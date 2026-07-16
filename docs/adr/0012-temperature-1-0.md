# ADR 0012 — temperature = 1.0 (explicita) en las llamadas al LLM

Fecha: 2026-07-16
Estado: Aceptado

## Contexto

`AnthropicClient` envia `temperature` **siempre y de forma explicita** (default `1.0`,
configurable por `ANTHROPIC_TEMPERATURE`), nunca implicita: si dependiera del default
de la API, un cambio de esta rompe la comparabilidad entre corridas. `model` y
`temperature` efectivos quedan consultables por corrida (en `meta` de `/api/chat`).

La pregunta obvia en una revision es: *"si buscas reproducibilidad, ¿no deberias usar
`temperature = 0`?"*. Este ADR la responde.

## Decision

Usar **`temperature = 1.0`**. Razonamiento:

1. **La reproducibilidad que se busca es la de la medicion agregada** (ASR sobre N
   intentos por payload), **no** la de cada respuesta individual. Lo que debe ser
   estable y comparable entre corridas es la *distribucion*, fijada por `model` +
   `temperature` + N, no cada token.

2. **Con `temperature = 0` el ASR colapsa a binario por payload**: cada payload sale
   siempre igual, N deja de tener sentido, y se pierde el fenomeno central — el
   ataque es **probabilistico** y la **persistencia gana**. Cf. system card de Claude
   Opus 4.5: inyeccion indirecta en entornos de coding agentico con ~4,7% de exito a
   1 intento y ~63% a 100. Un lab que mide a temp 0 no puede observar esa curva.

3. **`temperature = 1.0` es ademas lo que corre un agente real en produccion**: el
   target se comporta como el sistema que se quiere estudiar, no como una version
   determinista artificial.

## Consecuencias

- El ASR es probabilistico -> hay que lanzar **N intentos por payload**
  (N x payloads x niveles), lo que implica cientos de `POST /api/reset`; por eso el
  reset debe ser barato (recarga fixtures + limpia logs, sin dropear el esquema).
- **Corolario para el harness** (repo aparte, aqui solo se documenta): debe reportar
  **N y el intervalo de confianza**, no porcentajes pelados. Un ASR del 5% con N=10 no
  dice nada; la comparacion entre niveles solo es valida con N suficiente y su IC.

## Alternativas descartadas

- **`temperature = 0`**: colapsa el ASR a binario, invalida N y oculta el fenomeno
  probabilistico que es el objeto de estudio. Descartada.
- **Dejar `temperature` implicita (default de la API)**: rompe la comparabilidad
  entre corridas si la API cambia su default; ademas no queda registrada por corrida.
  Descartada.

## English summary

Send `temperature` **explicitly** (default `1.0`, configurable), never implicit —
API-default drift would break cross-run comparability; effective `model`/`temperature`
are echoed in `/api/chat`'s `meta`. `1.0` is deliberate: the reproducibility we want
is of the **aggregate** ASR over N attempts, not of each response. `temperature = 0`
collapses ASR to per-payload binary, makes N meaningless, and hides the central
probabilistic phenomenon — persistence wins (cf. Claude Opus 4.5 system card: indirect
prompt injection in agentic coding ~4.7% at 1 attempt, ~63% at 100). `1.0` also
matches a real production agent. Corollary (harness, separate repo): report N and a
confidence interval, not bare percentages.
