<?php

declare(strict_types=1);

namespace AssetOptimizer\Command;

use AssetOptimizer\Watch\WatchLock;
use AssetOptimizer\Watch\WatchTransition;
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
 * While running it holds the {@see WatchLock}, which is what switches
 * {@see \AssetOptimizer\Compiler\ImageOptimizeCompiler} on in dev — without a
 * watch, dev serves raw originals. Start and stop run a {@see WatchTransition}
 * so the content-hash digests actually flip between the two states; on stop
 * the compiled asset files are kept (their WebP twins make the next start
 * fast) and only the compiled config JSONs are removed — while those exist,
 * AssetMapper would keep serving the compiled assets in dev. If the watch dies
 * without cleanup (kill -9, or Ctrl-C without pcntl), the lock still
 * auto-releases and {@see \AssetOptimizer\EventListener\WatchTransitionListener}
 * heals the state on the next request.
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
        private readonly WatchLock       $watchLock,
        private readonly WatchTransition $transition,
        #[Autowire('%kernel.project_dir%')]
        private readonly string          $projectDir,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Optimizer — watch');

        if (!$this->watchLock->hold()) {
            $io->error('Another asset-optimizer:watch is already running.');

            return Command::FAILURE;
        }

        $io->writeln('Watching <info>assets/</info>. Optimized images + WebP are served in dev while this runs.');
        $io->writeln('<comment>Ctrl-C to stop.</comment>');
        $io->newLine();

        // Digests flip to their optimized variants now that the lock is held;
        // drop cached raw-digest entries so web requests recompute them.
        $this->transition->toHeld();

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

        $this->watchLock->release();
        // Back to raw-original digests in dev.
        $this->transition->toReleased();

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
