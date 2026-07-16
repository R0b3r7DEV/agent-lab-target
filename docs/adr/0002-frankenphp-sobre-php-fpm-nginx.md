# ADR 0002 — FrankenPHP (modo clasico) sobre php-fpm + Nginx

Fecha: 2026-07-16
Estado: Aceptado

## Contexto

El target corre en Docker Compose y debe levantar con `docker compose up`. Hacen
falta un servidor HTTP que sirva la app Symfony y una base de datos PostgreSQL 16.
Para el servidor PHP habia dos opciones idiomaticas: FrankenPHP (un solo servicio,
Caddy + PHP embebido) o el clasico php-fpm + Nginx (dos servicios).

Requisito operativo del laboratorio: las llamadas al LLM con tool use pueden
encadenar varias iteraciones y tardar; el **timeout de request debe ser >= 180s**.

## Decision

Usar **FrankenPHP en modo clasico** (no worker): un proceso PHP por peticion,
un unico contenedor de app. `SERVER_NAME=:80`.

- Un solo servicio de app en vez de dos (php-fpm + Nginx): menos piezas que mantener
  en un lab.
- Modo **clasico** (no worker) para v1: aislamiento de estado por peticion, mas
  simple de razonar; el worker mode se puede evaluar mas adelante si hiciera falta.
- Timeouts controlados explicitamente en el `Caddyfile`
  (`read_body 180s`, `write 300s`, `idle 300s`) y `max_execution_time = 180` en PHP,
  para que las llamadas largas al LLM no se corten.

## Consecuencias

- Positivas: `docker compose up` levanta app + Postgres; configuracion de servidor
  concentrada en un `Caddyfile` corto; HTTP/2 nativo.
- Negativas / asumidas: FrankenPHP es menos "estandar empresa" que php-fpm + Nginx,
  que quiza el autor quiera saber defender aparte; se documenta la alternativa para
  poder discutirla en entrevista. El modo clasico no aprovecha el worker mode (mas
  rendimiento), aceptable para un lab.

## Alternativas descartadas

- **php-fpm + Nginx**: mas ceremonia (config de Nginx aparte, dos servicios), sin
  ventaja para un target de laboratorio de baja carga.
- **FrankenPHP worker mode**: mayor rendimiento pero introduce estado compartido
  entre peticiones; innecesario en v1 y complica el razonamiento sobre aislamiento.

## English summary

The app runs on **FrankenPHP in classic (non-worker) mode** — a single app
container instead of php-fpm + Nginx — with `SERVER_NAME=:80`. Request timeouts are
set explicitly (Caddy `write 300s`, PHP `max_execution_time 180`) so long tool-use
LLM calls are not cut off (>= 180s requirement). Worker mode and php-fpm+Nginx are
documented as discarded alternatives.
