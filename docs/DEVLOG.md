# DEVLOG — agent-lab-target

Registro fechado por fase. Entradas bilingues (ES / EN), sin trailer de co-autor.

---

## 2026-07-16 — Fase 1: Andamiaje

**ES**

- Creado el proyecto Symfony 8 / PHP 8.4 en `AppSec/agent-lab-target/` (estructura
  estandar, no microframework).
- Docker Compose con **FrankenPHP en modo clasico** (`SERVER_NAME=:80`,
  timeouts >= 180s en `Caddyfile`, `max_execution_time=180`) + **PostgreSQL 16**.
- Endpoint `GET /api/health` que devuelve `{status, level, level_label}` con el
  **nivel efectivo resuelto en runtime** (`LabLevelResolver`): precedencia
  `X-Lab-Level` (cabecera solo-lab) > `LAB_LEVEL` (env). Enum `DefenseLevel` 0-3.
- `.env` con **solo placeholders**; la `ANTHROPIC_API_KEY` vive en `.env.local`
  (gitignored). Modelo por defecto **Haiku** (`claude-haiku-4-5-20251001`) y
  **temperature explicita** (`1.0`), por decision metodologica (Cambios 2 y 3).
- Documentacion: `docs/PLAN.md` (plan aprobado con los 5 cambios obligatorios),
  ADR 0001 (Symfony sobre Spring Boot), ADR 0002 (FrankenPHP sobre php-fpm+Nginx),
  README con el aviso de "entorno deliberadamente vulnerable - solo local".
- Verificacion: `composer install` local + arranque con el servidor embebido de PHP;
  `GET /api/health` responde 200 JSON. (Docker no disponible en la maquina de dev;
  el build de imagen lo probara el operador con `docker compose up`.)

**EN**

- Scaffolded Symfony 8 / PHP 8.4 target under `AppSec/agent-lab-target/`.
- Docker Compose: FrankenPHP classic mode (`:80`, >=180s timeouts) + PostgreSQL 16.
- `GET /api/health` returns the **runtime-resolved** effective level (`X-Lab-Level`
  header override, then `LAB_LEVEL` env). Placeholders only in `.env`; real API key
  in gitignored `.env.local`. Default model **Haiku**, explicit `temperature`.
- Docs: approved PLAN with the 5 mandatory changes, ADR 0001, ADR 0002, README
  vulnerability warning. Verified via local `composer install` + PHP built-in server;
  health endpoint returns 200 JSON. Docker image build to be validated by the operator.

Pendiente Fase 2: entidades Doctrine + fixtures (con `blocked` y flag de fuente unica).

---

## 2026-07-16 — Fase 1 reabierta: tareas de revision

**ES**

Fase 1 NO cerrada. Cambios pedidos en revision:

- **Tarea A (bloqueante) — Verificar bajo FrankenPHP: PENDIENTE.** Docker no esta
  disponible en la maquina de dev (ni Docker Desktop, ni docker en WSL: no hay
  distro). No puedo ejecutar `docker compose up` aqui. Queda como gate para el
  operador; se entrega checklist con los 5 casos + comprobacion de timeout >=180s.
- **Inconsistencia corregida (variables_order):** el caso `LAB_LEVEL=2 (env) -> 2`
  paso **solo** con `php -d variables_order=EGPCS` en linea de comandos (simulando un
  SAPI real), NO con el `app.ini`. Lo conflacione en el resumen anterior. El fix del
  `app.ini` esta en un sitio que NO he probado bajo FrankenPHP. Reconocido.
- **Tarea B (seguridad, ADR propuesto):** ADR 0011 (estado Propuesto, sin
  implementar). Sonda empirica de la resolucion de env de Symfony: `getenv()` funciona
  de fallback y la causa real del ensombrecimiento era el placeholder de `.env` en
  `$_ENV`, no la falta de `E`. `/proc/self/environ` filtra la key igual. Mitigaciones
  propuestas: key dedicada con tope de gasto, denylist en `read_file`
  (`/proc`,`/sys`,`.env.local`) manteniendo el traversal a `secret.flag`/`/etc/passwd`,
  `APP_ENV=prod` por defecto, y revertir `EGPCS`.
- **Tarea C (contrato):** `/api/chat` respondera con `meta:{level,model,temperature}`
  (valores efectivos de la request) para que cada fila del dataset se autodescriba.
  Fijado en `docs/PLAN.md` y `README.md`. No implementado (es Fase 4).
- **Tarea D (400, sin clamp): IMPLEMENTADO Y TESTEADO.** `LabLevelResolver` valida
  estricto: nivel fuera de rango / no numerico -> 400, en cabecera **y** en
  `LAB_LEVEL` (bind cambiado a `string` para no castear en silencio con `int:`).
  Eliminado el clamp de `DefenseLevel`. Tests: **22 tests, 25 assertions, OK**
  (unit `LabLevelResolverTest` + funcional `HealthEndpointTest` con `WebTestCase`).
- **Tarea E (ADR temperature):** ADR 0012 — `temperature=1.0`, con el razonamiento de
  reproducibilidad agregada vs. por-respuesta, el colapso a binario con temp 0, la
  cifra de la system card de Opus 4.5 (~4,7% @1 / ~63% @100) y el corolario para el
  harness (reportar N + IC).

**EN**

