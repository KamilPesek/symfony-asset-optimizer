<?php

declare(strict_types=1);

namespace AssetOptimizer\Binary;

use InvalidArgumentException;
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
 * Downloads and caches standalone tool binaries (tdewolff/minify, cwebp, oxipng)
 * into var/asset-optimizer/ on first use — the "download a binary, no npm" model
 * of symfonycasts/sass-bundle. No Node, no node_modules.
 *
 * Each tool's release artifact is named differently per platform, so the mapping
 * lives here; the binary is then located inside the extracted archive by name.
 */
final class BinaryInstaller
{
    /**
     * @var array<string, array{version: string, url: callable}>
     */
    private array $tools;

    private ?OutputInterface $output = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string              $projectDir,
        private readonly HttpClientInterface $httpClient,
    )
    {
        $this->tools = [
            'minify' => [
                'version' => '2.24.13',
                'url' => static fn(string $os, string $arch, string $v): string => sprintf(
                    'https://github.com/tdewolff/minify/releases/download/v%s/minify_%s_%s.%s',
                    $v,
                    'macos' === $os ? 'darwin' : $os,
                    $arch,
                    'windows' === $os ? 'zip' : 'tar.gz',
                ),
            ],
            'cwebp' => [
                'version' => '1.5.0',
                'url' => static function (string $os, string $arch, string $v): string {
                    $slug = match ($os) {
                        'darwin' => 'mac-' . ('arm64' === $arch ? 'arm64' : 'x86-64'),
                        'windows' => 'windows-x64',
                        default => 'linux-' . ('arm64' === $arch ? 'aarch64' : 'x86-64'),
                    };
                    $ext = 'windows' === $os ? 'zip' : 'tar.gz';

                    return sprintf('https://storage.googleapis.com/downloads.webmproject.org/releases/webp/libwebp-%s-%s.%s', $v, $slug, $ext);
                },
            ],
            'oxipng' => [
                'version' => '9.1.5',
                'url' => static function (string $os, string $arch, string $v): string {
                    $a = 'arm64' === $arch ? 'aarch64' : 'x86_64';
                    $target = match ($os) {
                        'darwin' => $a . '-apple-darwin',
                        'windows' => $a . '-pc-windows-msvc',
                        default => $a . '-unknown-linux-musl',
                    };
                    $ext = 'windows' === $os ? 'zip' : 'tar.gz';

                    return sprintf('https://github.com/shssoichiro/oxipng/releases/download/v%s/oxipng-%s-%s.%s', $v, $v, $target, $ext);
                },
            ],
        ];
    }

    public function setOutput(?OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function path(string $tool): string
    {
        if (!isset($this->tools[$tool])) {
            throw new InvalidArgumentException(sprintf('Unknown tool "%s".', $tool));
        }

        $dir = $this->projectDir . '/var/asset-optimizer';
        $binary = $dir . '/' . $tool . ('Windows' === PHP_OS_FAMILY ? '.exe' : '');

        if (!is_file($binary)) {
            $this->download($tool, $dir, $binary);
        }

        return $binary;
    }

    private function download(string $tool, string $dir, string $binary): void
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Windows' => 'windows',
            default => 'linux',
        };
        // Normalized to amd64/arm64; each tool's url() maps to its own naming.
        $arch = in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';

        $url = ($this->tools[$tool]['url'])($os, $arch, $this->tools[$tool]['version']);
        $ext = str_ends_with($url, '.zip') ? 'zip' : 'tar.gz';

        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create "%s".', $dir));
        }

        $work = $dir . '/.' . $tool . '-download';
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
            (new PharData($archive))->extractTo($work, null, true);
        }

        // Locate the binary by name anywhere inside the extracted tree.
        $name = basename($binary);
        $found = null;
        foreach ((new Finder())->files()->in($work)->name($name) as $file) {
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
        foreach ((new Finder())->in($dir)->depth('< 100')->reverseSorting() as $item) {
            $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
        }
        @rmdir($dir);
    }
}
