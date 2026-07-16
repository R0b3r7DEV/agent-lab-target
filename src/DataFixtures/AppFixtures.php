<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Lab\LabDataset;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Carga inicial del dataset del lab. Deterministas por construccion (ver
 * LabDataset): la misma fuente que usa el reset, para que carga y reset dejen
 * el sistema en un estado identico y comparable entre corridas.
 */
final class AppFixtures extends Fixture
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        LabDataset::seed($manager);
        $manager->flush();
        LabDataset::installFiles($this->projectDir);
    }
}
