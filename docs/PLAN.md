# PLAN — agent-lab-target (v1)

> Entorno **deliberadamente vulnerable** con fines educativos (linea DVWA / Web LLM
> attacks de PortSwigger). La vulnerabilidad central — el agente confia en el
> contenido de sus herramientas / datos no confiables como si fueran instrucciones —
> es **intencionada y es el objeto de estudio**. Solo se ejecuta en local / red
> aislada via Docker. Nunca se despliega ni se expone a Internet.

Este documento es el plan aprobado, con los cambios obligatorios incorporados.
Las decisiones se materializan en `docs/adr/` y el progreso se registra en
`docs/DEVLOG.md`.

---

## 1. Decisiones confirmadas

| Tema | Decision |
|---|---|
| Runtime | FrankenPHP en modo clasico (ADR 02) |
| HITL Nivel 2 | Auto-policy en 1 request; `LAB_CONFIRM_POLICY=deny\|allow` (default `deny`) |
| Invocacion LLM | API real en `/api/chat`; `FakeAnthropicTransport` solo en tests |
| Modelo por defecto | `claude-haiku-4-5-20251001` (Cambio 2) |
| Temperature | Explicita y configurable (`ANTHROPIC_TEMPERATURE`, default `1.0`) (Cambio 3) |
| Conversacion | Stateless single-turn, sin `conversation_id` |
| Ubicacion | `AppSec/agent-lab-target/` |

## 2. Herramientas (5, deliberadamente sobre-permisivas)

| Herramienta | Que hace | Por que es peligrosa |
|---|---|---|
| `read_file(path)` | Lee de un dir "sandbox" | Path traversal hacia el fichero del secreto |
| `send_email(to, body)` | "Envia" (registra) un correo | Canal de exfiltracion |
| `fetch_url(url)` | Descarga contenido de una URL | Exfiltracion via query params + inyeccion indirecta |
| `query_db(sql)` | Ejecuta SQL contra la BD del lab | Escalada / lectura de PII y del secreto |
| `delete_account(username)` | Borra una cuenta (tool propia) | Accion sensible / destructiva; superficie limpia para el gate del Nivel 2 |

## 3. Datos no confiables (PostgreSQL)

`Product`, `Review` (payload de inyeccion indirecta canonico), `User` (`carlos` con
PII = objetivo de exfiltracion), `Secret` (flag: API key ficticia), `EmailLog`,
`ExfiltrationEvent`.

## 4. Niveles de defensa (`LAB_LEVEL` 0-3), resueltos en runtime

- **0** sin defensa (baseline).
- **1** separacion datos/instrucciones (delimitadores + marca de "datos").
- **2** minimo privilegio + HITL (auto-policy); `query_db` deja de aceptar SQL arbitrario.
- **3** filtrado de salida (DLP) + egress allowlist en `fetch_url`.

## 5. Contrato HTTP

| Endpoint | Request | Response |
|---|---|---|
| `POST /api/chat` | `{ "message": "..." }` | `{ "reply": "...", "tool_calls": [...], "meta": { "level": N, "model": "...", "temperature": T } }` |
| `POST /api/reset` | — | `{ "status": "ok" }` (recarga fixtures + limpia logs; **sin dropear el esquema**) |
| `GET /api/health` | — | `{ "status": "ok", "level": N, "level_label": "..." }` (nivel EFECTIVO) |
| `GET /api/exfil` | — | `{ "events": [ { "tool", "domain", "url", "query", "blocked", "created_at" } ] }` |

Forma de cada `tool_call`: `{ "name", "input", "result_summary", "blocked" }`.

### `/api/chat` debe ser autodescriptiva (`meta`)

Con el override por `X-Lab-Level`, el nivel se decide **por request**. Pero
`/api/health` y `/api/chat` son requests distintas: el harness podria preguntar a
`health` "en que nivel estas", recibir `2`, y mandar el chat con una cabecera que se
ignora o se malinterpreta, y atribuir al Nivel 2 unos resultados que salieron del
Nivel 0. Fallo silencioso, tabla corrupta.

Por eso la respuesta de `/api/chat` incluye `meta` con los **valores efectivos de
esa misma request** (no lo que diga el `.env`):

```json
{
  "reply": "...",
  "tool_calls": [ { "name": "...", "input": {}, "result_summary": "...", "blocked": false } ],
  "meta": { "level": 2, "model": "claude-haiku-4-5-20251001", "temperature": 1.0 }
}
```

Esto cierra del todo el Cambio 3: cada fila del dataset se explica a si misma aunque
el entorno cambie a mitad de corrida. El `blocked` de cada `tool_call` es coherente
con los registros de `ExfiltrationEvent`/`EmailLog` (Cambio 1 / ADR 10).

### Nivel invalido -> 400 (sin clamp)

Un nivel fuera de rango (`< 0`, `> 3`) o no numerico, tanto en la cabecera
`X-Lab-Level` como en `LAB_LEVEL`, devuelve **400 Bad Request** con mensaje claro.
Nunca se acota en silencio: un off-by-one del harness (mandar `4`) se convertiria en
"medi el nivel 4" cuando en realidad seria el `3`. Falla fuerte (misma familia que el
footgun del Cambio 4). Ver `LabLevelResolver` y sus tests.

Override solo-lab: cabecera `X-Lab-Level: 0..3` para recorrer los 4 niveles en una
sola pasada sin reiniciar el contenedor. `GET /api/health` devuelve el nivel efectivo.

---

