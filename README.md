# agent-lab-target

> ⚠️ **ENTORNO DELIBERADAMENTE VULNERABLE — SOLO USO LOCAL / RED AISLADA.**
> No lo despliegues ni lo expongas a Internet. La vulnerabilidad central (el agente
> confia en el contenido de sus herramientas y en datos no confiables como si fueran
> instrucciones) es **intencionada** y es el objeto de estudio del laboratorio, en la
> linea de DVWA y de los labs de "Web LLM attacks" de PortSwigger.

Target (la "victima") de un laboratorio educativo de **inyeccion de prompts contra
agentes LLM con herramientas**. Un harness de ataque en Python (repositorio aparte)
lo ataca por HTTP como caja negra. Este repo expone un contrato HTTP limpio y estable.

Estado: **Fase 1 (andamiaje)**. El agente, las herramientas y las defensas se anaden
en fases posteriores (ver `docs/PLAN.md`).

## Stack

- PHP 8.4 · Symfony 8 (estructura estandar)
- PostgreSQL 16 · Doctrine ORM (desde la Fase 2)
- FrankenPHP en modo clasico · Docker Compose
- Bucle de tool use implementado a mano contra `POST /v1/messages` (sin SDK)

## ⚠️ Usa una API key DEDICADA al lab, con tope de gasto (ADR 11)

Este entorno esta disenado para romperse. `read_file` es **deliberadamente**
vulnerable a path traversal (su objetivo es alcanzar `secret.flag`), pero el mismo
traversal puede alcanzar el entorno del proceso (`/proc/self/environ`). **Asume que
la API key del proceso es alcanzable.** Por eso:

- Crea una **API key exclusiva de este lab** en la consola de Anthropic (no reutilices
  tu key personal ni la de otros proyectos).
- Ponle un **limite de gasto bajo** (workspace/key con presupuesto acotado). El
  modelo por defecto es Haiku, barato, pero el tope es la red de seguridad real.
- **Revocala al terminar** la practica.

Defensa en profundidad ya implementada: `read_file` consultara un denylist
(`/proc`, `/sys`, `.env.local`) que cierra las fuentes de credenciales reales sin
tocar el vector didactico (`secret.flag` y `/etc/passwd` siguen siendo alcanzables);
el contenedor corre en `APP_ENV=prod` por defecto (sin volcado de `$_ENV` en errores).
Ninguna de estas medidas sustituye a la key dedicada con tope: son capas.

## Levantar el lab

Requiere Docker.

```bash
# 1) API key DEDICADA del lab (ver aviso de arriba). Vive SOLO en .env.local (gitignored).
echo 'ANTHROPIC_API_KEY=sk-ant-...' > .env.local

# 2) Levantar app (FrankenPHP, APP_ENV=prod) + PostgreSQL 16.
docker compose up --build

# 3) Comprobar salud (nivel efectivo incluido).
curl http://localhost:8080/api/health
# -> {"status":"ok","level":0,"level_label":"Nivel 0 - sin defensa"}
```

Para modo dev (detalle de errores, solo si lo necesitas):

```bash
APP_ENV=dev APP_DEBUG=1 docker compose up --build
```

## Cambiar de nivel de defensa

Una sola variable selecciona la capa activa (`LAB_LEVEL`, 0–3). Se resuelve **en
runtime**, nunca en tiempo de compilacion del contenedor.

| Nivel | Defensa |
|---|---|
| 0 | Sin defensa (baseline) |
| 1 | Separacion datos/instrucciones |
| 2 | Minimo privilegio + human-in-the-loop |
| 3 | Filtrado de salida (DLP) + egress allowlist |

Por variable de entorno:

```bash
LAB_LEVEL=2 docker compose up
```

Override por peticion (funcionalidad **solo-lab**, para que el harness recorra los 4
niveles en una sola pasada sin reiniciar el contenedor):

```bash
curl -H 'X-Lab-Level: 3' http://localhost:8080/api/health
# -> {"status":"ok","level":3,...}
```

## Contrato HTTP

