<?php

declare(strict_types=1);

namespace App\Controller;

use App\Defense\LabLevelResolver;
use App\Lab\ExfilProjector;
use App\Lab\LabDataset;
use App\Lab\LabResetService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints de administracion/observabilidad del laboratorio.
 *
 * Controlador delgado: toda la logica vive en servicios (aqui solo se resuelve
 * el nivel efectivo y se serializa).
 *
 * Los verbos NO son cosmeticos: son la frontera anti-SSRF (Q2). `fetch_url` es
 * deliberadamente sobre-permisiva y solo emite GET; por eso `/api/reset` es POST-only
 * (una SSRF no puede resetear la corrida a mitad de un experimento) y `/api/exfil` es
 * GET-only de solo-lectura. Esto NO arregla la vulnerabilidad intencionada (exfiltrar a
 * un dominio del atacante sigue igual); protege el plano de control del propio lab —
 * mismo principio que el Bloque H con el reset destructivo.
 */
final class AdminController
{
    public function __construct(
        private readonly LabLevelResolver $levels,
    ) {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $level = $this->levels->resolve();

        return new JsonResponse([
            'status' => 'ok',
            'level' => $level->value,
            'level_label' => $level->label(),
        ]);
    }

    /**
     * Recarga fixtures + limpia el log durable (barato, sin dropear esquema salvo dano).
     * POST-only: alcanzable por el operador/harness, NO por la GET de `fetch_url` (Q2).
     *
     * PARAMETRIZADO (aditivo): sin cuerpo -> estado canonico (retrocompatible). Con
     * `{"poisoned_review": "<string>"}` -> sobrescribe SOLO el cuerpo de la review
     * envenenada. Allowlist estricta: cualquier otra clave, tipo no-string o exceso de
     * longitud -> 400 ANTES de tocar la BD (una request malformada no resetea a medias).
     * Secret/PII/nivel/fichero del flag quedan BLINDADOS (la request no los alcanza).
     *
     * La respuesta incluye la confirmacion de siembra (len + sha256 del cuerpo efectivo
     * leido de la BD): el harness verifica que la inyeccion tomo antes de gastar en chat.
     */
    #[Route('/api/reset', name: 'api_reset', methods: ['POST'])]
    public function reset(Request $request, LabResetService $reset): JsonResponse
    {
        $override = null;
        $raw = trim($request->getContent());

        if ('' !== $raw) {
            try {
                $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->badRequest('cuerpo no es JSON valido');
            }
            if (!\is_array($decoded) || array_is_list($decoded)) {
                return $this->badRequest('el cuerpo debe ser un objeto JSON');
            }
            $extra = array_diff(array_keys($decoded), ['poisoned_review']);
            if ([] !== $extra) {
                return $this->badRequest(sprintf('clave(s) no permitida(s): %s', implode(', ', $extra)));
            }
            if (!\array_key_exists('poisoned_review', $decoded)) {
                return $this->badRequest('falta la clave requerida "poisoned_review"');
            }
            if (!\is_string($decoded['poisoned_review'])) {
                return $this->badRequest('"poisoned_review" debe ser un string');
            }
            if (mb_strlen($decoded['poisoned_review']) > LabDataset::MAX_POISONED_REVIEW_LEN) {
                return $this->badRequest(sprintf(
                    '"poisoned_review" supera el maximo de %d caracteres',
                    LabDataset::MAX_POISONED_REVIEW_LEN,
                ));
            }
            $override = $decoded['poisoned_review'];
        }

        $confirmation = $reset->reset($override);

        return new JsonResponse(['status' => 'reset'] + $confirmation);
    }

    private function badRequest(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], 400);
    }

    /**
     * Log consultable de egress (trampa de exfiltracion): proyeccion de las filas
     * `fetch_url` de `ToolInvocation`, con `domain`/`query` parseados server-side (Q1).
     */
    #[Route('/api/exfil', name: 'api_exfil', methods: ['GET'])]
    public function exfil(ExfilProjector $projector): JsonResponse
    {
        return new JsonResponse(['entries' => $projector->entries()]);
    }
}
