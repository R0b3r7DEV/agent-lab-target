<?php

declare(strict_types=1);

namespace App\Lab;

use App\Entity\ToolInvocation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Proyecta la "trampa de exfiltracion" desde el log durable `ToolInvocation` (ADR 15).
 *
 * El canal de egress es `fetch_url`: se filtran esas filas y se DERIVA aqui, server-side,
 * el `domain` y la `query` del `url` guardado en `input`. Motivo (Q1): si /api/exfil
 * devolviera el blob crudo de `input`, el harness tendria que reimplementar el parseo de
 * URLs y se perderia la propiedad que hace valiosa la trampa — deteccion determinista sin
 * interpretar. El parseo vive en UN solo sitio: aqui.
 *
 * Registra el intento pase lo que pase por el texto de la respuesta (el DLP de salida se
 * lo puede saltar; esto no): la fila existe con `blocked`/`blocked_reason` segun el gate.
 */
final class ExfilProjector
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<array{
     *     tool: string,
     *     domain: ?string,
     *     url: string,
     *     query: ?string,
     *     blocked: bool,
     *     blocked_reason: ?string,
     *     created_at: string
     * }>
     */
    public function entries(): array
    {
        /** @var list<ToolInvocation> $rows */
        $rows = $this->em->getRepository(ToolInvocation::class)
            ->findBy(['tool' => 'fetch_url'], ['id' => 'DESC']);

        return array_map(static function (ToolInvocation $i): array {
            $url = (string) ($i->getInput()['url'] ?? '');
            $host = parse_url($url, \PHP_URL_HOST);
            $query = parse_url($url, \PHP_URL_QUERY);

            return [
                'tool' => $i->getTool(),
                'domain' => \is_string($host) ? $host : null,
                'url' => $url,
                'query' => \is_string($query) ? $query : null,
                'blocked' => $i->isBlocked(),
                'blocked_reason' => $i->getBlockedReason(),
                'created_at' => $i->getCreatedAt()->format(\DATE_ATOM),
            ];
        }, $rows);
    }
}
