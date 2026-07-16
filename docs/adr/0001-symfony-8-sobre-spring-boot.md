# ADR 0001 — Symfony 8 (PHP) sobre Spring Boot (Java) para el target

Fecha: 2026-07-16
Estado: Aceptado

## Contexto

El target del laboratorio es una aplicacion web que envuelve a un agente LLM con
herramientas. Necesitamos un framework de servidor maduro para: modelar entidades
(Doctrine), exponer un contrato HTTP estable para el harness, e implementar a mano
el bucle de tool use contra la API de Messages de Anthropic. El proyecto es de
portfolio (FCT / entrevistas AppSec) y debe poder defenderse en entrevista.

Las alternativas realistas eran Symfony (PHP) y Spring Boot (Java), ambas
empresariales y con ORM de primera clase.

## Decision

Usar **Symfony 8 sobre PHP 8.4**, estructura estandar (no microframework), con
Doctrine ORM. El bucle de tool use se implementa a mano sobre
`Symfony\Contracts\HttpClient\HttpClientInterface`, sin SDK (ver ADR 0003).

Motivos:

- El descubrimiento de herramientas por **atributos PHP 8 + compiler pass** (ADR
  0004) es idiomatico y expresivo en Symfony; el DI container y los tags encajan
  directamente con el "registro de herramientas" del diseno.
- Control total del contexto de cada llamada al LLM, imprescindible para las capas
  de defensa (sobre todo el patron dual-LLM previsto para v2).
- Menor ceremonia que Spring Boot para un target de laboratorio; iteracion rapida.
- Encaja con el perfil del autor (stack PHP/Symfony en el portfolio).

## Consecuencias

- Positivas: DI + atributos + compiler pass hacen limpio el registro de tools;
  Doctrine cubre entidades y fixtures; ecosistema conocido.
- Negativas / asumidas: PHP no tiene el tipado estatico de Java; se compensa con
  `declare(strict_types=1)`, PSR-12 y tests. El harness es un repo aparte en Python,
  desacoplado por HTTP, asi que el lenguaje del target no lo condiciona.

## Alternativas descartadas

- **Spring Boot (Java)**: mas ceremonia y arranque; el registro de tools por
  anotaciones + reflexion seria comparable pero mas verboso; no aporta ventaja
  decisiva para un target de lab y aleja del stack del autor.
- **Microframework (Slim / API Platform minimal)**: se pierde el DI container y el
  soporte de compiler passes que hacen elegante el registro de herramientas.

## English summary

We build the vulnerable target on **Symfony 8 / PHP 8.4** (standard structure,
Doctrine ORM) instead of Spring Boot. Symfony's DI container, PHP 8 attributes and
compiler passes map cleanly onto the attribute-based tool registry and give full
control over each LLM call — required for the defense layers (and the v2 dual-LLM
pattern). The attack harness is a separate Python repo talking HTTP, so the target's
language does not constrain it.
