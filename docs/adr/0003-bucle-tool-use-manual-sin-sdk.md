# ADR 0003 — Bucle de tool use a mano, sin SDK de Anthropic

Fecha: 2026-07-17
Estado: Aceptado

## Contexto

El agente necesita el bucle de tool use contra la API de Messages de Anthropic. Se
podia usar el SDK oficial (o el tool runner) o implementarlo a mano sobre
`HttpClientInterface`.

## Decision

**Bucle manual, sin SDK**, sobre `HttpClientInterface` (`AnthropicClient` +
`AgentService`):

1. Se envia el mensaje del usuario + un system prompt con reglas + la lista de `tools`.
2. Si `stop_reason == "tool_use"`: se ejecuta cada tool, se devuelve un `tool_result`, y
   se repite.
3. Limite de iteraciones configurable (`AGENT_MAX_ITERATIONS`, default 8) para evitar
   bucles infinitos.

Escribir el bucle a mano es un **requisito, no una limitacion**: da control total de
que entra en el contexto de cada llamada. Eso hara falta para las capas de defensa
(sobre todo el patron dual-LLM previsto para la v2, donde un modelo en cuarentena
procesa el contenido no confiable y otro privilegiado orquesta).

`model`, `temperature` y `max_tokens` se envian EXPLICITAMENTE (ADR 12) y son
consultables (los expone `meta` en /api/chat). La `ANTHROPIC_API_KEY` vive solo en el
backend, va en la cabecera `x-api-key`, y nunca aparece en logs ni excepciones (test
en `AnthropicClientTest`).

## Consecuencias

- Control total del contexto por llamada; base lista para el dual-LLM (v2).
- Hay que mantener a mano el marshalling del contrato (`tool_use`/`tool_result`), pero
  el contrato es estable y esta cubierto por tests con `FakeAnthropicTransport` (ADR 7).

## Alternativas descartadas

- **SDK oficial / tool runner**: mas comodo, pero oculta el marshalling y da menos
  control sobre el contexto de cada llamada — justo lo que el lab necesita para las
  defensas y el dual-LLM. Descartado.

## English summary

The tool-use loop is implemented **by hand** over `HttpClientInterface` (no SDK):
`AnthropicClient` does `POST /v1/messages`; `AgentService` runs the loop
(tool_use → execute → tool_result → repeat, capped by `AGENT_MAX_ITERATIONS`). Writing
it by hand is a requirement, not a limitation: it gives full control over what enters
each call's context, needed for the defense layers and the v2 dual-LLM pattern.
`model`/`temperature`/`max_tokens` are sent explicitly and exposed in `meta`; the API
key stays backend-only, in the `x-api-key` header, never in logs/exceptions (tested).
