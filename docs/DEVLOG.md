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

**EN**

Block D: PostgreSQL aligned to 18 everywhere (ADR 13); reset cost measured in compose,
not local. Block E: the missing `on: push` was deterministic — `89ca587`'s body
contained the CI-skip token (in the line documenting the norm); GitHub scans the whole
message. Four hypotheses ruled out with evidence; norm reinforced. Phase 2 (data):
Doctrine entities, initial migration via diff with `blocked` from the start
(schema:validate in sync), deterministic single-source dataset (flag shared by entity +
on-disk file), cheap TRUNCATE+reseed reset. Local reset bench (comparison only): avg
~98ms/50; the compose number is produced by CI in this commit.
