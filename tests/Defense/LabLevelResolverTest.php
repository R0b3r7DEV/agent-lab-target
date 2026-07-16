<?php

declare(strict_types=1);

namespace App\Tests\Defense;

use App\Defense\DefenseLevel;
use App\Defense\LabLevelResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Cubre la resolucion de nivel en runtime y el "fallo fuerte" (Tarea D):
 * precedencia cabecera > env, y 400 ante nivel fuera de rango / no numerico,
 * tanto en la cabecera como en LAB_LEVEL. Sin clamp silencioso.
 */
final class LabLevelResolverTest extends TestCase
{
    private function resolver(string $envLevel, ?Request $request = null): LabLevelResolver
    {
        $stack = new RequestStack();

        if (null !== $request) {
            $stack->push($request);
        }

        return new LabLevelResolver($stack, $envLevel);
    }

    private function requestWithHeader(string $value): Request
    {
        $request = Request::create('/api/health');
        $request->headers->set('X-Lab-Level', $value);

        return $request;
    }

    /**
     * @return iterable<string, array{string, DefenseLevel}>
     */
    public static function validEnvLevels(): iterable
    {
        yield '0' => ['0', DefenseLevel::None];
        yield '1' => ['1', DefenseLevel::DataSeparation];
        yield '2' => ['2', DefenseLevel::LeastPrivilege];
        yield '3' => ['3', DefenseLevel::OutputFiltering];
    }

    #[DataProvider('validEnvLevels')]
    public function testResolvesValidEnvLevel(string $env, DefenseLevel $expected): void
    {
        self::assertSame($expected, $this->resolver($env)->resolve());
    }

    public function testHeaderOverridesEnv(): void
    {
        $resolver = $this->resolver('2', $this->requestWithHeader('1'));

        self::assertSame(DefenseLevel::DataSeparation, $resolver->resolve());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'fuera de rango alto' => ['99'];
        yield 'fuera de rango 4' => ['4'];
        yield 'negativo' => ['-1'];
        yield 'no numerico' => ['foo'];
        yield 'vacio' => [''];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidEnvLevelThrows400(string $value): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->resolver($value)->resolve();
    }

    #[DataProvider('invalidValues')]
    public function testInvalidHeaderLevelThrows400(string $value): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->resolver('0', $this->requestWithHeader($value))->resolve();
    }
}