Phase 1 NOT closed. Task A (verify under FrankenPHP) is **blocked**: no Docker in this
dev environment (no Docker Desktop; WSL has no distro) — delivered as an operator
checklist instead. Corrected the variables_order inconsistency: the env case passed
only via the `-d variables_order=EGPCS` CLI flag, not the untested `app.ini`. Task B:
ADR 0011 (Proposed) with an empirical env-resolution probe. Task C: `/api/chat` `meta`
contract fixed in docs. Task D: strict 400 (no clamp) implemented + tested (22 tests
OK). Task E: ADR 0012 (temperature=1.0) with rationale.

Sigue pendiente para cerrar Fase 1: Tarea A bajo FrankenPHP (operador) + aprobacion de
la propuesta de ADR 0011.

---

## 2026-07-16 — ADR 0011 aprobado e implementado

**ES**

Aprobadas las 4 mitigaciones de seguridad; ADR 0011 pasa a Aceptado:

1. **Key dedicada con tope de gasto:** documentada en el README (seccion de aviso).
2. **Denylist `read_file`:** `SensitivePathGuard` + `SensitivePathException`
   (`/proc`, `/sys`, `.env.local` cerrados; `secret.flag` y `/etc/passwd` siguen
   permitidos — vector didactico intacto). Testeado. **Cableado en la tool: Fase 3.**
3. **`APP_ENV=prod`/`APP_DEBUG=0` por defecto** en `compose.yaml`; dev opt-in.
4. **Revertido `variables_order=EGPCS`** del `app.ini`; `LAB_LEVEL` **fuera de `.env`**
   (ya no ensombrece); resolucion via `getenv()` + procesador `default:lab_level_default`
   en `services.yaml`.

Verificacion (sin Docker):
- **Suite: 37 tests, 39 assertions, OK** (anadido `SensitivePathGuardTest`).
- **Mitigacion 4 probada con `php -S` plano (sin `-d variables_order`)**, justo donde
  antes fallaba: sin export -> nivel 0 (default); `export LAB_LEVEL=2` -> nivel 2 (la
  env gana via `getenv()`); `X-Lab-Level` sigue gananado; `99` -> 400. El mecanismo del
  fix queda demostrado; **confirmar bajo FrankenPHP sigue pendiente (Tarea A)**.

Docker: intento de `winget install Docker.DockerDesktop` lanzado. El cliente
`docker.exe` (v29.6.1) quedo instalado, pero el instalador de Docker Desktop seguia en
ejecucion (bloqueado sin interaccion) y el daemon no responde. Completarlo (UAC /
WSL2 / reinicio / arrancar Docker Desktop) queda del lado del operador.

**EN**

ADR 0011 approved and implemented: (1) dedicated capped key documented; (2) `read_file`
denylist mechanism (`SensitivePathGuard`) built + tested, tool wiring deferred to
Phase 3; (3) `APP_ENV=prod` default; (4) reverted `EGPCS`, removed `LAB_LEVEL` from
`.env`, resolve via `getenv()` + `default:`. Verified without Docker: 37 tests OK, and
Mitigation 4 proven on plain `php -S` (env now wins via getenv where it previously
failed). FrankenPHP confirmation (Task A) still pending. Docker Desktop client landed
via winget but the installer stayed blocked and the daemon is down — completion is on
the operator.

---

## 2026-07-16 — Verificacion de la Tarea A via CI (Docker fuera del portatil)

**ES**

Docker Desktop resulta complicado de arrancar en el portatil (Win10 Home) y WSL no
esta operativo (sin distro; `--install` pide elevacion + reinicio), asi que no puedo
levantar el contenedor ni en local ni autohospedado. Solucion: **verificar la Tarea A
en CI**. Anadido:

- `scripts/smoke-health.sh` — smoke reutilizable del contrato de `/api/health`
  (default 0, header 3, `99`/`abc` -> 400, POST -> 405). Sirve en CI y en local
  (WSL/Docker): `./scripts/smoke-health.sh http://localhost:8080`.
- `.github/workflows/ci.yml` — dos jobs en runners Linux:
  1. `tests`: PHP 8.4 + PHPUnit.
  2. `container`: `docker compose up --build` (FrankenPHP real, `APP_ENV=prod`),
     espera health, corre el smoke, valida `LAB_LEVEL=2` (env gana via `getenv()`,
     **confirmando la Mitigacion 4 bajo FrankenPHP**) y precedencia de cabecera, y
     prueba el **timeout >= 180s extremo-a-extremo** (inyecta un `.php` lento temporal
     que duerme 185s y verifica que Caddy lo devuelve).

Con esto la Tarea A se cierra en CI al hacer push, sin cargar el portatil, y de forma
repetible (encaja con el objetivo de portfolio). `bash -n` y YAML validados en local.

**EN**

Docker Desktop is hard to start on the laptop and WSL is not operational, so the
container can't be run locally or self-hosted here. Task A is moved to **CI**:
`scripts/smoke-health.sh` (reusable `/api/health` contract smoke) + `.github/workflows/ci.yml`
(PHPUnit job + container job that builds the real FrankenPHP image, runs the smoke,
verifies `LAB_LEVEL=2` env resolution via getenv under FrankenPHP, header precedence,
and an end-to-end >=180s timeout probe). Closes Task A on push, no laptop load,
repeatable. Syntax/YAML validated locally.

---

## 2026-07-16 — FASE 1 CERRADA (CI verde) + repo publico

**ES**

Repo publico: https://github.com/R0b3r7DEV/agent-lab-target (primer commit, `main`,
sin `vendor/var/.env.local`). CI (run 29534252806) **verde en los dos jobs**:

