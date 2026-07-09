<?php

declare(strict_types=1);

namespace AssetOptimizer\Binary;

/**
 * The tools — pinned versions and per-platform release-artifact naming, all
 * in one place. Each tool's release artifact is named differently per
 * platform, so the mapping lives here. The backing value is the executable's
 * file name, used to locate it in the archive and cache it under
 * var/asset-optimizer/ (Windows ".exe" suffix appended automatically).
 */
enum Tool: string
{
    case Minify = 'minify';
    case Cwebp = 'cwebp';
    case Oxipng = 'oxipng';

    public function version(): string
    {
        return match ($this) {
            self::Minify => '2.24.13',
            self::Cwebp => '1.5.0',
            self::Oxipng => '9.1.5',
        };
    }

    /**
     * Download URL of the release archive (".zip" or ".tar.gz").
     *
     * @param string $os   "linux", "darwin" or "windows"
     * @param string $arch "amd64" or "arm64"
     */
    public function url(string $os, string $arch): string
    {
        return match ($this) {
            self::Minify => sprintf(
                'https://github.com/tdewolff/minify/releases/download/v%s/minify_%s_%s.%s',
                $this->version(),
                $os,
                $arch,
                'windows' === $os ? 'zip' : 'tar.gz',
            ),
            self::Cwebp => sprintf(
                'https://storage.googleapis.com/downloads.webmproject.org/releases/webp/libwebp-%s-%s.%s',
                $this->version(),
                match ($os) {
                    'darwin' => 'mac-' . ('arm64' === $arch ? 'arm64' : 'x86-64'),
                    'windows' => 'windows-x64',
                    default => 'linux-' . ('arm64' === $arch ? 'aarch64' : 'x86-64'),
                },
                'windows' === $os ? 'zip' : 'tar.gz',
            ),
            self::Oxipng => sprintf(
                'https://github.com/shssoichiro/oxipng/releases/download/v%s/oxipng-%s-%s.%s',
                $this->version(),
                $this->version(),
                ('arm64' === $arch ? 'aarch64' : 'x86_64') . match ($os) {
                    'darwin' => '-apple-darwin',
                    'windows' => '-pc-windows-msvc',
                    default => '-unknown-linux-musl',
                },
                'windows' === $os ? 'zip' : 'tar.gz',
            ),
        };
    }
}
