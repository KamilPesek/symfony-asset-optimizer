<?php

declare(strict_types=1);

namespace AssetOptimizer\Image;

use GdImage;

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
            if ('png' === $ext) {
                imagealphablending($img, false);
                imagesavealpha($img, true);

                // GD's PNG quality argument is the zlib compression level (0-9).
                return self::capture($img, static fn (GdImage $i) => imagepng($i, null, 9));
            }

            return self::capture($img, static fn (GdImage $i) => imagejpeg($i, null, $quality));
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
        return $this->encodeTruecolor($content, static fn (GdImage $img) => imagewebp($img, null, $quality));
    }

    /**
     * Encodes any supported raster as AVIF at the given quality (alpha
     * preserved). Returns the bytes, or null on failure — including when this
     * GD build ships without AVIF support (common; needs libavif compiled in).
     */
    public function avif(string $content, int $quality): ?string
    {
        if (!function_exists('imageavif')) {
            return null;
        }

        return $this->encodeTruecolor($content, static fn (GdImage $img) => imageavif($img, null, $quality));
    }

    /**
     * Shared twin-encode scaffold: truecolor conversion + alpha preservation,
     * then the format-specific encoder. One copy of the prep policy, so WebP
     * and AVIF twins can never silently diverge.
     *
     * @param callable(GdImage): bool $encode
     */
    private function encodeTruecolor(string $content, callable $encode): ?string
    {
        $img = @imagecreatefromstring($content);
        if (false === $img) {
            return null;
        }

        try {
            imagepalettetotruecolor($img);
            imagealphablending($img, false);
            imagesavealpha($img, true);

            return self::capture($img, $encode);
        } finally {
            imagedestroy($img);
        }
    }

    /**
     * Runs a GD encoder that writes to stdout and captures its bytes. The
     * buffer is closed in a finally so a throwing encoder (GD warning promoted
     * to an exception by the dev ErrorHandler) cannot leak an output-buffer
     * level into the long-running compile process.
     *
     * @param callable(GdImage): bool $encode
     */
    private static function capture(GdImage $img, callable $encode): ?string
    {
        ob_start();
        try {
            $encode($img);
        } finally {
            $out = ob_get_clean();
        }

        // A failed capture (false) or empty string means "no image produced".
        return false === $out || '' === $out ? null : $out;
    }
}
