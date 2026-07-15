<?php

declare(strict_types=1);

namespace AssetOptimizer\Binary;

use RuntimeException;

/**
 * The tools — pinned versions, per-platform release-artifact naming, and the
 * artifacts' SHA-256 checksums, all in one place. Each tool's release artifact
 * is named differently per platform, so the mapping lives here. The backing
 * value is the executable's file name, used to locate it in the archive and
 * cache it under var/asset-optimizer/ (Windows ".exe" suffix appended
 * automatically).
 */
enum Tool: string
{
    /**
     * SHA-256 of every pinned release artifact, keyed by "<tool> <version>",
     * then artifact file name. The version lives in the outer key (not the
     * file name — some upstreams, e.g. libavif, ship generic names), so
     * bumping {@see version()} always fails closed until the new hashes are
     * recorded here, and equal file names across tools cannot collide. A
     * platform combination without an entry has no artifact upstream and
     * fails before any download.
     */
    private const array CHECKSUMS = [
        'minify 2.24.13' => [
            'minify_linux_amd64.tar.gz' => '6cdd5c8c1d605d60f48a2f4a654595235f1fb50f804b107f72f6a6c87240a1bd',
            'minify_linux_arm64.tar.gz' => '927bfbb693985a4671618ff19cd331f348c0a6b79ccd23a04cd9a58e70ce8221',
            'minify_darwin_amd64.tar.gz' => 'bc5e36e17c7d6e49fa5601b2ba3b211709f06270efb56d6352fa37510e033a74',
            'minify_darwin_arm64.tar.gz' => '9b70745adaeba8d2eb14f5e4fc24d63d0ac95c74e329d64c7a4664157da8dbf1',
            'minify_windows_amd64.zip' => '3e534953648244fb9b9988545db0e7684204a2d0ef6d4c9a9a1213439f2e0a8c',
        ],
        'cwebp 1.5.0' => [
            'libwebp-1.5.0-linux-x86-64.tar.gz' => 'f4bf49f85991f50e86a5404d16f15b72a053bb66768ed5cc0f6d042277cc2bb8',
            'libwebp-1.5.0-linux-aarch64.tar.gz' => 'ec874dd2e52097f11dd4999176ae6b6fb0ecb165bc1e05d3ecc9b29014fb003e',
            'libwebp-1.5.0-mac-x86-64.tar.gz' => 'f8917c9d193dd13ae2266272e1b491436a846fc328922affad572ec0e8d73d87',
            'libwebp-1.5.0-mac-arm64.tar.gz' => '0fa3d7fb64a2b2849115c2f1052b7068b3fbadcb7263e3f1840fcd8c798ea9c2',
            'libwebp-1.5.0-windows-x64.zip' => 'e8fe3bc7eb09774e69261a42bf9fa8a37ab5f3eecaab199f6420e6f9e822090c',
        ],
        'oxipng 9.1.5' => [
            'oxipng-9.1.5-x86_64-unknown-linux-musl.tar.gz' => '571e69fcb06a3675fe247998d845619075631b01ac2246e8a88206c723a472e2',
            'oxipng-9.1.5-aarch64-unknown-linux-musl.tar.gz' => '5c6c1ed8d0b2817a5800c89acdf16d64874b6a001634aea26edc5a2b7dbef29a',
            'oxipng-9.1.5-x86_64-apple-darwin.tar.gz' => 'c59ca46fe281e95ca2728ca9950096f1099f0776ff4c7eeafae84fdedb26f737',
            'oxipng-9.1.5-aarch64-apple-darwin.tar.gz' => 'a3fbb890c934ca785302d8533d5f076c053cc61946d52b728bca5df7f47cb2e8',
            'oxipng-9.1.5-x86_64-pc-windows-msvc.zip' => 'd53981683d8b76f3f3e45410158b4bc3bd78f7d936e3620de4b1ea56c9dffa38',
        ],
        // libavif (avifenc) — upstream ships generic per-OS artifact names.
        'avifenc 1.4.2' => [
            'linux-artifacts.zip' => 'faf58a670ffbfdc0e3559e6d37592cff277c447dd39453f1cd1d7d7f5a20b8ef',
            'macOS-artifacts.zip' => '41f9a3db7b7697aa4f9c83d5e07a1b2e00f28f23676d3f27698eef766689a6b6',
            'windows-artifacts.zip' => 'cb2d9fea43dcbab1d0707e3b37eb7b08070ad2fb60a2c188c39ec12382c0484a',
        ],
    ];

    case Minify = 'minify';
    case Cwebp = 'cwebp';
    case Oxipng = 'oxipng';
    case Avifenc = 'avifenc';

    public function version(): string
    {
        return match ($this) {
            self::Minify => '2.24.13',
            self::Cwebp => '1.5.0',
            self::Oxipng => '9.1.5',
            self::Avifenc => '1.4.2',
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
            self::Avifenc => sprintf(
                'https://github.com/AOMediaCodec/libavif/releases/download/v%s/%s-artifacts.zip',
                $this->version(),
                // Upstream ships one artifact per OS, each holding a single
                // architecture (linux: x86-64 glibc, macOS: arm64, windows:
                // x64). The archive names carry no arch, so the missing
                // combinations must fail fast here instead of in sha256().
                match (true) {
                    'linux' === $os && 'amd64' === $arch => 'linux',
                    'darwin' === $os && 'arm64' === $arch => 'macOS',
                    'windows' === $os && 'amd64' === $arch => 'windows',
                    default => throw new RuntimeException(sprintf('No release artifact of "%s" for %s/%s.', $this->value, $os, $arch)),
                },
            ),
        };
    }

    /**
     * Pinned SHA-256 of the release archive that {@see url()} points to.
     *
     * @param string $os   "linux", "darwin" or "windows"
     * @param string $arch "amd64" or "arm64"
     */
    public function sha256(string $os, string $arch): string
    {
        $file = basename($this->url($os, $arch));

        return self::CHECKSUMS[$this->value . ' ' . $this->version()][$file] ?? throw new RuntimeException(sprintf('No release artifact of "%s" for %s/%s ("%s").', $this->value, $os, $arch, $file));
    }
}
