<?php

declare(strict_types=1);

namespace App\Defense;

/**
 * Niveles de defensa conmutables del laboratorio.
 *
 * Se resuelve SIEMPRE en runtime desde LAB_LEVEL (o el override X-Lab-Level),
 * nunca en tiempo de compilacion del contenedor (ver ADR 05). El set de
 * herramientas y su schema son estaticos y canonicos; cada nivel filtra o
 * recorta en runtime.
 */
enum DefenseLevel: int
{
    /** Sin defensa (baseline). */
    case None = 0;

    /** Separacion datos/instrucciones: el contenido no confiable se marca como datos. */
    case DataSeparation = 1;

    /** Minimo privilegio + human-in-the-loop sobre acciones sensibles. */
    case LeastPrivilege = 2;

    /** Filtrado de salida (DLP) + egress allowlist en fetch_url. */
    case OutputFiltering = 3;

    public function label(): string
    {
        return match ($this) {
            self::None => 'Nivel 0 - sin defensa',
            self::DataSeparation => 'Nivel 1 - separacion datos/instrucciones',
            self::LeastPrivilege => 'Nivel 2 - minimo privilegio + HITL',
            self::OutputFiltering => 'Nivel 3 - filtrado de salida + egress allowlist',
        };
    }
}