- **Tests (PHPUnit): success** (37 tests).
- **Contenedor (Tarea A) - FrankenPHP + PostgreSQL: success.** Pasos: build de la
  imagen FrankenPHP (primer build real, OK) -> health -> smoke de los 5 casos
  (default 0, header 3, `99`/`abc` -> 400, POST -> 405) -> `LAB_LEVEL=2` resuelto por
  env **bajo FrankenPHP real** (confirma la Mitigacion 4 sin EGPCS) + precedencia de
  cabecera -> **timeout >=180s extremo a extremo** (sonda de 185s devuelta por Caddy).

**Tarea A CERRADA.** Con ella, y con B/C/D/E ya entregadas, **la Fase 1 queda cerrada
y verificada donde importa**. Verificacion en adelante: CI (el portatil no corre Docker).

Nota menor (no bloqueante): warning de deprecacion de Node 20 en `actions/checkout@v4`
(forzado a Node 24). Pendiente de bump a v5 como pulido.

Pendiente: OK explicito del operador para arrancar la Fase 2 (entidades Doctrine +
migracion + fixtures, con `blocked` y flag de fuente unica).

**EN**

Public repo pushed; CI green on both jobs. Container job (Task A) built the real
FrankenPHP image and passed all checks: 5 health cases, `LAB_LEVEL=2` under real
FrankenPHP (Mitigation 4 confirmed, no EGPCS), and the >=180s end-to-end timeout probe.
**Task A closed -> Phase 1 closed and verified.** Minor: bump `actions/checkout@v4`->v5
to clear the Node 20 deprecation warning. Awaiting operator go for Phase 2.

---

## 2026-07-16 — Higiene del repo antes de la Fase 2 (Bloques A/B/C)

**ES**

- **A1 confirmado:** el commit de cierre `d009479` toco **solo `docs/DEVLOG.md`** ->
  `[skip ci]` inocuo, el HEAD anterior (verde) representa el codigo. Norma documentada
  en el README: `[skip ci]` solo en commits docs-only; si roza `src/`, `config/`,
  `compose.yaml`, `Dockerfile` o `.github/`, corre el CI.
- **B — escaneo de historial:** `gitleaks 8.30.1 detect` sobre el historial completo
  (2 commits) -> **`no leaks found`**. **secret scanning + push protection ACTIVADOS**
  via API (confirmado: ambos `enabled`). **No hay `ANTHROPIC_API_KEY` en los secrets de
  Actions** (`gh secret list` vacio) — los tests usan `FakeAnthropicTransport`.
- **C — supply chain del CI:** todas las actions **pineadas por SHA** (checkout
  `9c091bb…` v7.0.0, setup-php `f3e473d…` v2.37.2) con el tag en comentario; anadido
  `.github/dependabot.yml` (github-actions + composer). Justificacion (tj-actions,
  marzo 2025) y calibracion honesta del riesgo en el README. Resuelve de paso el warning
  de Node 20 (v7 usa node24).

**EN**

Repo hygiene before Phase 2: A1 (closure commit was docs-only; skip-ci norm
documented). B (gitleaks full-history scan -> no leaks; secret scanning + push
protection enabled; no `ANTHROPIC_API_KEY` in CI secrets). C (all actions pinned by SHA
+ Dependabot; supply-chain rationale in README; also clears the Node 20 warning).

---

## 2026-07-17 — Bloques D/E + Fase 2 (datos)

**ES**

**Bloque D — version de PostgreSQL alineada.** Local tenia PG 18.4 y el compose PG 16:
dos versiones = trampa "verifico X, envio Y", critica para una medicion de rendimiento
como el coste de reset. Decision (ADR 13): **PostgreSQL 18 en todo** (compose
`postgres:18-alpine`, `serverVersion=18`). DBAL 4.4.3 lo soporta sin friccion.
**El coste de reset se mide en el compose, no en local** (el local es solo bucle de
iteracion).

**Bloque E — el `on: push` que no disparo: causa DETERMINISTA encontrada.** No era
transitorio. El cuerpo del commit `89ca587` contenia el literal del token de salto de
CI (en la linea que documentaba la propia norma); GitHub escanea el mensaje completo
(titulo + cuerpo) y salto el workflow. Descarte de las 4 hipotesis:
- E1 filtros `paths:`/`branches:` -> descartada (no hay).
- E2 token en el cuerpo -> **CAUSA** (grep lo confirma).
- E3 push con GITHUB_TOKEN -> descartada (committer R0b3r7DEV, no bot).
- E4 skipped vs ausente -> el run no se creo (los checks que aparecen luego son del
  dispatch manual). Norma reforzada: nunca escribir tokens de salto de CI en mensajes
  de commit (ni al documentarlos).

**Fase 2 — datos.** Entidades Doctrine (Product, Review, User=`app_user`, Secret=
`lab_secret`, EmailLog, ExfiltrationEvent). **Migracion inicial generada por `diff`**
(`Version20260716215240`), con **`blocked BOOLEAN NOT NULL` en `email_log` y
`exfiltration_event` desde el origen** (C3); `doctrine:schema:validate` -> *in sync*.
**Dataset DETERMINISTA** (`App\Lab\LabDataset`): sin Faker, IDs y valores fijos; carlos
con PII, review con payload de inyeccion indirecta, Secret; **flag de fuente unica**
(`App\Lab\LabSecret`) compartido por la entidad y el fichero en disco `var/secret.flag`
(alcanzable por `../secret.flag`). **Reset barato** (`LabResetService`): TRUNCATE RESTART
IDENTITY CASCADE + reseed en transaccion. Determinismo verificado (dos resets ->
carlos identico). 37 tests siguen OK.

