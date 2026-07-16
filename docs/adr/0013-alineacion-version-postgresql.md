# ADR 0013 — Alineacion de la version de PostgreSQL (local / compose / CI)

Fecha: 2026-07-16
Estado: Aceptado

## Contexto

La maquina de desarrollo tiene **PostgreSQL 18.4** nativo; el `compose` (y por tanto
el CI) traia **PostgreSQL 16**. Dos versiones distintas conviviendo es la misma trampa
que `php -S` vs. FrankenPHP con otro disfraz: **verificar contra un runtime y enviar
otro**. El entregable del lab es el `compose`.

Donde el problema deja de ser teorico: el **coste de `/api/reset`** es una medicion de
**rendimiento**, lo mas sensible al entorno que existe. PG 18 nativo en Windows y PG 16
sobre un volumen de Docker no comparten version, ni planificador, ni ruta de I/O. Un
numero medido en local no transfiere al contenedor, y ese numero condiciona el diseno
del harness (N x payloads x niveles -> cientos de resets).

## Decision

**PostgreSQL 18 en todas partes** (opcion (a)): local, `compose` y CI. Una sola version
en todo el proyecto.

- `compose.yaml`: `postgres:18-alpine`; `DATABASE_URL` con `serverVersion=18`.
- Doctrine DBAL 4 usa una unica `PostgreSQLPlatform` (no hay clases por version por
  encima de un umbral); `serverVersion` es metadato para evitar el query de version en
  arranque. PG 18 no introduce friccion de plataforma. **Se verifica en CI** (el
  `compose` con `postgres:18-alpine` corre `doctrine:migrations:migrate` + fixtures): si
  el job pasa en verde, DBAL+18 es limpio de verdad, no por suposicion.

### Reglas de verificacion (cierre de la trampa)

- **El PostgreSQL local queda permitido como BUCLE DE ITERACION rapida, NO como gate de
  aceptacion.** El gate es el `compose`.
- **El coste de `/api/reset` se mide en el `compose`. Obligatorio.** Un numero medido en
  local no vale como deliverable. Si se reporta el local, es solo comparativa, y el que
  cuenta es el del contenedor, anotado con version de PG, entorno y numero de
  iteraciones de la medicion.

## Consecuencias

- **Gotcha de la imagen Docker de PG18 (cazado por el CI, no por el local).** La imagen
  `postgres:18` cambio a directorios de datos versionados: el volumen debe montarse en
  `/var/lib/postgresql` (NO en `/var/lib/postgresql/data`, como en PG16), o el contenedor
  sale `unhealthy` y el compose no levanta (docker-library/postgres#37, PR #1259). El
  PG18 **nativo** (scoop) no tiene este problema; solo aparece en la imagen Docker — que
  es lo que se envia. Es la validacion literal de la regla "el gate es el compose, no el
  local": el primer run de Fase 2 fallo aqui y se corrigio el punto de montaje.
- Positivas: un unico Postgres en local/compose/CI; el numero de reset es representativo
  del entorno que se envia; se elimina la divergencia de versiones.
- Negativas / asumidas: PG 18 es reciente (~sept 2025); si apareciera friccion con DBAL
  se veria en el job de CI (fail rapido) y se caeria a PG 16 (opcion (b)). En el momento
  de esta decision el servidor PG local no estaba arrancado, asi que la verificacion se
  hace en el `compose`/CI — que es justamente el gate.

## Alternativas descartadas

- **(b) PostgreSQL 16 en todas partes + usar el `compose` tambien en local**: valido,
  pero obligaria a degradar respecto al PG 18 ya instalado en local y a depender de
  Docker para el bucle de iteracion. (a) unifica sin degradar.
- **Dejar dos versiones (16 compose / 18 local)**: inaceptable — invalida cualquier
  medicion de rendimiento y reintroduce la trampa "verifico X, envio Y".

## English summary

Local dev has **PostgreSQL 18.4**; the compose/CI shipped **PG 16** — two versions is the
"verify one runtime, ship another" trap again, and it bites hardest on the
**`/api/reset` cost**, a performance measurement (scheduler + I/O path differ across
versions/hosts). Decision: **PostgreSQL 18 everywhere** (local/compose/CI). DBAL 4 uses a
single `PostgreSQLPlatform`, so PG 18 is frictionless; **verified in CI** (compose with
`postgres:18-alpine` runs migrate + fixtures). Rules: local PG is an **iteration loop
only, never the acceptance gate**; the **reset cost is measured in the compose**
(mandatory), annotated with PG version, environment, and iteration count. Fallback to PG
16 if CI shows DBAL friction.
