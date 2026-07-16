# ADR 0004 — Registro de herramientas por atributos + compiler pass

Fecha: 2026-07-17
Estado: Aceptado

## Contexto

El agente expone herramientas a la API de Messages en el campo `tools`, cada una con
su JSON Schema (`name`, `description`, `input_schema`). Escribir esos schemas a mano y
mantenerlos sincronizados con las clases que los implementan es duplicacion y una
fuente de errores. Ademas, el nivel de defensa (`LAB_LEVEL`) filtra que herramientas se
ofrecen, y ese filtrado NO puede horneiarse en el contenedor compilado (ver ADR 05 /
Cambio 4): un compiler pass que leyera el nivel recibiria el placeholder, no el valor.

## Decision

- **Descubrimiento por atributos PHP 8:** `#[AgentTool(name:, description:)]` sobre la
  clase; `#[AgentToolParam(name:, type:, description:, required:, enum:)]` repetible por
  parametro. Cada herramienta implementa `AgentToolInterface::execute(array): ToolResult`
  y se autoconfigura con el tag `app.agent_tool` (via `_instanceof`).
- **`AgentToolPass` (compiler pass):** recorre los servicios tagueados, lee los atributos
  por reflexion y **genera el JSON Schema una sola vez**. Inyecta en `ToolRegistry`:
  (a) `$definitions` (el set canonico `name -> {name, description, input_schema}`),
  (b) `$tools` (un `ServiceLocator` para ejecutar cada herramienta por nombre). Orden
  determinista (`ksort`) para reproducibilidad / cache de prompt.
- **Agnostico del nivel (Cambio 4):** el pass genera el conjunto COMPLETO y canonico y
  **no lee `LAB_LEVEL` ni resuelve parametros del contenedor**. El filtrado por nivel es
  RUNTIME, en `ToolRegistry::schemas(DefenseLevel)`: en Nivel 0 devuelve el set completo;
  los recortes de minimo privilegio (Nivel 2) y demas llegan en la Fase 5, en ese mismo
  punto de extension.

## Consecuencias

- Positivas: cero schemas a mano; el schema no puede desincronizarse de la clase (sale de
  ella); anadir una herramienta = una clase con atributos; el filtrado por nivel vive en
  un unico sitio (runtime), sin riesgo del footgun de compile-time.
- Negativas / asumidas: la reflexion en el pass corre en compile time (coste unico, se
  cachea). `ToolRegistry` recibe sus argumentos del pass, asi que se declara con
  placeholders explicitos (`$definitions: []`, `$tools: !service_locator {}`) para que el
  autowiring no intente resolverlos.

## Verificacion

- Test: las 5 herramientas se registran; el schema tiene la forma del campo `tools`
  (`input_schema.type = object`, `properties`, `required` como lista); el set es identico
  a cualquier nivel (canonico) y el pass no llama a `getParameter`/`getParameterBag`.

## Alternativas descartadas

- **Schemas a mano** (array por herramienta): duplicacion y desincronizacion garantizada.
- **Leer el nivel en el pass y generar solo las herramientas del nivel activo:** el
  footgun del Cambio 4 — placeholder en compile time, schemas obsoletos en silencio al
  cambiar `LAB_LEVEL` sin limpiar cache. Descartada por diseno.

## English summary

Tools are discovered via PHP 8 attributes (`#[AgentTool]`, `#[AgentToolParam]`) and a
compiler pass (`AgentToolPass`) that reflects them and **generates the Messages-API JSON
Schema once**, injecting into `ToolRegistry` the canonical definition set plus a
`ServiceLocator` for execution. **The pass is level-agnostic (Cambio 4): it never reads
`LAB_LEVEL` or resolves container parameters** — doing so at compile time would bake the
placeholder and silently produce stale schemas. Per-level filtering is a runtime concern
in `ToolRegistry::schemas(DefenseLevel)` (full set at Level 0; least-privilege trimming
arrives in Phase 5). Tested: 5 tools registered, valid schema shape, level-independent
canonical set, no `getParameter` in the pass.
