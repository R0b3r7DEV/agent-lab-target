<?php

declare(strict_types=1);

namespace App\Tests\Defense;

use App\Defense\SensitivePathException;
use App\Defense\SensitivePathGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifica el doble filo del ADR 11: se cierran las fuentes de credenciales
 * reales, pero el vector didactico (secret.flag, /etc/passwd) sigue permitido.
 */
final class SensitivePathGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function deniedPaths(): iterable
    {
        yield 'entorno del proceso' => ['/proc/self/environ'];
        yield 'raiz proc' => ['/proc'];
        yield 'proc anidado' => ['/proc/1/cmdline'];
        yield 'sysfs' => ['/sys/class/net/eth0/address'];
        yield 'env.local de la app' => ['/app/.env.local'];
        yield 'env.local.php' => ['/app/.env.local.php'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedPaths(): iterable
    {
        // El vector didactico DEBE seguir funcionando.
        yield 'flag ficticio' => ['/app/var/sandbox/../secret.flag'];
        yield 'flag ficticio canonico' => ['/app/var/secret.flag'];
        yield 'passwd clasico' => ['/etc/passwd'];
        yield 'shadow (sin creds reales en contenedor)' => ['/etc/shadow'];
        yield 'placeholders .env' => ['/app/.env'];
        yield 'fichero normal del sandbox' => ['/app/var/sandbox/nota.txt'];
        // No confundir un basename que contenga "proc" con el prefijo /proc.
        yield 'no es /proc' => ['/app/data/process.log'];
    }

    #[DataProvider('deniedPaths')]
    public function testDeniesRealCredentialSources(string $path): void
    {
        self::assertFalse((new SensitivePathGuard())->isAllowed($path));
    }

    #[DataProvider('allowedPaths')]
    public function testAllowsDidacticVector(string $path): void
    {
        self::assertTrue((new SensitivePathGuard())->isAllowed($path));
    }

    public function testAssertReadableThrowsOnDenied(): void
    {
        $this->expectException(SensitivePathException::class);

        (new SensitivePathGuard())->assertReadable('/proc/self/environ');
    }

    public function testAssertReadableSilentOnAllowed(): void
    {
        (new SensitivePathGuard())->assertReadable('/etc/passwd');

        $this->expectNotToPerformAssertions();
    }
}
