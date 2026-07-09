<?php

declare(strict_types=1);

namespace AssetOptimizer\Image;

/**
 * Pure-PHP image processing via the GD extension — no external binary, no npm.
 */
final class GdImageProcessor
{
    /**
     * Re-encodes a JPEG/PNG at the given quality. Returns the bytes, or null on
     * failure (caller keeps the original).
     */
    public function optimize(string $content, string $ext, int $quality): ?string
    {
        $img = @imagecreatefromstring($content);
        if (false === $img) {
            return null;
        }

        try {
            ob_start();
            if ('png' === $ext) {
                imagealphablending($img, false);
                imagesavealpha($img, true);
                // GD's PNG quality argument is the zlib compression level (0-9).
                imagepng($img, null, 9);
            } else {
                imagejpeg($img, null, $quality);
            }
            $out = ob_get_clean();

            return false === $out ? null : $out;
        } finally {
            imagedestroy($img);
        }
    }

    /**
     * Encodes any supported raster as WebP at the given quality (alpha preserved).
     * Returns the bytes, or null on failure.
     */
    public function webp(string $content, int $quality): ?string
    {
        $img = @imagecreatefromstring($content);
        if (false === $img) {
            return null;
        }

        try {
            imagepalettetotruecolor($img);
            imagealphablending($img, false);
            imagesavealpha($img, true);

            ob_start();
            imagewebp($img, null, $quality);
            $out = ob_get_clean();

            return false === $out ? null : $out;
        } finally {
            imagedestroy($img);
        }
    }
}
