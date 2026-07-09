<?php

declare(strict_types=1);

namespace AssetOptimizer\Compiler;

use AssetOptimizer\Minify\Minifier;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\Compiler\AssetCompilerInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Minifies SVG with tdewolff/minify — production only (gated on !kernel.debug),
 * for the same determinism reasons as {@see JsCssMinifyCompiler}.
 */
final readonly class SvgMinifyCompiler implements AssetCompilerInterface
{
    public function __construct(
        private Minifier $minifier,
        #[Autowire('%kernel.debug%')]
        private bool     $debug,
        #[Autowire('%asset_optimizer.svg_enabled%')]
        private bool     $enabled,
    )
    {
    }

    public function supports(MappedAsset $asset): bool
    {
        return $this->enabled &&
            !$this->debug &&
            'svg' === strtolower($asset->publicExtension);
    }

    public function compile(string $content, MappedAsset $asset, AssetMapperInterface $assetMapper): string
    {
        return $this->minifier->minify($content, 'svg');
    }
}