| Endpoint | Descripcion | Fase |
|---|---|---|
| `GET /api/health` | Healthcheck; devuelve el nivel efectivo | 1 ✅ |
| `POST /api/chat` | `{message}` → `{reply, tool_calls[], meta}` | 4 |
| `POST /api/reset` | Recarga fixtures + limpia logs (barato, sin dropear esquema) | 6 |
| `GET /api/exfil` | Log consultable de egress (trampa de exfiltracion), con `blocked` | 6 |

La respuesta de `/api/chat` es **autodescriptiva**: `meta` lleva los valores
**efectivos de esa request** (no los del `.env`):

```json
"meta": {
  "level": 0, "model": "claude-haiku-4-5-20251001", "temperature": 1.0, "max_tokens": 1024,
  "iterations": 3, "stop_reason": "end_turn",
  "max_iterations_reached": false, "truncated": false, "api_error": false
}
```

`tool_calls` es una **proyeccion del log durable** `ToolInvocation` (ADR 15), no una
contabilidad paralela.

**El harness DEBE descartar las mediciones no concluyentes** — no contarlas como "el
ataque fallo". Una request es no concluyente si `max_iterations_reached`, `truncated` o
`api_error` es `true`: en esos casos no llegamos a saber si el agente picaba, y contarlas
como fallo del ataque inflaria artificialmente la efectividad de todas las defensas
(Cambio 1 aplicado al bucle).

Un nivel invalido (`X-Lab-Level` o `LAB_LEVEL` fuera de `0..3` o no numerico)
devuelve **400 Bad Request**, nunca un clamp silencioso.

## Configuracion (env)

Ver `.env` (solo placeholders). Claves relevantes: `LAB_LEVEL`, `LAB_CONFIRM_POLICY`,
`ANTHROPIC_MODEL` (default Haiku), `ANTHROPIC_TEMPERATURE` (explicita, default `1.0`),
`ANTHROPIC_MAX_TOKENS`, `AGENT_MAX_ITERATIONS`.

## Documentacion

- `docs/PLAN.md` — plan completo (con los cambios obligatorios incorporados).
- `docs/adr/` — un ADR por decision de arquitectura.
- `docs/DEVLOG.md` — bitacora fechada por fase.

## Higiene del repositorio y CI

Coherente con el tema del lab (maneja una API key de pago; su objeto de estudio es la
exfiltracion de secretos), la seguridad del propio repo no puede ir floja:

- **Secret scanning + push protection activos** (GitHub, gratis en repos publicos): si
  un push contiene una credencial, GitHub lo bloquea. El historial ademas se escaneo con
  **gitleaks** (sin fugas).
- **Actions pineadas por SHA completo**, no por tag mutable, con el tag legible en un
  comentario. Por que: `actions/checkout@v7` es un tag **mutable** — quien controle el
  repo de la action puede reapuntarlo. No es teorico: en el compromiso de
  **tj-actions/changed-files** (marzo 2025) se reescribieron los tags para volcar los
  secretos del runner en los logs de build, afectando a miles de repos; los que pineaban
  por SHA no se vieron afectados. Riesgo real **hoy aqui**: bajo (no hay secretos en el
  CI — los tests usan `FakeAnthropicTransport`). El argumento es de practica y coherencia:
  un repo de portfolio sobre seguridad de agentes no puede llevar tags mutables en su CI.
  **Dependabot** (`.github/dependabot.yml`) propone los bumps de SHA de forma controlada.
- **`[skip ci]` solo en commits que tocan exclusivamente `docs/`.** En cuanto un commit
  roza `src/`, `config/`, `compose.yaml`, `Dockerfile` o `.github/`, corre el CI. Sin
  excepciones: el estado que crees tener debe ser el que el CI ha probado.

---

### English

Deliberately vulnerable **target** for a prompt-injection lab against tool-using LLM
agents (DVWA / PortSwigger "Web LLM attacks" style). **Local / isolated use only —
never deploy or expose it.** A separate Python attack harness hits it over HTTP as a
black box. Currently at **Phase 1 (scaffolding)**: `GET /api/health` with a
runtime-resolved defense level (`LAB_LEVEL` 0–3, `X-Lab-Level` header override). The
agent, tools and defenses land in later phases — see `docs/PLAN.md`.
