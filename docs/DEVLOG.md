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
