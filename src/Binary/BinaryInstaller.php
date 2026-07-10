<?php

declare(strict_types=1);

namespace AssetOptimizer\Binary;

use PharData;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
use ZipArchive;
use function in_array;
use function strlen;
use const PHP_OS_FAMILY;

/**
 * Downloads and caches standalone tool binaries (see the {@see Tool} enum)
 * into var/asset-optimizer/ on first use — the "download a binary, no npm"
 * model of symfonycasts/sass-bundle. No Node, no node_modules.
 *
 * Concurrency-safe: each download extracts into its own unique work directory
 * and the finished binary is moved into place with an atomic rename, so
 * parallel first-use compiles never see a partial binary.
 */
final class BinaryInstaller
{
    private ?OutputInterface $output = null;

    private readonly Filesystem $fs;

    /** @var array<string, string> resolved binary paths, memoized for the process */
    private array $resolved = [];

    /** @var array<string, true> tools whose download failed this process — not retried */
    private array $failed = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string              $projectDir,
        private readonly HttpClientInterface $httpClient,
    )
    {
        $this->fs = new Filesystem();
    }

    public function setOutput(?OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function path(Tool $tool): string
    {
        // A compile touches many assets of the same type; resolving the path
        // once avoids a redundant stat per asset for an already-known binary.
        if (isset($this->resolved[$tool->value])) {
            return $this->resolved[$tool->value];
        }

        $dir = $this->projectDir . '/var/asset-optimizer';
        $binary = $dir . '/' . $tool->value . ('Windows' === PHP_OS_FAMILY ? '.exe' : '');

        if (is_file($binary)) {
            return $this->resolved[$tool->value] = $binary;
        }

        // A download that already failed this process is not retried: the whole
        // compile touches many assets of the same type, and without this every
        // one would re-attempt the (still failing) download and block on it.
        if (isset($this->failed[$tool->value])) {
            throw new RuntimeException(sprintf('"%s" is unavailable (download failed earlier this run).', $tool->value));
        }

        try {
            $this->download($tool, $dir, $binary);
        } catch (Throwable $e) {
            $this->failed[$tool->value] = true;
            $this->output?->writeln(sprintf(
                '<comment>Asset Optimizer:</comment> "%s" unavailable (%s) — affected assets ship without it.',
                $tool->value,
                $e->getMessage(),
            ));

            throw $e;
        }

        return $this->resolved[$tool->value] = $binary;
    }

    private function download(Tool $tool, string $dir, string $binary): void
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Windows' => 'windows',
            default => 'linux',
        };
        // Normalized to amd64/arm64; each tool's url() maps to its own naming.
        $arch = in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';

        $url = $tool->url($os, $arch);
        $ext = str_ends_with($url, '.zip') ? 'zip' : 'tar.gz';

        // Unique per attempt so concurrent processes never share extraction state.
        $work = $dir . '/.' . $tool->value . '-' . bin2hex(random_bytes(8));
        $this->fs->mkdir($work, 0o755);

        try {
            $archive = $work . '/archive.' . $ext;
            $this->fetch($url, $archive);
            $this->extract($archive, $ext, $work);

            // Locate the binary by name anywhere inside the extracted tree.
            $name = basename($binary);
            $found = null;
            foreach (new Finder()->files()->in($work)->name($name)->size('> 0') as $file) {
                $found = $file->getRealPath();
                break;
            }
            if (null === $found) {
                throw new RuntimeException(sprintf('"%s" not found in %s.', $name, $url));
            }

            // chmod before the rename so the binary appears complete and
            // executable in one atomic step.
            $this->fs->chmod($found, 0o755);
            try {
                $this->fs->rename($found, $binary, true);
            } catch (IOException $e) {
                // A parallel first-use compile won the race and placed the
                // binary first (rename-over-existing is not atomic on Windows,
                // so it throws here). Its result is equivalent to ours — accept
                // it rather than failing the build.
                if (!is_file($binary)) {
                    throw $e;
                }
            }
        } finally {
            // Best-effort cleanup: a locked leftover (e.g. AV still scanning an
            // extracted file on Windows) must never fail an otherwise-successful
            // install. The unique work-dir name means a stray dir collides with
            // nothing on a later run.
            try {
                $this->fs->remove($work);
            } catch (IOException) {
                // leave the work dir; it is harmless
            }
        }
    }

    private function fetch(string $url, string $archive): void
    {
        // Stream the response straight to disk (never buffer the whole archive in
        // memory) with a progress bar when a console output is available.
        $this->output?->writeln(sprintf('<info>Asset Optimizer:</info> downloading %s…', basename($url)));
        $progress = null;
        $response = $this->httpClient->request('GET', $url, [
            'on_progress' => function (int $dlNow, int $dlSize) use (&$progress): void {
                if ($dlSize <= 0 || null === $this->output) {
                    return;
                }
                $progress ??= new ProgressBar($this->output, $dlSize);
                $progress->setProgress($dlNow);
            },
        ]);

        $handle = fopen($archive, 'w');
        if (false === $handle) {
            throw new RuntimeException(sprintf('Cannot write "%s".', $archive));
        }
        try {
            foreach ($this->httpClient->stream($response) as $chunk) {
                $bytes = $chunk->getContent();
                if (strlen($bytes) !== fwrite($handle, $bytes)) {
                    throw new RuntimeException(sprintf('Short write to "%s" (disk full?).', $archive));
                }
            }
        } finally {
            // Always release the handle so the caller's cleanup (and Windows,
            // which locks open files) can remove the work directory.
            fclose($handle);
        }
        $progress?->finish();
        $this->output?->writeln('');
    }

    private function extract(string $archive, string $ext, string $work): void
    {
        if ('tar.gz' === $ext) {
            new PharData($archive)->extractTo($work, null, true);

            return;
        }

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The "zip" PHP extension is required to extract .zip tool archives.');
        }
        $zip = new ZipArchive();
        if (true !== $zip->open($archive)) {
            throw new RuntimeException(sprintf('Cannot open "%s".', $archive));
        }
        try {
            if (!$zip->extractTo($work)) {
                throw new RuntimeException(sprintf('Cannot extract "%s".', $archive));
            }
        } finally {
            $zip->close();
        }
    }
}