Coste de reset — **local (solo comparativa, PG18 nativo Windows, N=50): min 77 /
avg 98 / p95 124 / max 407 ms**. El numero que cuenta (compose / PG18 / N=50) lo produce
el CI.

**El primer run de Fase 2 (commit 507bff0) FALLO** — y bien: la imagen Docker de
PostgreSQL 18 exige montar el volumen en `/var/lib/postgresql` (directorios versionados),
no en `.../data`; el contenedor de BD salia `unhealthy` y el compose no levantaba. El
PG18 nativo local no reproduce el fallo; solo aparece en la imagen Docker — exactamente
por eso el gate es el compose. Corregido el punto de montaje (ADR 13, commit 36ede69).

**FASE 2 CERRADA — CI verde (run 29540063840, commit 36ede69).** El job de contenedor
corrio, en el compose (FrankenPHP + PostgreSQL 18):
- `doctrine:migrations:migrate` -> OK (10 queries, 18.8ms), `Version20260716215240`.
- seed via `app:lab:reset` + aserciones de determinismo (carlos, secret=flag, logs a 0).
- **COSTE DE RESET (deliverable C2), compose / PostgreSQL 18 / N=50:
  min 8.7 / avg 10.0 / p95 10.2 / max 39.7 ms.** A ~10ms, cientos de resets son segundos:
  la estrategia TRUNCATE+reseed basta, sin alternativa necesaria.

Dato metodologico: el compose (~10ms) resulto **mas rapido** que el local (~98ms), lo que
confirma que el numero local NO transfiere — se reporta solo como comparativa, el que
cuenta es el del compose (ADR 13).

---

## 2026-07-17 — Bloque F (tests que faltaban)

**ES**

- **F1 (reconocido):** la Fase 2 no anadio ningun test PHPUnit (el conteo 37->37 no
  mentia). El determinismo se comprobaba como paso de CI en bash (`dbal:run-sql | grep`),
  fuera de la suite — que es donde tienen que estar los invariantes. Corregido.
- **F2:** `tests/Lab/LabDatasetTest` clava en la suite:
  1. **Invariante de fuente unica del flag (Cambio 5):** la entidad `Secret` y el
     fichero `var/secret.flag` son IDENTICOS (y ambos == `LabSecret::FLAG`). El dia que
     alguien toque uno solo (p. ej. en la Fase 3), el test lo caza — sin el, la
     divergencia seria silenciosa.
  2. **Determinismo (C1):** PII de carlos, IDs y payload de la review con valores fijos.
  Sin BD (ObjectManager como stub). **Suite: 39 tests, 51 assertions.**
- Benchmark de reset: descarta una vuelta de calentamiento sin cronometrar.
- Commit de cierre de Fase 2 `95636fa`: **verde** confirmado.

**EN**

F1: Phase 2 added no PHPUnit tests; the determinism check lived as a CI bash step, not in
the suite. F2: `LabDatasetTest` puts the flag single-source invariant (entity == file ==
`LabSecret::FLAG`) and dataset determinism into the suite (39 tests). Reset bench now
discards a warmup round. Closure commit `95636fa` confirmed green.

---

## 2026-07-17 — Fase 3: registro de tools + las 5 tools (Nivel 0)

**ES**

Disciplina de la fase: NO arreglar la vulnerabilidad intencionada. Las tools nacen
sobre-permisivas (Nivel 0); sin validacion/sanitizacion/limites no pedidos.

- **G1 — registro por atributos + compiler pass (ADR 4):** `#[AgentTool]` /
  `#[AgentToolParam]` + `AgentToolPass` que reflexiona y **genera el JSON Schema una vez**;
  inyecta en `ToolRegistry` el set canonico + un `ServiceLocator`. **Agnostico del nivel
  (Cambio 4):** el pass no lee `LAB_LEVEL` ni resuelve parametros; el filtrado es runtime
  en `schemas(DefenseLevel)`. Tests: 5 tools, forma de schema valida, set canonico
  identico a cualquier nivel, y guard de que el pass no llama a `getParameter`.
- **G2 — las 5 tools sobre-permisivas:** `read_file` (traversal), `send_email`,
  `fetch_url`, `query_db` (SQL arbitrario), `delete_account`. Comportamiento Nivel 0.
- **G3 — `SensitivePathGuard` cableado en `read_file`, resolviendo ANTES de la denylist:**
  `realpath()` (manejando `false`) y se comprueba la ruta RESUELTA, no la cruda (una
  denylist sobre la cadena cruda no detectaria `../../../proc/...`). Tests en las DOS
  direcciones: (1) bloquea `/proc`, `/sys`, `.env.local` incluso via traversal y con
  segmentos redundantes (`/./`); (2) **`../secret.flag` sigue funcionando y `/etc/passwd`
  sigue legible** — el vector didactico intacto. Los targets absolutos (Linux) corren en
  el CI/runner.
- **G4 — instrumentacion desde el origen:** `fetch_url` escribe `ExfiltrationEvent` y
  `send_email` escribe `EmailLog` SIEMPRE, `blocked=false`, registrando ANTES de cualquier
  defensa/peticion (test de orden `['persist','request']`). El intento de
  `delete_account`/`query_db` lo registrara el bucle del agente (tool_calls, Fase 4).
