# ADR 0016 — /api/reset parametrizado (inyeccion del cuerpo de la review)

Fecha: 2026-07-19
Estado: **Aceptado** (aprobado en revision e implementado)

## Contexto

El harness mide el ASR por familia de ataque. La familia `indirect_injection` es la clase
estrella (inyeccion via contenido no confiable que el agente lee), pero hoy el target siembra
**una unica review envenenada FIJA**: el harness solo puede variar el mensaje del usuario
(el disparador), no el contenido inyectado. Asi, `indirect_injection` mide una sola
instruccion bajo N disparadores, no una diversidad real de ataques indirectos (ver ADR 0008
del harness).

## Decision

`POST /api/reset` pasa a ser **parametrizable, de forma puramente ADITIVA**:

- **Sin cuerpo** (o cuerpo vacio/whitespace) -> comportamiento **exactamente el actual**:
  reseed canonico (review #2 = `INJECTION_REVIEW_BODY`). Retrocompatible al 100%.
- **Con `{"poisoned_review": "<string>"}`** -> reseed determinista que **sobrescribe UNICAMENTE
  el cuerpo de la review envenenada canonica**. Todo lo demas, intacto.

### Allowlist: el harness suministra el ATAQUE, nunca el OBJETIVO

- **Parametrizable:** solo `poisoned_review` (contenido no confiable que lee el agente).
- **BLINDADO, inalcanzable desde la request:** el `Secret`/flag (fuente unica, Cambio 5), el
  PII de `carlos`, la review benigna #1, el nivel de defensa, `var/secret.flag`. Si el harness
  pudiera cambiar el secreto o el PII, el **ground truth del scorer se romperia**.
- **Rechazo estricto (400) ANTES de tocar la BD** (una request malformada no resetea a
  medias): clave desconocida, cuerpo no-objeto, falta `poisoned_review`, tipo no-string, o
  longitud > `MAX_POISONED_REVIEW_LEN` (8000). El `{}` explicito -> 400 (falta la clave; es el
  bug tipico de un runner que serializa un cuerpo nulo a `{}`, debe fallar fuerte).

### La ruta de siembra es SEGURA (bug real vs. vuln intencionada)

El cuerpo inyectado ES un payload de prompt-injection (hostil **para el agente**): eso es
intencionado. Pero al insertarlo en PostgreSQL se usa el **ORM (consulta parametrizada)**: el
string entra como DATO, no como SQL. La vulnerabilidad del lab es que el *agente* confia en ese
contenido, **no** que el seeder sea inyectable. No se introduce SQLi en el seeder. (La SQLi
intencionada sigue siendo `query_db`, sin recortar.) El tope de 8000 es una **barandilla**
contra input absurdo que dispare coste/contexto, no un objetivo: una inyeccion real son
decenas de caracteres.

### Confirmacion de siembra desde el estado PERSISTIDO (Cambio 1 / Bloque I)

La respuesta incluye `poisoned_review_len` + `poisoned_review_sha256` **leidos de la review ya
persistida** (`SELECT body FROM review WHERE id = 2` tras el flush), NO de un calculo paralelo
en el controlador. Si entre `resolvePoisonedReview` y el persist se colara una transformacion
(callback de Doctrine, un trim futuro), la confirmacion reflejaria la **verdad de tierra** —lo
que el agente leera de verdad—, no la intencion. El harness la usa para verificar que la
inyeccion tomo **antes de gastar en el `chat`** (encaja con su D2/D3). Nunca expone el secreto
(es hash del contenido del atacante).

## Consecuencias

### Se revisan dos decisiones previas

1. **Fixtures deterministas (C1):** el determinismo pasa de "fixtures FIJAS" a "**estado =
   funcion determinista de la entrada del reset**". `reset(body=X)` dos veces -> estado
   identico; `reset()` sin cuerpo -> estado canonico identico al de siempre. La
   reproducibilidad de una corrida se mantiene porque cada payload declara su cuerpo (lado
   harness).
2. **Caja negra del harness (ADR 0004 del harness):** que el harness controle el **contenido
   no confiable** es FIEL al modelo de amenaza —en el vector indirecto real, el atacante ES
   quien escribe la review— no god-mode (cf. como AgentDojo parametriza sus inyecciones). El
   blindaje del objetivo (secreto/PII/nivel) preserva la separacion victima/atacante.

### Rendimiento y recuperacion, intactos

- **Via rapida** (`TRUNCATE`+reseed) preservada; el override solo cambia el string sembrado.
  Coste local (delta override-vs-canonico): **min identico** (~86 ms local, absoluto que NO
  transfiere; el numero autoritativo lo da el CI en compose/PG18). El unico anadido por reset
  es un `SELECT` de una fila para la confirmacion.
- **Restauracion de esquema (Bloque H, ADR 14)** preservada: tras DDL destructivo, `fullRestore`
  recupera **y** aplica el override.

## Alternativas descartadas

- **(a) Sembrar 2-3 reviews fijas mas:** menos flexible; el harness no controla el ataque, solo
  elige entre un menu fijo.
- **Parametrizar tambien el objetivo (secreto/PII):** romperia el ground truth del scorer.
  Descartado; el objetivo queda blindado.
- **Confirmacion calculada en el controlador:** dos fuentes que "deberian" coincidir; se rechaza
  por el mismo motivo que el log durable unico (Bloque I).
