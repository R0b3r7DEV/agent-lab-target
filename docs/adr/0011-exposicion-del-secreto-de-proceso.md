# ADR 0011 — Exposicion del secreto de proceso y resolucion de env

Fecha: 2026-07-16
Estado: **Aceptado** (aprobado en revision e implementado; ver "Estado de implementacion")

## Estado de implementacion (2026-07-16)

- Mitigacion 1 (key dedicada con tope): **documentada** en el README (proceso).
- Mitigacion 2 (denylist): **mecanismo implementado y testeado** en
  `SensitivePathGuard` (`/proc`, `/sys`, `.env.local`), manteniendo `secret.flag` y
  `/etc/passwd`. **Cableado en `read_file` pendiente de Fase 3** (cuando exista la tool).
- Mitigacion 3 (`APP_ENV=prod`/`APP_DEBUG=0` por defecto): **implementada** en `compose.yaml` (dev opt-in).
- Mitigacion 4 (revertir `EGPCS` + `getenv()`+`default:`): **implementada**
  (`app.ini` sin `variables_order`, `LAB_LEVEL` fuera de `.env`, `services.yaml` con
  `default:lab_level_default`). Verificada localmente con `php -S` sin flags;
  **pendiente confirmar bajo FrankenPHP** (Tarea A, requiere Docker).

> Esta es una decision de **seguridad real**, no de la vulnerabilidad intencionada
> del laboratorio. Siguiendo la regla "si dudas de si algo es vuln del lab o bug
> real, pregunta": esto es **bug real**. La vulnerabilidad intencionada es que el
> agente confie en datos no confiables; NO lo es filtrar la API key **real** de
> Anthropic (con facturacion real) en vez del `sk-LAB-FAKE-...` que es el flag.

## Contexto

Para que la env del `docker compose` (p. ej. `LAB_LEVEL`) gane sobre el placeholder
de `.env`, en la Fase 1 se anadio `variables_order = "EGPCS"` al `app.ini`. La `E`
puebla `$_ENV` con **todas** las variables de entorno del proceso, incluida
`ANTHROPIC_API_KEY`.

Dos consecuencias, ambas fugas reales del secreto de produccion:

1. **Volcado en dev/profiler.** La pagina de excepcion de Symfony en dev y el
   profiler muestran `$_ENV`/`$_SERVER`. Una excepcion no capturada durante una
   corrida de la suite puede pintar la key en pantalla.
2. **La grave, llega en la Fase 3.** `read_file` es deliberadamente vulnerable a
   path traversal — ese es su proposito: alcanzar `secret.flag`. Pero el mismo
   traversal alcanza `/proc/self/environ`, que contiene el entorno del proceso.
   Un payload podria exfiltrar la API key **real**, con coste real, en vez del flag
   ficticio. Eso no es el vector didactico: es una fuga de verdad y **contamina el
   hallazgo** (el harness detectaria "secreto exfiltrado", pero seria el equivocado).

### Evidencia empirica (sonda de resolucion de env de Symfony)

`%env(int:X)%` resuelto con `ContainerBuilder::compile(true)`:

| Donde esta la variable | Resultado |
|---|---|
| Solo `getenv()` (putenv) | valor correcto -> Symfony **si** usa `getenv()` de fallback |
| Solo `$_SERVER` | valor correcto |
| `$_ENV=0` (placeholder .env) + `getenv()=2` (compose) | **0** -> `$_ENV` **ensombrece** el env real |
| Ausente en todos | excepcion (sin default) |

Conclusion clave: el problema **no** es que faltara la `E`. Es que el placeholder de
`.env` aterriza en `$_ENV` (Dotenv lo puebla) y gana sobre la env real de `getenv()`.
Ademas, `/proc/self/environ` filtra el entorno del proceso **con o sin** `E` en PHP
(es la vista del kernel). Por tanto `variables_order` es un factor menor; las
mitigaciones que cargan el peso son otras.

## Decision propuesta (defensa en profundidad)

1. **API key dedicada al lab, con limite de gasto bajo.** Mitigacion pragmatica y
   correcta: en un entorno disenado para romperse, **asume que el secreto de proceso
   es alcanzable**. Usar una key de Anthropic exclusiva del lab, con presupuesto
   acotado, y documentar el procedimiento en el `README` (crear key aparte, fijar
   limite, revocar tras la practica). No compartir la key personal.

