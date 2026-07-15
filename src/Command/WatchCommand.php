<?php

declare(strict_types=1);

namespace AssetOptimizer\Command;

use AssetOptimizer\WatchMode;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\Process;
use function defined;
use const PHP_BINARY;
use const SIGINT;
use const SIGTERM;

/**
 * Dev preview watch: recompiles the asset map whenever a source asset changes,
 * so optimized images + WebP twins are visible live in dev.
 *
 * The compile subprocess runs with ASSET_OPTIMIZER_WATCH=1 ({@see WatchMode}),
 * which is what switches {@see \AssetOptimizer\Compiler\ImageOptimizeCompiler}
 * and the WebP twins on in dev — a manual `asset-map:compile` in dev writes
 * raw originals. Watch compiles keep their MappedAsset cache in a separate
 * namespace ({@see \AssetOptimizer\Factory\WatchScopedMappedAssetFactory}), so
 * web requests served while the watch runs can never seed the compile with
 * raw-digest entries, and unchanged assets stay cache hits across compiles. On
 * stop the compiled build (asset files + config JSONs) stays in public/assets
 * and dev keeps serving it — exactly like after a manual `asset-map:compile`
 * in dev, or sass-bundle's var/sass output. Restarting the watch updates it
 * (reusing the expensive WebP twins); deleting public/assets returns dev to
 * live raw serving. Because nothing is cleaned up, dying without a signal
 * handler (kill -9, or Ctrl-C without pcntl) ends in the same state as a clean
 * stop.
 *
 * `asset-map:compile` already runs the sass build (via the sass-bundle's
 * PreAssetsCompileEvent listener, if installed), so this single command covers
 * sass + optimization + WebP. JS/CSS are left unminified in dev, which keeps
 * debugging sane. The compile subprocess output is captured and only shown on
 * failure — this deliberately hides AssetMapper's "debug mode is enabled…"
 * warning while the watch runs and keeps public/assets fresh; the stop message
 * states the frozen state instead.
 */
#[AsCommand(
    name: 'asset-optimizer:watch',
    description: 'Watch assets and recompile (optimize images + WebP, and sass) on change — dev preview.',
)]
final class WatchCommand extends Command implements SignalableCommandInterface
{
    private readonly Filesystem $fs;

    private bool $running = true;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.debug%')]
        private readonly bool   $debug,
    )
    {
        $this->fs = new Filesystem();
        parent::__construct();
    }

    protected function configure(): void
    {
        // Poll tick. Detection latency averages tick/2, and each tick pays one
        // snapshot() stat sweep of assets/, which grows with asset count —
        // very large asset trees may prefer a longer tick.
        $this->addOption('tick', null, InputOption::VALUE_REQUIRED, 'Poll tick in ms', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Optimizer — watch');

        // Clamped so a typo can never produce a usleep(0) busy loop or an
        // hours-long unresponsive tick.
        $tickMs = min(60_000, max(10, (int) $input->getOption('tick')));

        if (!$this->debug) {
            $io->error('asset-optimizer:watch is a dev preview tool and refuses to run with kernel.debug off (e.g. APP_ENV=prod): there is nothing to preview, and its start would clear this environment\'s asset cache. Use asset-map:compile for builds.');

            return Command::FAILURE;
        }

        // Single-instance guard: a flock held for the command's lifetime
        // (FlockStore). The OS releases it on any process death — even
        // SIGKILL — so it can never go stale, unlike a pid or marker file.
        $lockDir = $this->projectDir . '/var/asset-optimizer';
        try {
            $this->fs->mkdir($lockDir, 0o755);
            $lock = new LockFactory(new FlockStore($lockDir))->createLock('asset-optimizer:watch', ttl: null);
            if (!$lock->acquire()) {
                $io->error('Another asset-optimizer:watch is already running.');

                return Command::FAILURE;
            }
        } catch (Exception $e) {
            // mkdir or lock-file creation failed — typically var/ permissions.
            $io->error(sprintf('Cannot acquire the watch lock in "%s" — check permissions. (%s)', $lockDir, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->writeln('Watching <info>assets/</info>. Optimized images + WebP are served in dev while this runs.');
        $io->writeln('<comment>Ctrl-C to stop.</comment>');
        $io->newLine();

        try {
            // Snapshot before compiling (same order as the loop): an edit
            // landing while the initial build runs then differs from it and
            // recompiles on the first tick instead of being silently absorbed.
            $signature = $this->snapshot();
            $this->compile($io, 'initial build');

            while ($this->running) {
                usleep($tickMs * 1000);
                // A signal interrupts the usleep; without this check the loop
                // body would still stat-sweep and possibly run a full compile
                // after Ctrl-C.
                if (!$this->running) {
                    break;
                }
                $next = $this->snapshot();
                if ($next !== $signature) {
                    $signature = $next;
                    $this->compile($io, 'change detected');
                }
            }
        } finally {
            $lock->release();
        }

        $io->newLine();
        $io->writeln('Stopped watching. The optimized build stays in <info>public/assets</info> and dev keeps serving it (frozen, like after any <info>asset-map:compile</info>).');
        $io->writeln('Restart the watch to update it, or delete <info>public/assets</info> to serve raw sources live again.');

        return Command::SUCCESS;
    }

    /**
     * Fingerprint of every source asset (path + mtime + size). Any add, edit, or
     * delete under assets/ changes the string.
     */
    private function snapshot(): string
    {
        $dir = $this->projectDir . '/assets';
        if (!$this->fs->exists($dir)) {
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

        // File-backed opcache for the compile subprocess: every compile is a
        // fresh PHP process that would otherwise re-parse the whole framework
        // each change (~140 ms). A file cache persists compiled scripts across
        // subprocesses (per-process SHM would not) and still validates source
        // timestamps. Harmless no-op when the opcache extension is absent.
        // Recreated per compile so deleting var/ mid-watch self-heals.
        $opcacheDir = $this->projectDir . '/var/asset-optimizer/opcache';
        $this->fs->mkdir($opcacheDir, 0o755);

        $process = new Process(
            [
                PHP_BINARY,
                '-d', 'opcache.enable_cli=1',
                '-d', 'opcache.file_cache=' . $opcacheDir,
                '-d', 'opcache.file_cache_only=1',
                $this->projectDir . '/bin/console', 'asset-map:compile', '--no-interaction',
            ],
            $this->projectDir,
            // Flips the dev-gated optimizations (images, WebP twins) on for
            // this compile — see ImageOptimizeCompiler / WebpTwinFilesystem.
            [WatchMode::ENV => '1'],
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
