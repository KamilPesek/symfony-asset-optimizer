<?php

declare(strict_types=1);

namespace AssetOptimizer\Factory;

use AssetOptimizer\WatchMode;
use Symfony\Component\AssetMapper\Factory\CachedMappedAssetFactory;
use Symfony\Component\AssetMapper\Factory\MappedAssetFactoryInterface;
use Symfony\Component\AssetMapper\MappedAsset;

/**
 * Gives watch compiles their own MappedAsset cache namespace
 * (`asset_mapper-watch` next to the framework's `asset_mapper`).
 *
 * Dev web requests (raw digests) and asset-optimizer:watch compiles
 * (optimized digests — see {@see WatchMode}) share kernel.cache_dir, and the
 * cache is keyed on source mtime only, so in a single namespace each state
 * would trust and serve the other's entries. A namespace per state keeps both
 * self-consistent with no clearing, no races, and incremental watch
 * recompiles: unchanged assets stay cache hits instead of being re-optimized.
 *
 * Installed by swapping the class of FrameworkBundle's
 * asset_mapper.cached_mapped_asset_factory definition — constructor signatures
 * match, so the framework's own wiring is reused unchanged (see
 * {@see \AssetOptimizer\DependencyInjection\WatchScopedAssetCachePass}).
 */
final readonly class WatchScopedMappedAssetFactory implements MappedAssetFactoryInterface
{
    private CachedMappedAssetFactory $inner;

    public function __construct(MappedAssetFactoryInterface $innerFactory, string $cacheDir, bool $debug)
    {
        $this->inner = new CachedMappedAssetFactory(
            $innerFactory,
            $cacheDir . (WatchMode::active() ? '-watch' : ''),
            $debug,
        );
    }

    public function createMappedAsset(string $logicalPath, string $sourcePath): ?MappedAsset
    {
        return $this->inner->createMappedAsset($logicalPath, $sourcePath);
    }
}
