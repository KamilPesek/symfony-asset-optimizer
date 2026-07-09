<?php

declare(strict_types=1);

namespace AssetOptimizer\Compiler;

use AssetOptimizer\Image\ImageOptimizer;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\Compiler\AssetCompilerInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use function in_array;

/**
 * Optimizes JPEG (GD) and PNG (oxipng) raster assets.
 *
 * Runs in every context (dev serve, dev watch, prod build) so an image's
 * compiled bytes — and therefore its content-hash digest — are deterministic.
 * AssetMapper caches compiled assets, so it runs once per image until its
 * source changes. Never enlarges (see {@see ImageOptimizer::optimize()}).
 */
final class ImageOptimizeCompiler implements AssetCompilerInterface
{
    private const array EXTENSIONS = ['jpg', 'jpeg', 'png'];

    public function __construct(
        private readonly ImageOptimizer $optimizer,
        #[Autowire('%asset_optimizer.images_enabled%')]
        private readonly bool           $enabled,
        #[Autowire('%asset_optimizer.image_quality%')]
        private readonly int            $quality,
    )
    {
    }

    public function supports(MappedAsset $asset): bool
    {
        return $this->enabled && in_array(strtolower($asset->publicExtension), self::EXTENSIONS, true);
    }

    public function compile(string $content, MappedAsset $asset, AssetMapperInterface $assetMapper): string
    {
        return $this->optimizer->optimize($content, strtolower($asset->publicExtension), $this->quality);
    }
}
