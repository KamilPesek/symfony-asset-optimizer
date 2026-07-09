<?php

declare(strict_types=1);

namespace AssetOptimizer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use function defined;
use const PHP_BINARY;
use const SIGINT;
use const SIGTERM;

/**
 * Dev preview watch: recompiles the asset map whenever a source asset changes,
 * so optimized images + WebP twins are visible live in dev.
 *
 * `asset-map:compile` already runs the sass build (via the sass-bundle's
 * PreAssetsCompileEvent listener, if installed), so this single command covers
 * sass + optimization + WebP. JS/CSS are left unminified in dev, which keeps
 * debugging sane. The compile subprocess output is captured and only shown on
 * failure — this deliberately hides AssetMapper's "debug mode is enabled…"
 * warning, irrelevant here because the watch keeps public/assets fresh.
 */
#[AsCommand(
    name: 'asset-optimizer:watch',
    description: 'Watch assets and recompile (optimize images + WebP, and sass) on change — dev preview.',
)]
final class WatchCommand extends Command implements SignalableCommandInterface
{
    private bool $running = true;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Optimizer — watch');
        $io->writeln('Watching <info>assets/</info>. Optimized images + WebP are served in dev on change.');
        $io->writeln('<comment>Ctrl-C to stop.</comment>');
        $io->newLine();

        $this->compile($io, 'initial build');
        $signature = $this->snapshot();

        while ($this->running) {
            usleep(500_000);
            $next = $this->snapshot();
            if ($next !== $signature) {
                $signature = $next;
                $this->compile($io, 'change detected');
            }
        }

        $io->newLine();
        $io->writeln('Stopped watching.');

        return Command::SUCCESS;
    }

    /**
     * Fingerprint of every source asset (path + mtime + size). Any add, edit, or
     * delete under assets/ changes the string.
     */
    private function snapshot(): string
    {
        $dir = $this->projectDir . '/assets';
        if (!is_dir($dir)) {
            return '';
        }

        $parts = [];
        foreach (new Finder()->files()->in($dir) as $file) {
            $parts[] = $file->getRelativePathname() . ':' . $file->getMTime() . ':' . $file->getSize();
        }
        sort($parts);

        return implode('|', $parts);
    }

    private function compile(SymfonyStyle $io, string $reason): void
    {
        $io->write(sprintf('<info>[%s]</info> compiling… ', $reason));

        $start = hrtime(true);
        $process = new Process(
            [PHP_BINARY, $this->projectDir . '/bin/console', 'asset-map:compile', '--no-interaction'],
            $this->projectDir,
        );
        $process->setTimeout(null);
        $process->run();
        $ms = (int) round((hrtime(true) - $start) / 1_000_000);

        if ($process->isSuccessful()) {
            $io->writeln(sprintf('<info>done</info> in %d ms.', $ms));

            return;
        }

        // Surface the real error only when the compile actually fails.
        $io->writeln('<error>failed:</error>');
        $io->write($process->getErrorOutput() ?: $process->getOutput());
    }

    public function getSubscribedSignals(): array
    {
        return array_values(array_filter([
            defined('SIGINT') ? SIGINT : null,
            defined('SIGTERM') ? SIGTERM : null,
        ]));
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->running = false;

        return false;
    }
}
