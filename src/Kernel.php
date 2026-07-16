<?php

declare(strict_types=1);

namespace App;

use App\DependencyInjection\Compiler\AgentToolPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // Descubre las herramientas y genera sus schemas (agnostico del nivel, ADR 4).
        $container->addCompilerPass(new AgentToolPass());
    }
}
