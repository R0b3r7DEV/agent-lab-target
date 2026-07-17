# ADR 0007 — Invocacion LLM real en /api/chat; FakeAnthropicTransport solo en tests

Fecha: 2026-07-17
Estado: Aceptado

## Contexto

Para que la inyeccion de prompts sea genuina, el agente debe hablar con un LLM real: un
mock no puede demostrar el ataque. Pero los tests del bucle deben ser deterministas y
no gastar tokens ni depender de la red.

## Decision

- **`/api/chat` usa la API real** (`AnthropicClient` sobre `HttpClientInterface`), con el
  modelo Haiku por defecto (ADR: modelo pequeno para ASR alto en el baseline).
- **`FakeAnthropicTransport` vive solo en tests**: un `MockHttpClient` que devuelve
  cuerpos canonicos de la API de Messages en orden, sin tocar la red. Opera a nivel de
  **transporte**, asi que ejercita el marshalling real del `AnthropicClient` (headers,
  cuerpo JSON), no una interfaz mockeada que lo saltaria.

Los tests cubren: una vuelta de solo texto, tool use de una vuelta, encadenado de varias,
agotamiento de `MAX_ITER`, error de API y truncado.

## Consecuencias

- El ataque real ocurre contra un LLM real; los tests son deterministas y offline.
- El `FakeAnthropicTransport` no refleja ataques nuevos (es un guion fijo); su papel es
  probar la MECANICA del bucle, no la efectividad del ataque (eso lo mide el harness
  contra la API real).

## Alternativas descartadas

- **Fake a nivel de interfaz (mockear `AnthropicClient`)**: saltaria el marshalling HTTP
  real, que es justo lo que queremos ejercitar. Descartado a favor del fake de transporte.
- **Modo record/replay (cassettes)**: util para CI reproducible, pero mas codigo en v1 y
  las respuestas grabadas no reflejan ataques nuevos. Trabajo futuro.

## English summary

`/api/chat` calls the **real** Messages API (genuine injection needs a real LLM).
`FakeAnthropicTransport` is a test-only `MockHttpClient` returning canned Messages-API
bodies in order — at the **transport** level, so it exercises `AnthropicClient`'s real
HTTP marshalling rather than a mocked interface. Loop tests cover single text, single
tool use, chained tool use, `MAX_ITER` exhaustion, API error, and truncation. Cassettes
(record/replay) are future work.
