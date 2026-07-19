<?php

declare(strict_types=1);

namespace App\Command;

use App\Lab\LabResetService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Resetea el dataset del lab. Con --iterations sirve de benchmark del coste de
 * reset (requisito C2): se mide en el compose (ADR 13), anotando PG y N.
 */
#[AsCommand(
    name: 'app:lab:reset',
    description: 'Resetea el dataset del lab (TRUNCATE + reseed). --iterations para medir el coste.',
)]
final class LabResetCommand extends Command
{
    public function __construct(
        private readonly LabResetService $resetService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'iterations',
            'i',
            InputOption::VALUE_REQUIRED,
            'Numero de resets a ejecutar (benchmark del coste)',
            '1',
        );
        $this->addOption(
            'poisoned-review',
            null,
            InputOption::VALUE_REQUIRED,
            'Cuerpo de la review envenenada a inyectar (reset parametrizado); por defecto, el canonico',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $iterations = max(1, (int) $input->getOption('iterations'));
        /** @var string|null $override */
        $override = $input->getOption('poisoned-review');

        // Descarta el calentamiento: la primera reset paga JIT/cache/planificador.
        // A ~10ms da igual, pero deja el benchmark limpio (una vuelta sin cronometrar).
        if ($iterations > 1) {
            $this->resetService->reset($override);
        }

        $timesMs = [];
        for ($i = 0; $i < $iterations; ++$i) {
            $start = hrtime(true);
            $this->resetService->reset($override);
            $timesMs[] = (hrtime(true) - $start) / 1_000_000;
        }

        sort($timesMs);
        $min = $timesMs[0];
        $max = $timesMs[array_key_last($timesMs)];
        $avg = array_sum($timesMs) / $iterations;
        $p95 = $timesMs[(int) floor(0.95 * ($iterations - 1))];

        $output->writeln(sprintf(
            'reset x%d -> min=%.1fms avg=%.1fms p95=%.1fms max=%.1fms',
            $iterations,
            $min,
            $avg,
            $p95,
            $max,
        ));

        return Command::SUCCESS;
    }
}
