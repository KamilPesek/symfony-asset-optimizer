<?php

declare(strict_types=1);

namespace AssetOptimizer\Compiler;

use AssetOptimizer\Image\ImageOptimizer;
use AssetOptimizer\Watch\WatchLock;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\Compiler\AssetCompilerInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use function in_array;

/**
 * Optimizes JPEG (GD) and PNG (oxipng) raster assets.
 *
 * Always on for a prod compile. In dev (kernel.debug) it runs only while an
 * asset-optimizer:watch holds the {@see WatchLock}: plain dynamic serving
 * delivers raw originals, and starting the watch flips dev to the prod-like
 * preview. The two dev states produce different content-hash digests by
 * design — each is self-consistent, and the watch-on digests match the files
 * the watch compiles into public/assets, so the web server serves those
 * statically (including the .htaccess WebP rule). The watch clears
 * AssetMapper's dev cache on start so cached raw digests actually flip.
 * Never enlarges (see {@see ImageOptimizer::optimize()}).
 */
final class ImageOptimizeCompiler implements AssetCompilerInterface
{
    private const array EXTENSIONS = ['jpg', 'jpeg', 'png'];

    public function __construct(
        private readonly ImageOptimizer $optimizer,
        private readonly WatchLock      $watchLock,
        #[Autowire('%kernel.debug%')]
        private readonly bool           $debug,
        #[Autowire('%asset_optimizer.jpg_png_enabled%')]
        private readonly bool           $enabled,
        #[Autowire('%asset_optimizer.jpg_png_quality%')]
        private readonly int            $quality,
    )
    {
    }

    public function supports(MappedAsset $asset): bool
    {
        if (!$this->enabled || !in_array(strtolower($asset->publicExtension), self::EXTENSIONS, true)) {
            return false;
        }

        // Dev serves raw originals unless a watch is running (prod-like preview).
        return !$this->debug || $this->watchLock->isHeld();
    }

    public function compile(string $content, MappedAsset $asset, AssetMapperInterface $assetMapper): string
    {
        return $this->optimizer->optimize($content, strtolower($asset->publicExtension), $this->quality);
    }
}
