<?php

declare(strict_types=1);

namespace AssetOptimizer\Image;

use AssetOptimizer\Binary\BinaryInstaller;
use AssetOptimizer\Binary\Tool;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;
use function strlen;

/**
 * Image optimization + WebP/AVIF via standalone binaries (cwebp, avifenc,
 * oxipng).
 *
 * - JPEG: GD re-encode (no downloadable mozjpeg).
 * - PNG:  oxipng (lossless, far better than GD), GD fallback.
 * - WebP: cwebp with max effort (-m 6), binary-only.
 * - AVIF: avifenc, binary-only.
 *
 * Twin encodes (WebP/AVIF) have no GD fallback: GD output is notably larger
 * than the pinned binaries', and a GD-derived twin or rejection verdict would
 * persist in public/assets long after the binary becomes available. A missing
 * binary just skips the twin; the next compile retries.
 */
final readonly class ImageOptimizer
{
    /** First 8 bytes of every PNG. */
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** Last 8 bytes of every PNG: the zero-length IEND chunk + its fixed CRC. */
    private const string PNG_TRAILER = "IEND\xae\x42\x60\x82";

    private Filesystem $fs;

    public function __construct(
        private BinaryInstaller  $binaries,
        private GdImageProcessor $gd,
    )
    {
        $this->fs = new Filesystem();
    }

    /**
     * Optimize JPEG/PNG bytes. Returns the smaller of optimized/original.
     */
    public function optimize(string $content, string $ext, int $quality): string
    {
        $optimized = 'png' === $ext
            ? $this->oxipng($content)
            : $this->gd->optimize($content, $ext, $quality);

        return null !== $optimized && 0 < strlen($optimized) && strlen($optimized) < strlen($content)
            ? $optimized
            : $content;
    }

    /**
     * Encode an image file as WebP via cwebp. Returns the bytes, or null when
     * the binary is unavailable or fails.
     */
    public function webp(string $sourcePath, int $quality): ?string
    {
        try {
            // Piped via stdout ('-o -') — no temp files.
            $bytes = $this->run([$this->binaries->path(Tool::Cwebp), '-quiet', '-m', '6', '-q', (string) $quality, $sourcePath, '-o', '-']);
        } catch (Throwable) {
            return null;
        }

        return '' !== $bytes ? $bytes : null;
    }

    /**
     * Encode an image file as AVIF via avifenc. Returns the bytes, or null
     * when the binary is unavailable or fails.
     */
    public function avif(string $sourcePath, int $quality): ?string
    {
        try {
            // Resolve the binary before any filesystem work: an unavailable
            // avifenc must not cost a temp-file create/remove per candidate.
            $avifenc = $this->binaries->path(Tool::Avifenc);

            // avifenc cannot write to stdout, so encode via a temp file.
            $tmp = $this->fs->tempnam(sys_get_temp_dir(), 'asset-optimizer-', '.avif');
            try {
                $this->run([$avifenc, '-q', (string) $quality, '--jobs', 'all', $sourcePath, '-o', $tmp]);
                $bytes = $this->fs->readFile($tmp);
            } finally {
                $this->fs->remove($tmp);
            }
        } catch (Throwable) {
            return null;
        }

        return '' !== $bytes ? $bytes : null;
    }

    private function oxipng(string $content): ?string
    {
        try {
            // Piped via stdin/stdout — no temp files.
            $bytes = $this->run(
                [$this->binaries->path(Tool::Oxipng), '-o', 'max', '--strip', 'safe', '-q', '--stdout', '-'],
                $content,
            );
            // Trust stdout only if it looks like a complete PNG: require both
            // the signature and the IEND trailer. A stream cut short (broken
            // pipe, killed mid-write) that still exits 0 keeps the signature but
            // loses the trailer; without this it would pass the caller's
            // "smaller than the source" check and publish a truncated PNG.
            if (str_starts_with($bytes, self::PNG_SIGNATURE) && str_ends_with($bytes, self::PNG_TRAILER)) {
                return $bytes;
            }
        } catch (Throwable) {
            // fall through to GD
        }

        return $this->gd->optimize($content, 'png', 9);
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, ?string $input = null): string
    {
        $process = new Process($command);
        $process->setInput($input);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'image tool failed');
        }

        return $process->getOutput();
    }
}
