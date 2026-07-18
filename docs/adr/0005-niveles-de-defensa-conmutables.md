# ADR 0005 — Niveles de defensa conmutables (0-3)

Fecha: 2026-07-18
Estado: **Aceptado** (aprobado en revision e implementado en Fase 5)

> Esto NO toca la vulnerabilidad intencionada (que el agente confie en datos no
> confiables como si fueran instrucciones). Las capas se **anaden encima** y se
> **conmutan por nivel** para medir el delta de cada una; el Nivel 0 la deja intacta.

## Contexto

El lab mide el ASR (attack success rate) del harness contra el agente. Para atribuir
un descenso del ASR a una defensa concreta, hace falta:

1. Un **baseline** (Nivel 0) sin ninguna defensa: el denominador contra el que se
   compara. Si una capa superior se filtrara al Nivel 0, el baseline subiria y los
   deltas quedarian falseados.
2. Capas **aisladas y acumulativas**, seleccionables de una en una, para atribuir el
   efecto a UNA capa y no a una mezcla.
3. Resolucion del nivel en **runtime** (no en compile-time del contenedor), para que
   la misma imagen mida los cuatro niveles sin recompilar (ver Cambio 4 y ADR sobre
   resolucion de env; el override es la cabecera `X-Lab-Level`).

## Decision

Cuatro niveles (`App\Defense\DefenseLevel`), acumulativos:

| Nivel | Nombre | Capa que anade |
|---|---|---|
| 0 | `None` | ninguna (baseline pristino) |
| 1 | `DataSeparation` | el output de tool se envuelve como DATOS no confiables |
| 2 | `LeastPrivilege` | minimo privilegio + human-in-the-loop sobre acciones sensibles |
| 3 | `OutputFiltering` | DLP de salida + egress allowlist en `fetch_url` |

Decisiones estructurales:

- **Ganchos centralizados** en `App\Defense\DefensePolicy`: `wrapUntrusted` (N1),
  `gate` (N2/N3) y `filterOutput` (N3). En Nivel 0 **todos son la identidad**. Un
  unico sitio donde vive la logica de nivel, y un test (`BaselinePristineTest`, N1)
  que clava que el Nivel 0 no recibe ninguna capa.
- **El gate va ENTRE `persist` y `execute`** (Cambio 1 / Bloque J-N4). El
  `ToolInvocation` se registra ANTES de consultar el gate; asi una llamada bloqueada
  queda grabada (`blocked=true` + `blocked_reason`), **distinguible de "nunca
  intentada"**. Un test de orden (`AgentInvocationOrderTest`) fija la secuencia
  `['persist','gate','execute']`.
- **El recorte de schema es runtime** (`ToolRegistry::schemas(level)`): a partir del
  Nivel 2, `query_db` se **anuncia** como solo-lectura. Es cosmetico para el modelo;
  quien realmente lo impide es el `gate`. El SET de tools y sus `input_schema` no
  cambian por nivel (Cambio 4).
- **`blocked_reason`** es un codigo estable (`confirmation_denied`, `egress_allowlist`)
  que se proyecta a `tool_calls[].blocked_reason` para que el harness sepa QUE capa
  paro la accion (no solo que se bloqueo).

## Consecuencias

- El harness corre la misma imagen 4 veces cambiando `X-Lab-Level`; el delta de ASR
  entre niveles consecutivos es el efecto atribuible a esa capa.
- Anadir una capa nueva = un metodo en `DefensePolicy` con guarda `>= nivel` + su
  test aislado; el Nivel 0 sigue protegido por `BaselinePristineTest`.
- Las capas son de eficacia PARCIAL a proposito (ver `docs/DEFENSES.md`): el valor
  pedagogico esta en medir cuanto tapa cada una y por donde se escapa.

## Alternativas descartadas

- **Nivel en compile-time (parametro del contenedor).** Obligaria a una imagen por
  nivel y colisiona con la resolucion de env (el pass recibiria el placeholder, no el
  valor). Descartado por Cambio 4.
- **Gate ANTES del persist.** Una llamada bloqueada no se registraria y seria
  indistinguible de "el modelo nunca la intento" — rompe la metrica (Cambio 1).
- **Ganchos dispersos por cada tool.** Duplicaria la logica de nivel y arriesgaria
  que una capa se colara al Nivel 0. Centralizar en `DefensePolicy` lo evita.
