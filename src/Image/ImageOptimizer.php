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
 * Image optimization + WebP via standalone binaries (cwebp, oxipng), with a
 * pure-PHP GD fallback if a binary is unavailable or fails.
 *
 * - JPEG: GD re-encode (no downloadable mozjpeg).
 * - PNG:  oxipng (lossless, far better than GD), GD fallback.
 * - WebP: cwebp with max effort (-m 6), GD fallback.
 */
final readonly class ImageOptimizer
{
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
     * Encode an image file as WebP. Returns the bytes, or null on failure.
     */
    public function webp(string $sourcePath, int $quality): ?string
    {
        try {
            $out = $this->fs->tempnam(sys_get_temp_dir(), 'ao_', '.webp');
            try {
                $this->run([$this->binaries->path(Tool::Cwebp), '-quiet', '-m', '6', '-q', (string) $quality, $sourcePath, '-o', $out]);
                $bytes = @file_get_contents($out);
                if (false !== $bytes && '' !== $bytes) {
                    return $bytes;
                }
            } finally {
                @unlink($out);
            }
        } catch (Throwable) {
            // fall through to GD
        }

        $content = @file_get_contents($sourcePath);

        return false === $content ? null : $this->gd->webp($content, $quality);
    }

    private function oxipng(string $content): ?string
    {
        try {
            // Piped via stdin/stdout — no temp files.
            $bytes = $this->run(
                [$this->binaries->path(Tool::Oxipng), '-o', 'max', '--strip', 'safe', '-q', '--stdout', '-'],
                $content,
            );
            if ('' !== $bytes) {
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
