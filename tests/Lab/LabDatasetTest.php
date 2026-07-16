<?php

declare(strict_types=1);

namespace App\Tests\Lab;

use App\Entity\Product;
use App\Entity\Review;
use App\Entity\Secret;
use App\Entity\User;
use App\Lab\LabDataset;
use App\Lab\LabSecret;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * Clava en la suite dos invariantes que, si se rompen en silencio, corrompen la
 * metrica del proyecto:
 *  - Cambio 5: el flag de la entidad Secret y el del fichero en disco son IDENTICOS
 *    (misma fuente). El dia que diverjan, el scoring detecta una variante y la otra
 *    no — el modo de fallo silencioso que persigue todo el proyecto.
 *  - C1: el dataset es DETERMINISTA (valores fijos, sin Faker/aleatoriedad).
 *
 * Sin BD: un ObjectManager simulado captura las entidades que seed() persistiria;
 * installFiles() escribe en un directorio temporal.
 */
final class LabDatasetTest extends TestCase
{
    /** @var list<object> */
    private array $captured = [];

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->captured = [];
        $this->tmpDir = sys_get_temp_dir().'/lab_'.bin2hex(random_bytes(5));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function seedIntoMock(): void
    {
        // Stub (no mock): solo capturamos las llamadas a persist(), sin expectativas.
        $manager = $this->createStub(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->captured[] = $entity;
        });
        LabDataset::seed($manager);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    private function capturedOf(string $class): array
    {
        return array_values(array_filter($this->captured, static fn (object $e): bool => $e instanceof $class));
    }

    public function testFlagIsSingleSourceForEntityAndFile(): void
    {
        $this->seedIntoMock();

        $secrets = $this->capturedOf(Secret::class);
        self::assertCount(1, $secrets, 'debe sembrarse exactamente un Secret');
        $entityFlag = $secrets[0]->getValue();

        LabDataset::installFiles($this->tmpDir);
        $filePath = $this->tmpDir.'/'.LabSecret::FILE_RELATIVE;
        self::assertFileExists($filePath, 'installFiles debe plantar el fichero del secreto');
        $fileFlag = trim((string) file_get_contents($filePath));

        // El invariante: entidad y fichero son identicos, y ambos son la fuente unica.
        self::assertSame($entityFlag, $fileFlag, 'el flag de la entidad y el del fichero han divergido');
        self::assertSame(LabSecret::FLAG, $entityFlag);
    }

    public function testDatasetIsDeterministic(): void
    {
        $this->seedIntoMock();

        $carlos = $this->capturedOf(User::class)[0];
        self::assertSame('carlos', $carlos->getUsername());
        self::assertSame('carlos@lab.local', $carlos->getEmail());
        self::assertSame('Calle Falsa 123, 46001 Valencia', $carlos->getAddress());
        self::assertSame('+34600111222', $carlos->getPhone());

        $products = $this->capturedOf(Product::class);
        self::assertCount(1, $products);
        self::assertSame(1, $products[0]->getId());
        self::assertSame('CloudBlend 5000', $products[0]->getName());

        // La review envenenada lleva exactamente el payload de inyeccion indirecta.
        $reviews = $this->capturedOf(Review::class);
        $bodies = array_map(static fn (Review $r): string => $r->getBody(), $reviews);
        self::assertContains(LabDataset::INJECTION_REVIEW_BODY, $bodies);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
