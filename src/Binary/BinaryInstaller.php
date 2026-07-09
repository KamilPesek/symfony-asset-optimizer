<?php

declare(strict_types=1);

namespace AssetOptimizer\Binary;

use PharData;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ZipArchive;
use function in_array;
use const PHP_OS_FAMILY;

/**
 * Downloads and caches standalone tool binaries (see the {@see Tool} enum)
 * into var/asset-optimizer/ on first use — the "download a binary, no npm"
 * model of symfonycasts/sass-bundle. No Node, no node_modules.
 */
final class BinaryInstaller
{
    private ?OutputInterface $output = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string              $projectDir,
        private readonly HttpClientInterface $httpClient,
    )
    {
    }

    public function setOutput(?OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function path(Tool $tool): string
    {
        $dir = $this->projectDir . '/var/asset-optimizer';
        $binary = $dir . '/' . $tool->value . ('Windows' === PHP_OS_FAMILY ? '.exe' : '');

        if (!is_file($binary)) {
            $this->download($tool, $dir, $binary);
        }

        return $binary;
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

        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create "%s".', $dir));
        }

        $work = $dir . '/.' . $tool->value . '-download';
        @mkdir($work, 0o777, true);
        $archive = $work . '/archive.' . $ext;

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
        foreach ($this->httpClient->stream($response) as $chunk) {
            fwrite($handle, $chunk->getContent());
        }
        fclose($handle);
        $progress?->finish();
        $this->output?->writeln('');

        if ('zip' === $ext) {
            $zip = new ZipArchive();
            $zip->open($archive);
            $zip->extractTo($work);
            $zip->close();
        } else {
            new PharData($archive)->extractTo($work, null, true);
        }

        // Locate the binary by name anywhere inside the extracted tree.
        $name = basename($binary);
        $found = null;
        foreach (new Finder()->files()->in($work)->name($name) as $file) {
            $found = $file->getRealPath();
            break;
        }
        if (null === $found) {
            $this->rrmdir($work);
            throw new RuntimeException(sprintf('"%s" not found in %s.', $name, $url));
        }

        copy($found, $binary);
        @chmod($binary, 0o755);
        $this->rrmdir($work);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new Finder()->in($dir)->depth('< 100')->reverseSorting() as $item) {
            $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
        }
        @rmdir($dir);
    }
}
