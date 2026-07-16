#!/usr/bin/env bash
# Smoke test del contrato de /api/health (Tarea A). Reutilizable:
#   - En CI (GitHub Actions) contra el contenedor FrankenPHP.
#   - En local bajo WSL/Docker: ./scripts/smoke-health.sh http://localhost:8080
set -euo pipefail

BASE="${1:-http://localhost:8080}"

fail() { echo "SMOKE FAIL: $1"; exit 1; }

level() { curl -fsS -H "${2:-X-Empty: 1}" "$BASE/api/health" | jq -r '.level'; }
code()  { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "== base: $BASE =="

# 1) Nivel por defecto -> 0 (LAB_LEVEL ausente -> procesador default:; ver ADR 11).
[ "$(curl -fsS "$BASE/api/health" | jq -r '.level')" = "0" ] || fail "default != 0"
echo "ok: default -> 0"

# 2) Override por cabecera -> 3.
[ "$(level x 'X-Lab-Level: 3')" = "3" ] || fail "X-Lab-Level:3 != 3"
echo "ok: X-Lab-Level:3 -> 3"

# 3) Nivel invalido (fuera de rango) -> 400, sin clamp silencioso (Tarea D).
[ "$(code -H 'X-Lab-Level: 99' "$BASE/api/health")" = "400" ] || fail "X-Lab-Level:99 != 400"
echo "ok: X-Lab-Level:99 -> 400"

# 4) No numerico -> 400.
[ "$(code -H 'X-Lab-Level: abc' "$BASE/api/health")" = "400" ] || fail "X-Lab-Level:abc != 400"
echo "ok: X-Lab-Level:abc -> 400"

# 5) Metodo no permitido -> 405.
[ "$(code -X POST "$BASE/api/health")" = "405" ] || fail "POST != 405"
echo "ok: POST -> 405"

echo "SMOKE OK"
