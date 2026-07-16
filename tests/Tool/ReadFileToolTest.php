<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Defense\SensitivePathGuard;
use App\Lab\LabSecret;
use App\Tool\ReadFileTool;
use PHPUnit\Framework\TestCase;

/**
 * Tests de read_file EN LAS DOS DIRECCIONES (G3):
 *
 *  1. BLOQUEA fuentes de credenciales reales (/proc, /sys, .env.local), incluso
 *     alcanzadas por traversal relativo y con segmentos redundantes — porque la
 *     denylist se comprueba sobre la ruta RESUELTA (realpath), no la cruda.
 *  2. El VECTOR DIDACTICO sigue vivo: `../secret.flag` sigue funcionando y
 *     `/etc/passwd` sigue siendo legible. Si estos fallan, se ha roto el lab.
 *
 * Los targets absolutos (/etc/passwd, /proc) solo existen en Linux -> esos tests se
 * saltan fuera de Linux; corren en el compose/CI. El de secret.flag es cross-platform
 * (se planta en un projectDir temporal).
 */
final class ReadFileToolTest extends TestCase
{
    private string $projectDir;
    private ReadFileTool $tool;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/rf_'.bin2hex(random_bytes(5));
        mkdir($this->projectDir.'/var/sandbox', 0o775, true);
        file_put_contents($this->projectDir.'/var/sandbox/manual.txt', "contenido benigno del sandbox\n");
        // El secreto, un nivel por encima del sandbox (como en produccion del lab).
        file_put_contents($this->projectDir.'/var/secret.flag', LabSecret::FLAG."\n");

        $this->tool = new ReadFileTool(new SensitivePathGuard(), $this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    public function testReadsBenignSandboxFile(): void
    {
        $result = $this->tool->execute(['path' => 'manual.txt']);

        self::assertFalse($result->isError);
        self::assertStringContainsString('benigno', $result->content);
    }

    // ---- Direccion 2: el vector didactico DEBE seguir funcionando ----

    public function testTraversalToSecretFlagStillWorks(): void
    {
        $result = $this->tool->execute(['path' => '../secret.flag']);

        self::assertFalse($result->isError, 'el traversal a ../secret.flag NO debe bloquearse (es el vector del lab)');
        self::assertStringContainsString(LabSecret::FLAG, $result->content);
    }

    public function testEtcPasswdStillReadable(): void
    {
        $this->requireLinux();

        $result = $this->tool->execute(['path' => $this->traversalTo('etc/passwd')]);

        self::assertFalse($result->isError, '/etc/passwd debe seguir siendo legible (target clasico, no es credencial del proceso)');
        self::assertStringContainsString('root:', $result->content);
    }

    // ---- Direccion 1: fuentes de credenciales reales BLOQUEADAS ----

    public function testProcSelfEnvironIsBlockedViaTraversal(): void
    {
        $this->requireLinux();

        $result = $this->tool->execute(['path' => $this->traversalTo('proc/self/environ')]);

        self::assertTrue($result->isError, '/proc/self/environ (entorno del proceso -> API key real) debe bloquearse');
    }

    public function testProcIsBlockedWithRedundantSegments(): void
    {
        $this->requireLinux();

        // Segmentos redundantes (/./): realpath los normaliza ANTES de la denylist.
        $result = $this->tool->execute(['path' => $this->traversalTo('proc/./self/cmdline')]);

        self::assertTrue($result->isError, 'la normalizacion de /./ no debe permitir esquivar la denylist');
    }

    /**
     * Construye un path relativo desde el sandbox que resuelve a `/$absTarget`,
     * calculando la profundidad dinamicamente (robusto ante la ruta del temp dir).
     */
    private function traversalTo(string $absTarget): string
    {
        $sandbox = (string) realpath($this->projectDir.'/var/sandbox');
        $depth = substr_count(trim($sandbox, '/'), '/') + 1;

        return str_repeat('../', $depth).$absTarget;
    }

    private function requireLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            self::markTestSkipped('target absoluto solo disponible en Linux (corre en el compose/CI)');
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
