<?php

declare(strict_types=1);

namespace AssetOptimizer\Compiler;

use AssetOptimizer\Minify\Minifier;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\Compiler\AssetCompilerInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Minifies JS, CSS and SVG with tdewolff/minify — production only (gated on
 * !kernel.debug).
 *
 * In dev this is always off, so assets stay debuggable and their digests are
 * deterministic. In prod AssetMapper only runs compilers during a compile (there
 * is no dynamic serving), so no further gating is needed.
 *
 * Registered at a very low priority so it runs after the sass compiler and
 * AssetMapper's JS import-path rewriting — it minifies the final content.
 */
final readonly class MinifyCompiler implements AssetCompilerInterface
{
    public function __construct(
        private Minifier $minifier,
        #[Autowire('%kernel.debug%')]
        private bool     $debug,
        #[Autowire('%asset_optimizer.js_enabled%')]
        private bool     $jsEnabled,
        #[Autowire('%asset_optimizer.css_enabled%')]
        private bool     $cssEnabled,
        #[Autowire('%asset_optimizer.svg_enabled%')]
        private bool     $svgEnabled,
        /** @var list<string> */
        #[Autowire('%asset_optimizer.ignore_paths%')]
        private array    $ignorePaths,
    )
    {
    }

    public function supports(MappedAsset $asset): bool
    {
        if ($this->debug) {
            return false;
        }

        $enabled = match (strtolower($asset->publicExtension)) {
            'js' => $this->jsEnabled,
            'css' => $this->cssEnabled, // covers sass output whose public extension is css
            'svg' => $this->svgEnabled,
            default => false,
        };

        if (!$enabled) {
            return false;
        }

        return !array_any(
            $this->ignorePaths,
            static fn(string $pattern): bool => fnmatch($pattern, $asset->logicalPath),
        );
    }

    public function compile(string $content, MappedAsset $asset, AssetMapperInterface $assetMapper): string
    {
        // supports() guarantees the extension is js, css or svg — all valid
        // `minify --type` values.
        return $this->minifier->minify($content, strtolower($asset->publicExtension));
    }
}
