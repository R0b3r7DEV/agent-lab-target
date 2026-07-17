# ADR 0015 — Log durable de invocaciones (ToolInvocation); tool_calls como proyeccion

Fecha: 2026-07-17
Estado: Aceptado

## Contexto (Bloque I)

`delete_account` y `query_db` no tenian log durable, solo aparecerian en el `tool_calls`
de la respuesta. Dos problemas:

1. **El timeout.** El bucle de tool use puede tardar (por eso el timeout es >=180s). Si
   un request revienta a mitad, el `tool_calls` **nunca llega** y se pierde el registro de
   que se invoco. Un `delete_account` ejecutado dentro de un request que expiro es
   exactamente el caso que hay que poder auditar despues. `ToolInvocation` sobrevive
   porque esta en BD.
2. **Dos fuentes de verdad.** Si `tool_calls` lleva su contabilidad y la BD lleva otra,
   divergen en silencio. Es el Cambio 5 con otro traje.

Invariante no negociable: **toda invocacion de tool se registra de forma DURABLE, con
`blocked`, ANTES del gate. `tool_calls` es una PROYECCION de ese registro, no una
contabilidad paralela.**

## Decision

Un **unico log durable generico**: `ToolInvocation` (`tool`, `input` JSON, `blocked`,
`result_summary`, `created_at`), escrito por el `AgentService` (el orquestador) por cada
tool_use, con `persist`+`flush` inmediato, ANTES de ejecutar/gate.

- `tool_calls` en /api/chat se construye proyectando esas filas — misma fuente.
- **Se eliminan `EmailLog` y `ExfiltrationEvent`** como entidades. `/api/exfil` (Fase 6)
  pasa a ser una **proyeccion** sobre `ToolInvocation` filtrada a las tools de
  exfiltracion (fetch_url/send_email), derivando dominio/query del `input` guardado.

### Por que no una de las dos opciones ofrecidas literalmente

Se ofrecian "generico + detalle por FK" o "generico + mantener EmailLog/ExfiltrationEvent
como canales especializados". Ambas retienen entidades especializadas que **duplican el
hecho** "se invoco la tool X con blocked=Y" que el log generico ya posee. La opcion mas
limpia respecto al invariante "sin dos fuentes de verdad" es un **unico log**, con
`/api/exfil` como vista sobre el. Trade-off asumido: `/api/exfil` parsea el `input` en
lectura en vez de tener columnas pre-extraidas — despreciable para un lab.

### Por que el escritor es el AgentService y no la tool (clave)

El registro debe preceder al GATE. Si lo escribiera la tool, una llamada que el gate del
Nivel 2 **bloquee** (y por tanto no ejecute la tool) **no se registraria** -> "bloqueado"
indistinguible de "no intentado" -> se viola el invariante. Por eso el escritor es el
orquestador, y por eso el Bloque I va ACOPLADO a la Fase 4.

## Consecuencias

- Una sola tabla para el hecho "tool invocada con blocked"; sin divergencia; auditable
  tras un timeout.
- **Bloque J (Fase 5):** cuando se inserte el gate, va ENTRE el `persist` del
  `ToolInvocation` y la ejecucion, marcando `blocked`. La asercion de orden de fetch_url
  debe pasar a `['persist','gate','request']` (documentado en su test).
- Las tools `send_email`/`fetch_url` quedan PURAS (ya no escriben en BD).

## English summary

A single durable generic log, `ToolInvocation` (tool, input, blocked, result_summary,
created_at), written by the `AgentService` on every tool_use with an immediate flush,
**before execution/gate**. `tool_calls` projects from it; `EmailLog`/`ExfiltrationEvent`
are removed and `/api/exfil` becomes a projection over `ToolInvocation`. Rationale for
the durable log: it survives request timeouts (a `delete_account` in an expired request
must be auditable) and it removes the two-sources-of-truth divergence (Cambio 5). The
writer must be the orchestrator (not the tool) so that a gate-blocked call is still
recorded — which is why Block I is coupled to Phase 4. Phase 5 will insert the gate
between the persist and the execution (order assertion becomes `['persist','gate','request']`).