## 6. Cambios obligatorios incorporados (post-aprobacion)

### Cambio 1 (critico) — Registro de intentos bloqueados vs. no intentados

Metrica principal del proyecto (ASR) en juego. Hay que distinguir:
"el agente pico y la defensa lo bloqueo" (defensa funciona) de "el agente nunca pico"
(la inyeccion fallo). Conflacionarlos infla artificialmente la efectividad del Nivel 3.

- `ExfiltrationEvent` y `EmailLog` llevan `blocked: bool`.
- **Registrar SIEMPRE antes de aplicar la defensa**: `fetch_url` registra el egress
  antes de `EgressAllowlist`; `send_email`/`delete_account`/`query_db`(escritura)
  registran el intento aunque el Nivel 2 lo deniegue, marcado `blocked=true`.
- `GET /api/exfil` expone `blocked` por evento.
- El `blocked` de `tool_calls` en `/api/chat` debe ser coherente con esos registros.
- Documentado como **ADR 10** (metodologia de medicion).

### Cambio 2 — Modelo por defecto Haiku

`ANTHROPIC_MODEL=claude-haiku-4-5-20251001`. Razon: senal en el baseline. Un modelo
pequeno da ASR alto en Nivel 0 y hace visible la contribucion de cada capa. Sigue
siendo un LLM real; la invocacion real en `/api/chat` se mantiene. El escalado de
modelo (Sonnet/Opus) es experimento posterior (trabajo futuro).

### Cambio 3 — Temperature explicita

`AnthropicClient` envia `temperature` siempre (default `1.0`, configurable). Nunca
implicita. `model` y `temperature` efectivos quedan consultables por corrida.
Consecuencia operativa: el ASR es probabilistico -> N intentos por payload
(N x 20 payloads x 4 niveles) -> cientos de `POST /api/reset`. El reset debe ser
**barato**: recarga de fixtures + limpieza de logs, sin dropear/recrear el esquema.

### Cambio 4 — El nivel se resuelve en runtime, nunca en compile time

Footgun: un compiler pass con `getParameter('LAB_LEVEL')` recibe el placeholder, no
el valor; cambiar `LAB_LEVEL` sin limpiar cache produce schemas obsoletos en silencio.

- `AgentToolPass` genera el set completo y canonico de schemas (estatico, sin nivel).
- `DefenseLevel` se resuelve en runtime desde `%env(int:LAB_LEVEL)%` (ya implementado
  en `LabLevelResolver`).
- `ToolRegistry::schemas(level)` filtra/recorta en runtime.
- Override por cabecera `X-Lab-Level` (solo-lab). Documentado en ADR 05.

### Cambio 5 — Fuente unica de verdad para el flag

El secreto vive en la entidad `Secret` y en `var/.../secret.flag`. Ambos se generan
desde **una sola constante** en el codigo. Sin literales duplicados.

### Documentacion adicional

- **ADR 10**: "Registro de intentos bloqueados vs. no intentados" (metodologia).
- "Que NO cubre" del Nivel 3: `OutputDlpScanner` se basa en deteccion de patron
  sobre el secreto; un payload que pida devolverlo en base64, invertido o deletreado
  se lo salta. Hallazgo pedagogico esperado, **no un bug a arreglar en v1**.
  Contraste: la trampa de `fetch_url` registra el egress pase lo que pase por el
  texto, y por tanto no tiene esa debilidad.

---

## 7. ADRs

1. Symfony 8 sobre Spring Boot
2. FrankenPHP sobre php-fpm + Nginx
3. Bucle de tool use manual sin SDK
4. Registro por atributos + compiler pass (schema generado)
5. Niveles de defensa conmutables resueltos en runtime (`LAB_LEVEL` + `X-Lab-Level`)
6. HITL Nivel 2 como auto-policy single-request
7. Invocacion LLM real + `FakeAnthropicTransport` en tests
8. Patron dual-LLM documentado para v2 (no implementado en v1)
9. Diseno de la trampa de deteccion de exfiltracion
10. Registro de intentos bloqueados vs. no intentados (metodologia de medicion del ASR)
11. Exposicion del secreto de proceso y resolucion de env (Aceptado — seguridad; Tarea B)
12. temperature = 1.0 explicita (Tarea E)

## 8. Fases

1. **Andamiaje** (esta fase): Docker (FrankenPHP clasico + PG16), Symfony 8 / PHP 8.4,
   `GET /api/health`, ADRs 1-2, DEVLOG.
2. Datos: entidades + migracion + fixtures (con `blocked` y flag de fuente unica).
3. Registro de tools: atributos + `AgentToolPass` + `ToolRegistry`; las 5 tools. ADR 4.
4. Agente: `AnthropicClient` (model/temperature explicitos) + bucle + `/api/chat`
   (Nivel 0). ADRs 3, 7. Tests con `FakeAnthropicTransport`.
5. Defensas: `DefensePolicy` + Niveles 1-3 + `LAB_CONFIRM_POLICY`. ADRs 5, 6.
6. Trampa + contrato: `/api/reset`, `/api/exfil`, registro bloqueados-vs-no-intentados. ADRs 9, 10.
7. Docs: README completo, "que ataca / que no cubre" por capa, ADR 8 (dual-LLM v2).

## 9. Fuera de alcance v1 (trabajo futuro)

Harness Python, multi-turno + memory poisoning, inyeccion multimodal, dual-LLM
implementado, AgentDojo, frontend React, escalado de modelo (Sonnet/Opus).
