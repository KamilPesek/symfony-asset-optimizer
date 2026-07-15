<?php

declare(strict_types=1);

namespace AssetOptimizer;

/**
 * The watch marker: asset-optimizer:watch runs its compile subprocesses with
 * {@see self::ENV} set to '1', which flips the dev-gated optimizations
 * (image optimization, WebP/AVIF twins) on for that compile — see
 * {@see \AssetOptimizer\Compiler\ImageOptimizeCompiler} and
 * {@see \AssetOptimizer\Path\TwinFilesystem}.
 */
final class WatchMode
{
    public const string ENV = 'ASSET_OPTIMIZER_WATCH';

    /**
     * Whether the current process is a watch-started compile. Strictly '1',
     * not a presence check: an inherited ASSET_OPTIMIZER_WATCH=0 (or empty)
     * must not flip the dev preview on.
     */
    public static function active(): bool
    {
        return '1' === getenv(self::ENV);
    }

    private function __construct()
    {
    }
}
