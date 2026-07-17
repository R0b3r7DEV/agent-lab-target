# ADR 0014 — Reset con restauracion de esquema ante DDL destructivo

Fecha: 2026-07-17
Estado: Aceptado

## Contexto

`query_db` acepta **SQL arbitrario, DDL incluido** (Nivel 0, intencionado). Un payload
que intente escalar con `DROP TABLE app_user` o `ALTER TABLE ...`, o simplemente el
modelo generando SQL destructivo mientras "explora", deja el esquema danado. A partir de
ahi el reset de la Fase 2 (`TRUNCATE` + reseed) **falla en silencio**, y todas las
mediciones posteriores de la corrida son basura.

Con cientos de payloads corriendo desatendidos (N x 20 payloads x 4 niveles), esto
ocurre; y cuando ocurre, no sabrias en que payload se envenono la corrida. Es un fallo
silencioso que corrompe la metrica principal — la familia de problemas que persigue todo
el proyecto.

## Decision

**Opcion (a): reset con via rapida + restauracion.**

- **Camino feliz (~99%):** `TRUNCATE ... RESTART IDENTITY CASCADE` + reseed en una
  transaccion. El `TRUNCATE` es a la vez el reset y la **comprobacion barata de deriva**:
  si una tabla falta (`DROP`), lanza; si una columna falta (`ALTER`), lanza el reseed.
  Coste ~10 ms (medido, ADR 13).
- **Solo tras dano de DDL:** restauracion completa — `DROP SCHEMA public CASCADE` +
  recrear el esquema desde el mapping de Doctrine (`SchemaTool::createSchema`, equivalente
  a la migracion; `schema:validate` lo garantiza) + reseed. Se paga el precio (mas caro)
  **solo cuando toca**, no en cada reset.

Ventaja decisiva: **mantiene la superficie de ataque intacta.** `query_db` sigue
aceptando DDL; el vector no se recorta. El reset solo garantiza que la siguiente medicion
parte de un lab operativo.

## Consecuencias

- El 99% de los resets siguen costando ~10 ms; solo tras un payload destructivo se paga
  la restauracion completa. Presupuesto de sobra: incluso 300 ms x 400 resets son 2 min.
- `DROP SCHEMA public CASCADE` elimina tambien `doctrine_migration_versions`; no importa,
  el lab no re-ejecuta migraciones en runtime (la restauracion crea el esquema desde el
  mapping, que `schema:validate` garantiza identico a la migracion).
- **Test (Bloque H):** `LabResetRecoveryTest` (#[Group('db')]) ejecuta `DROP TABLE review
  CASCADE` **via la tool real `query_db`**, resetea, y verifica que el lab queda operativo
  (carlos vuelve, las 2 reviews vuelven). Corre en el compose/CI (PG18), no en local.

## Alternativas descartadas

- **(b) Rol de BD dedicado para `query_db` con DML pero sin DDL.** Protegeria la
  integridad de la medicion restringiendo DDL. Argumento a favor: el vector estudiado es
  la **exfiltracion via SELECT**, no la destruccion de esquema, asi que restringir DDL
  **no reduce el vector**. Contraargumento honesto: en el mundo real la SQLi **escala a
  DDL**, asi que el lab perderia algo de realismo. Descartada: (a) conserva el realismo
  completo y el presupuesto sobra, asi que no hay razon para recortar el objeto de estudio.

## English summary

`query_db` accepts arbitrary SQL including DDL (Level 0, intended). Destructive DDL
(`DROP`/`ALTER`) would break the Phase 2 `TRUNCATE` reset silently, poisoning the rest of
an unattended run. Decision (option a): **fast path + restore** — happy path is
`TRUNCATE`+reseed (~10 ms; the TRUNCATE itself is the cheap drift check), and only after
DDL damage does the reset do a full restore (`DROP SCHEMA public CASCADE` +
`SchemaTool::createSchema` from the mapping + reseed). This **keeps the attack surface
intact** (query_db still allows DDL) while guaranteeing the next measurement starts from
an operational lab. Alternative (b) — a DDL-less DB role for query_db — was discarded:
the studied vector is SELECT exfiltration, so restricting DDL doesn't reduce it, but (a)
keeps full realism and the budget is ample. Verified by `LabResetRecoveryTest` (DB group)
running destructive DDL via the real `query_db` tool, in the compose/CI.