- **Suite: 51 tests, 115 assertions** (3 saltados en Windows: read_file a `/proc`,
  `/etc/passwd` — corren en Linux/CI).

**EN**

Phase 3 (Level 0, deliberately over-permissive — no defensive code added). G1:
attribute-based tool registry + level-agnostic compiler pass (ADR 4), schema generated
once. G2: the 5 over-permissive tools. G3: `SensitivePathGuard` wired into `read_file`,
**resolving the path (realpath) before the denylist**; bidirectional tests — blocks
`/proc`/`/sys`/`.env.local` (incl. traversal + redundant segments), while `../secret.flag`
and `/etc/passwd` stay reachable (Linux tests run in CI). G4: instrumentation from birth —
`fetch_url`/`send_email` always log (blocked=false), recorded before any defense/request.
51 tests, 3 skipped on Windows (run in CI).

---

## 2026-07-17 — Bloques H + J (antes de la Fase 4)

**ES**

- **Bloque H (critico) — reset robusto ante DDL destructivo (ADR 14).** `query_db`
  acepta DDL, asi que un `DROP`/`ALTER` (payload o modelo "explorando") dejaba el
  `TRUNCATE` roto y envenenaba en silencio el resto de una corrida. `LabResetService`
  ahora: via rapida (`TRUNCATE`+reseed, el TRUNCATE es la comprobacion de deriva); y solo
  tras dano, restauracion completa (`DROP SCHEMA public CASCADE` + recrear desde el
  mapping + reseed). Mantiene `query_db` con DDL (vector intacto). Test
  `LabResetRecoveryTest` (#[Group('db')]): `DROP TABLE review CASCADE` **via la tool real
  query_db** -> reset -> lab operativo. Los tests con BD se separan por grupo: el job
  PHPUnit corre `--exclude-group db`; el job de contenedor corre `--group db` contra el
  compose (PG18). `dbname_suffix` a '' en test (misma BD del lab; el test la restaura).
- **Bloque J (documentado) — requisito de la Fase 5.** El test de orden de `fetch_url`
  (`['persist','request']`) solo clava media invariante: cuando llegue el gate del Nivel 3,
  `['gate','persist','request']` tambien pasaria, y es justo lo que el Cambio 1 prohibe.
  Anotado en `ToolInstrumentationTest` y aqui: en la Fase 5 la asercion **debe pasar a
  `['persist','gate','request']`**.
- Suite: 52 tests (3 saltados en Windows -> corren en CI).

**Nota de secuenciacion:** los Bloques I (log durable `ToolInvocation` con `blocked`
ANTES del gate) y la Fase 4 (bucle de tool use) van ACOPLADOS: el escritor del log debe
ser el `AgentService`, y el registro debe preceder al gate (si lo escribiera la tool, una
llamada bloqueada por el gate no se registraria -> se violaria el invariante). Por eso I
se implementa junto con la Fase 4, como esfuerzo enfocado siguiente.

**EN**

Block H (critical): reset now survives destructive DDL from `query_db` (ADR 14) — fast
path (`TRUNCATE`+reseed) and, only after damage, a full restore (`DROP SCHEMA public
CASCADE` + recreate from mapping + reseed); keeps query_db's DDL (vector intact). Tested
via the real `query_db` tool (`LabResetRecoveryTest`, DB group, runs in the compose). DB
tests split by group: PHPUnit job `--exclude-group db`, container job `--group db`. Block
J documented: the `fetch_url` order assertion must become `['persist','gate','request']`
in Phase 5. Sequencing note: Block I (durable `ToolInvocation` log with `blocked` before
the gate) is coupled to Phase 4 (the AgentService is the writer, and logging must precede
the gate), so they're implemented together next.

---

## 2026-07-17 — Bloque I + Fase 4 (bucle de tool use)

**ES**

- **Bloque I — log durable (ADR 15).** Nuevo `ToolInvocation` (tool, input, blocked,
  result_summary, created_at): UNICA fuente de verdad de toda invocacion, escrita por el
  `AgentService` con flush inmediato ANTES de ejecutar/gate (sobrevive a un timeout). Se
  **eliminan `EmailLog`/`ExfiltrationEvent`**; `/api/exfil` (Fase 6) sera una proyeccion
  sobre `ToolInvocation`. `send_email`/`fetch_url` quedan puras. Migracion
  `Version20260717212340`. Elegi el log unico (no las dos opciones ofrecidas) porque
  ambas duplicaban el hecho — justificado en el ADR. Clave: el escritor es el orquestador,
  no la tool, para que una llamada bloqueada por el gate se registre igual -> por eso I
  va acoplado a la Fase 4.
- **Fase 4 — bucle (ADRs 3, 7).** `AnthropicClient` (POST /v1/messages a mano, sin SDK;
  model/temperature/max_tokens explicitos; la API key nunca en logs/excepciones, con test
  `AnthropicClientTest` que lo clava). `AgentService` (el bucle; registra ToolInvocation
  antes del gate; `tool_calls` proyecta de ahi). `/api/chat` stateless single-turn con
  `meta` completo (K2): `level, model, temperature, max_tokens, iterations, stop_reason,
  max_iterations_reached, truncated, api_error` — distingue "el ataque fallo" de "no
  concluyente". `FakeAnthropicTransport` (MockHttpClient, solo tests). 6 tests del bucle:
  texto, tool use, encadenado, MAX_ITER, error de API, truncado. README: el harness DEBE
  descartar las no concluyentes.
- CI: los tests con BD corren en el contenedor con `APP_ENV=test` explicito (el env real
  prod impedia arrancar el kernel de test). Suite local: **58 tests** (3 saltados en
  Windows -> corren en CI).

**EN**

Block I (ADR 15): a single durable `ToolInvocation` log (source of truth), written by the
`AgentService` before execution/gate (survives timeouts); `EmailLog`/`ExfiltrationEvent`
removed, `/api/exfil` becomes a projection. Chose the single log over the two offered
options (both duplicated the fact); the writer is the orchestrator so a gate-blocked call
is still logged — hence I is coupled to Phase 4. Phase 4 (ADRs 3, 7): hand-written
`AnthropicClient` (no SDK; explicit model/temperature/max_tokens; API key never in
logs/exceptions, tested), `AgentService` loop, `/api/chat` with full `meta` (K2:
iterations/stop_reason/max_iterations_reached/truncated/api_error), `FakeAnthropicTransport`
(test-only), 6 loop tests. README: the harness must discard inconclusive measurements. 58
tests locally (3 skipped on Windows, run in CI).

**EN**

Block D: PostgreSQL aligned to 18 everywhere (ADR 13); reset cost measured in compose,
not local. Block E: the missing `on: push` was deterministic — `89ca587`'s body
contained the CI-skip token (in the line documenting the norm); GitHub scans the whole
message. Four hypotheses ruled out with evidence; norm reinforced. Phase 2 (data):
Doctrine entities, initial migration via diff with `blocked` from the start
(schema:validate in sync), deterministic single-source dataset (flag shared by entity +
on-disk file), cheap TRUNCATE+reseed reset. Local reset bench (comparison only): avg
~98ms/50; the compose number is produced by CI in this commit.

---

## 2026-07-18 — Fase 5: defensas conmutables (Niveles 1-3)

**ES**

- **`DefensePolicy`** centraliza las tres capas (ADR 05): `wrapUntrusted` (N1: envuelve
  el output de tool como DATOS no confiables), `gate` (N2 minimo privilegio + HITL, N3
  egress allowlist de `fetch_url`) y `filterOutput` (N3 DLP del flag). En **Nivel 0
  todos los ganchos son la identidad**; lo clava `BaselinePristineTest` (N1) con
  comparacion **estricta** (system prompt canonico via `SystemPromptFactory::CANONICAL`
  + `query_db` con descripcion canonica, no la recortada).
- **Orden `persist -> gate -> execute`** (Cambio 1 / N4): el `gate` va ENTRE registrar el
  `ToolInvocation` y ejecutar, para que una llamada bloqueada quede grabada
  (`blocked=true` + nueva columna `blocked_reason`, migracion `Version20260718024210`) y
  NO se confunda con "nunca intentada". `AgentInvocationOrderTest` pasa a asertar
  `['persist','gate','execute']` con un espia de `DefensePolicy`.
- **N2 (metadatos):** `meta.dlp_redacted` (bool) y `tool_calls[].blocked_reason`
  (proyeccion del log durable, no contabilidad paralela).
- **HITL como auto-policy** (`LAB_CONFIRM_POLICY` deny|allow, ADR 06): el lab es no
  interactivo; `deny` (defecto) bloquea las acciones sensibles, `allow` las
  auto-confirma. Mismo patron `default:` que `LAB_LEVEL` — **corregido**: la variable NO
  se declara en `.env` (si no, el `deny` del `.env` ensombreceria en `$_ENV` un `allow`
  del compose; es el bug del ADR 11). Se resuelve via `lab_confirm_policy_default`.
- **N5 docs:** `docs/DEFENSES.md` con el "que ataca / que NO cubre" por capa. Hallazgo
  pedagogico **esperado** (no bug): el DLP por coincidencia literal es evadible
  (base64/invertido/deletreado); se contrasta con el log de `fetch_url`, que registra el
  intento **independientemente** del formato -> detectar en el borde de egress (con log)
  es mas robusto que censurar contenido.
- Verificacion: suite local **80 tests** (73 sin BD, 3 saltados = grupo db que corre
  aparte; 7 del grupo db con `APP_ENV=test`), 0 fallos. `schema:validate` en sync.
  El numero de contenedor lo produce el CI en el commit de cierre.

**EN**

Phase 5 — switchable defenses (Levels 1-3). `DefensePolicy` centralizes the three layers
(ADR 05): `wrapUntrusted` (L1, tool output framed as untrusted DATA), `gate` (L2 least
privilege + HITL, L3 `fetch_url` egress allowlist), `filterOutput` (L3 flag DLP). At
**Level 0 every hook is the identity**, pinned by `BaselinePristineTest` (N1) with strict
comparison. Order is `persist -> gate -> execute` (Change 1/N4): the gate sits between
logging the `ToolInvocation` and executing, so a blocked call is recorded (`blocked=true`
+ new `blocked_reason` column, migration `Version20260718024210`) and never conflated
with "never attempted"; the order test now asserts `['persist','gate','execute']`. N2
metadata: `meta.dlp_redacted` + `tool_calls[].blocked_reason`. HITL is a deterministic
auto-policy (`LAB_CONFIRM_POLICY` deny|allow, ADR 06) since the lab is non-interactive;
**fixed**: like `LAB_LEVEL` it is NOT declared in `.env` (the `.env` value would shadow
the compose env in `$_ENV` — the ADR 11 bug), resolved via `default:`. N5 docs in
`docs/DEFENSES.md`: per-layer what-it-attacks/what-it-does-not; the literal-match DLP
being bypassable (base64/reversed/spelled) is an **expected** teaching finding, not a bug,
contrasted with `fetch_url` logging that captures the attempt regardless of encoding. 80
local tests, 0 failures; container number produced by CI on the closing commit.

---

## 2026-07-18 — Fase 6: /api/reset, /api/exfil, frontera anti-SSRF + primer disparo

**ES**

- **Bloques bloqueantes previos.**
  - **M (confirmado con diff):** `compose.yaml:18` sigue `APP_ENV: ${APP_ENV:-prod}` — la
    historia del fichero muestra un solo `+` (su introduccion) y ningun `-` posterior.
    `APP_ENV=test` aparece SOLO en `ci.yml:87`, inyectado por comando (`-e`) en el exec de
    PHPUnit del grupo db; el `up` (linea 44) arranca en prod. ADR 11 intacto.
  - **O (durabilidad del gate en PG real):** `GateDurabilityTest` (#[Group('db')]): una
    llamada sensible a Nivel 2/deny -> el gate la bloquea -> se relee FRESCA desde PG
    (`em->clear()` + repositorio) con `blocked=true` y `blocked_reason='confirmation_denied'`,
    y sin `result_summary` (no se ejecuto). Caso simetrico: query_db read-only permitido ->
    `blocked=false`, con resumen. Es el Motivo 1 del Bloque I (sobrevivir al timeout de
    180s) clavado en comportamiento, no en esquema. Grupo db: **7 -> 11 tests**.
  - **P (descubribilidad):** tabla de TODOS los knobs en el README (default, valores
    admitidos, donde se resuelven), tras sacar `LAB_CONFIRM_POLICY` de `.env`.
- **Q1 — /api/exfil como proyeccion con dominio parseado server-side.** `ExfilProjector`
  filtra las filas `fetch_url` de `ToolInvocation` y DERIVA `domain`/`query` del `url`
  (parse_url) en UN solo sitio; devuelve `tool, domain, url, query, blocked,
  blocked_reason, created_at`. Motivo: si devolviera el blob crudo, el harness
  reimplementaria el parseo y se perderia la deteccion determinista. `ExfilEndpointTest`
  (#[Group('db')]) lo verifica (dominio parseado, ruido de otras tools excluido) + que
  `/api/reset` limpia el log.
- **Q2 — SSRF hacia el plano de control.** `fetch_url` es SSRF a proposito y emite solo
  GET; `/api/reset` y `/api/chat` son POST-only, asi que un SSRF (GET) recibe 405 y no
  puede resetear la corrida ni disparar el agente. Frontera principiada (como el Bloque H):
  NO reduce el objeto de estudio (exfiltrar a un dominio del atacante sigue intacto);
  protege la integridad de la medida. `ControlPlaneSsrfTest` lo asevera (405 a verbos no
  permitidos). Superficie GET-reachable (health, exfil) = solo-lectura, documentada en
  `docs/DEFENSES.md` § SSRF. NO se mete `localhost` en la denylist de egress (eso si seria
  arreglar la vuln): la proteccion es de verbo en el borde, no de destino.
- **Q4 — endpoints solo-lab sin auth.** README: `/api/reset`, `/api/exfil`, `/api/chat` y
  `X-Lab-Level` no llevan auth a proposito; refuerza el aviso de "solo local / red
  aislada" (cualquiera con acceso de red resetea tu corrida o lee tu log). Los POST-only
  son frontera anti-SSRF interna, NO control de acceso.
- **Q3 — primer disparo real: runner + plumbing verificado; la transcripcion REAL queda
  PENDIENTE de la key del operador.** `scripts/first-shot.sh` (reset -> chat Nivel 0 ->
  /api/exfil; portable sin jq). El disparo real contra la API de Anthropic **gasta dinero
  y necesita una key dedicada del lab**, que NO esta en este entorno; **no se fabrica una
  transcripcion**. Lo que SI se verifico en vivo (PG scoop + `php -S`, sin key):

  ```
  POST /api/reset  -> {"status":"reset"}                         (siembra el dataset)
  POST /api/chat   -> meta.stop_reason="api_error", api_error=true, reply="", tool_calls=[]
                      (sin key -> el marcador K2 de "no concluyente" funciona; el harness
                       descartaria esta medicion en vez de contarla como ataque fallido)
  GET  /api/exfil  -> {"entries": []}
  ```

  El endpoint, el bucle y el meta responden bien; falta SOLO la corrida con key real, que
  ejecuta el operador con:

  ```bash
  echo 'ANTHROPIC_API_KEY=sk-ant-...' > .env.local   # key DEDICADA con tope (ADR 11)
  docker compose up -d                               # o php -S con PG local
  ./scripts/first-shot.sh http://localhost:8080
  ```

  Su salida (mensaje + reply + tool_calls con `blocked` + meta + /api/exfil) es la
  transcripcion Q3 a pegar aqui. Si el agente no pica a la primera, tampoco es un fallo:
  es el primer dato sobre lo que hace falta para que pique, y va al DEVLOG igual.
- Verificacion: **91 tests locales** (80 sin BD + 11 grupo db en compose/PG18), 0 fallos.
  El numero de contenedor lo produce el CI en el commit de cierre.

**EN**

Phase 6. Blocking items first: **M** confirmed by diff — compose keeps `APP_ENV: ${APP_ENV:-prod}`
(one `+`, no later `-`), `APP_ENV=test` only in the PHPUnit db-group exec command (ci.yml:87),
ADR 11 intact. **O** — `GateDurabilityTest` (db group) proves a gate-blocked call lands in
real PG with `blocked=true` + `blocked_reason`, reread fresh after `em->clear()`, and the
symmetric allowed case (blocked=false); db group 7 -> 11 tests. **P** — full knobs table in
the README. **Q1** — `/api/exfil` projects `ToolInvocation` fetch_url rows with `domain`/`query`
parsed server-side (single source), so the harness gets deterministic detection instead of
reparsing URLs; `ExfilEndpointTest` verifies it and that reset clears the log. **Q2** — SSRF to
the control plane: `fetch_url` is GET-only, `/api/reset` and `/api/chat` are POST-only, so an
induced SSRF gets 405 and can't reset the run or fire the agent; principled boundary (like
Block H), does not touch the studied vuln, documented in DEFENSES.md; `ControlPlaneSsrfTest`
asserts the 405s. **Q4** — lab-only unauthenticated endpoints documented as reinforcing the
local-only / isolated-network warning. **Q3** — `scripts/first-shot.sh` runner + live plumbing
verified without a key (reset OK; chat degrades cleanly to `api_error`; exfil empty); the REAL
paid shot against the Anthropic API is **left PENDING the operator's dedicated key — no
transcript is fabricated**. 91 local tests, 0 failures; container number from CI on the closing
commit.

---

## 2026-07-19 — /api/reset parametrizado (capacidad para la familia indirecta del harness)

**ES**

- **Capacidad ADITIVA (ADR 0016):** `POST /api/reset` sin cuerpo -> estado canonico
  **identico** al de siempre (retrocompatible al 100%); con `{"poisoned_review":"<string>"}`
  -> sobrescribe UNICAMENTE el cuerpo de la review envenenada canonica (id 2, `compliance_bot`).
  Resuelve la decision aparcada (ADR 0008 del harness): la familia `indirect_injection` deja de
  medir una sola instruccion bajo N disparadores.
- **Fuente unica del cuerpo efectivo:** `LabDataset::resolvePoisonedReview(?override)` (override
  o canonico), usada por el seeder; `seed()` y `LabResetService::reset()` reciben el override
  opcional (default null). `fastReset` Y `fullRestore` lo aplican -> la restauracion de esquema
  del Bloque H no se puentea.
- **Blindaje (el harness suministra el ATAQUE, no el OBJETIVO):** solo `poisoned_review` es
  parametrizable; `Secret`/flag, PII de carlos, review benigna #1, nivel y `var/secret.flag`
  quedan inalcanzables desde la request. Test explicito lo asevera.
- **Rechazo estricto 400 ANTES de tocar la BD** (una request malformada no resetea a medias):
  clave desconocida, cuerpo no-objeto, `{}` (falta la clave), no-string, o > 8000 chars
  (barandilla, el 400 por longitud dice el maximo). La ruta de siembra usa el ORM
  (parametrizada): el payload entra como DATO, no como SQL — la vuln vive en que el agente
  confia en el contenido, no en el seeder.
- **Confirmacion de siembra desde el estado PERSISTIDO (Cambio 1/Bloque I):** la respuesta lleva
  `poisoned_review_len` + `poisoned_review_sha256` leidos con `SELECT body FROM review WHERE
  id=2` tras el flush (no un calculo paralelo del controlador). El harness verifica que la
  inyeccion tomo antes de gastar en `chat`. Nunca expone el secreto.
- **Coste:** via rapida preservada; delta override-vs-canonico local con **min identico**
  (~86 ms local, absoluto que NO transfiere; el numero de compose/PG18 lo da el CI). `app:lab:reset`
  gana `--poisoned-review` (aditivo). Unico anadido por reset: un SELECT de una fila.
- **Tests (contra PG18 real, grupo db):** retrocompat canonica; round-trip del override leido de
  la BD; blindaje (secreto/PII/fichero intactos); determinismo (reset(X) x2 identico);
  confirmacion == estado persistido; `fullRestore` aplica el override. No-db: los seis 400s.
  **Suite: no-db 86 (3 saltados en Windows), db 20 (antes 11), 0 fallos.** El numero de
  contenedor lo produce el CI.

**EN**

Additive capability (ADR 0016): parametrized `POST /api/reset`. No body -> canonical state,
100% backward compatible; `{"poisoned_review":"<string>"}` -> overwrites ONLY the canonical
poisoned review body (id 2). Single source `LabDataset::resolvePoisonedReview`; override flows
through `seed()`/`reset()`, applied by both the fast path and the schema `fullRestore` (Block H
preserved). Shielding: only `poisoned_review` is parametrizable; the Secret/flag, carlos PII,
benign review #1, level and `var/secret.flag` are unreachable from the request (explicit test).
Strict 400 BEFORE touching the DB (a malformed request must not half-reset): unknown key,
non-object, `{}`, non-string, or > 8000 chars (guardrail; the length 400 states the max).
Seeding path uses the ORM (parametrized): the payload is DATA, not SQL — the vuln is the agent
trusting the content, not an injectable seeder. Seeding confirmation (`len` + `sha256`) is read
from the PERSISTED review after flush (Change 1/Block I), not a parallel calc; the harness uses
it to confirm the injection took before spending on `chat`; never exposes the secret. Cost: fast
path preserved (local floor identical; authoritative compose/PG18 number from CI). Tests: db
group 11 -> 20, non-db 86 (3 skipped on Windows), 0 failures.