2. **Denylist en `read_file`** para fuentes de credenciales — defensa en profundidad:
   `/proc`, `/sys`, `.env.local` y los ficheros de entorno de la app.
   **Restriccion dura:** el traversal hacia `secret.flag` y hacia targets clasicos
   (`/etc/passwd`) **debe seguir funcionando** — el vector didactico se mantiene
   intacto. Solo se cierra lo que expone credenciales reales. Esto **no** es un
   arreglo de la vulnerabilidad intencionada; es cerrar una fuga colateral distinta.

3. **`APP_ENV=prod` / `APP_DEBUG=0` por defecto en el `compose`; `dev` como opt-in
   explicito.** Elimina el volcado de `$_ENV`/`$_SERVER` del profiler y de la pagina
   de excepcion (consecuencia 1). El modo dev queda disponible pero hay que pedirlo.

4. **`variables_order`: la `E` no es necesaria.** Segun la sonda, `getenv()` de
   fallback basta para que la env del compose gane, *siempre que el `.env` no
   ensombrezca*. Propuesta:
   - Quitar `LAB_LEVEL` (y las operacionales overridables en runtime) del `.env`,
     resolviendo con el procesador `default:` (p. ej. `%env(default:lab_level_default:LAB_LEVEL)%`
     con un parametro `lab_level_default=0`).
   - **Revertir** `variables_order = "EGPCS"` del `app.ini` (dejar el default de PHP).
   Asi la env del compose es autoritativa via `getenv()` sin poblar `$_ENV`. Aun asi,
   dado que `/proc/self/environ` filtra igual, esta medida es higiene, no la barrera
   principal: las barreras son (1), (2) y (3). Pendiente de **verificar bajo
   FrankenPHP** que `getenv()` expone la env del contenedor en modo clasico.

## Consecuencias

- Positivas: la fuga del secreto real deja de contaminar la metrica; el flag
  ficticio sigue siendo el unico "secreto exfiltrable" por diseno; el vector
  didactico (traversal a `secret.flag`/`/etc/passwd`) intacto.
- Negativas / asumidas: `prod` por defecto pierde la comodidad del profiler en dev
  (se recupera con opt-in). La denylist anade una pequena logica en `read_file` que
  hay que documentar como "cierre de fuga colateral", no como mitigacion del vector.

## Alternativas descartadas

- **No hacer nada / confiar en que nadie apunte a `/proc/self/environ`**: inaceptable;
  el harness esta disenado precisamente para encontrar traversals.
- **Cifrar/ocultar la key en la app**: no ayuda; el traversal lee el entorno del
  proceso del kernel, no un fichero de la app.
- **Mantener `EGPCS` y confiar en `prod`**: `prod` tapa el volcado del profiler, pero
  no la fuga por `/proc`; y `EGPCS` no aporta nada a la resolucion (la sonda lo
  demuestra), asi que se revierte.

## English summary

**Real security bug (not the intended lab vuln):** `variables_order=EGPCS` plus the
real `ANTHROPIC_API_KEY` as a process env var means the key can leak via the dev
profiler (`$_ENV` dump) and, worse, via `read_file` path traversal to
`/proc/self/environ` — exfiltrating the **real** billed key instead of the intended
`sk-LAB-FAKE-...` flag, which also corrupts the metric. A probe shows Symfony's env
resolution falls back to `getenv()`, and the real cause of the earlier shadowing was
the `.env` placeholder landing in `$_ENV`, not a missing `E`. Proposed, layered
(not yet implemented): (1) dedicated low-cap lab API key, assuming the process secret
is reachable, documented in README; (2) `read_file` denylist for `/proc`, `/sys`,
`.env.local` while keeping traversal to `secret.flag` and `/etc/passwd` working;
(3) `APP_ENV=prod`/`APP_DEBUG=0` by default, dev opt-in; (4) drop `LAB_LEVEL` from
`.env` and resolve via `getenv()` + `default:` processor, reverting `EGPCS` — to be
verified under FrankenPHP. Awaiting approval.
