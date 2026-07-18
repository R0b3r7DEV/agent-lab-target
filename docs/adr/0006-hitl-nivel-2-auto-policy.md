# ADR 0006 — Human-in-the-loop del Nivel 2 como auto-policy

Fecha: 2026-07-18
Estado: **Aceptado** (aprobado en revision e implementado en Fase 5)

## Contexto

El Nivel 2 (`LeastPrivilege`) somete las acciones sensibles (`send_email`,
`delete_account`, y `query_db` de escritura/DDL) a **confirmacion humana** antes de
ejecutarse. En un producto real la confirmacion la da una persona por UI.

Pero el lab lo consume un **harness automatizado y no interactivo**: mide el ASR sin
un humano en el bucle. Un prompt de confirmacion interactivo real:

- bloquearia el harness (no hay quien responda),
- introduciria estado conversacional y no-determinismo (el agente tendria que pedir
  confirmacion, esperar, reanudar), rompiendo el `/api/chat` single-turn stateless,
- haria irreproducible la medicion.

## Decision

El HITL del Nivel 2 se modela como una **auto-policy** deterministica, gobernada por
la variable de entorno `LAB_CONFIRM_POLICY`:

- `deny` (por defecto): toda accion sensible se **bloquea** con
  `blocked_reason = 'confirmation_denied'`. Simula al humano que dice "no" (la postura
  segura por defecto).
- `allow`: toda accion sensible se **auto-confirma** y pasa. Simula al humano que
  siempre dice "si" — util para medir el ASR de la *ruta feliz* del Nivel 2 (que tapa
  el minimo privilegio del schema sin depender del veto humano).

El gate registra igualmente el `ToolInvocation` ANTES de decidir (Cambio 1): con
`deny`, la fila queda `blocked=true`; el harness ve el intento y la razon.

## Consecuencias

- El Nivel 2 es medible en un pipeline no interactivo, deterministico y reproducible.
- Se pueden medir DOS cosas distintas cambiando solo la env: el efecto del **veto**
  (`deny`) y el efecto del **minimo privilegio anunciado** (`allow`, donde el modelo
  ve `query_db` como solo-lectura pero puede intentar acciones sensibles que se
  confirman solas).
- **Limitacion honesta:** una auto-policy no captura la friccion real de un humano
  (fatiga de confirmaciones, click-through). Eso es explicito en `docs/DEFENSES.md`
  como "lo que esta capa NO cubre": un usuario que aprueba por inercia una accion
  inducida por inyeccion sigue siendo vulnerable, y el lab no lo mide.

## Alternativas descartadas

- **Confirmacion interactiva real (segundo turno).** Bloquea el harness y rompe el
  single-turn stateless. Descartada.
- **Aprobacion siempre concedida, sin `deny`.** Perderia la capa mas interesante (el
  veto), que es justo la que baja el ASR de las acciones sensibles.
- **Heuristica "confirmar solo si parece malicioso".** Seria una mini-clasificador
  dentro de la defensa, no deterministico y dificil de atribuir; ademas se acercaria a
  "arreglar" la vuln por deteccion. El eje del Nivel 2 es *privilegio*, no *deteccion*.
