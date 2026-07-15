<?php

declare(strict_types=1);

namespace AssetOptimizer\DependencyInjection;

use AssetOptimizer\Factory\WatchScopedMappedAssetFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Swaps the class of FrameworkBundle's asset_mapper.cached_mapped_asset_factory
 * for {@see WatchScopedMappedAssetFactory}, which scopes the cache dir per
 * watch state. A class swap (the constructors match) reuses the framework's
 * own wiring — inner factory, cache dir, debug — untouched.
 */
final class WatchScopedAssetCachePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('asset_mapper.cached_mapped_asset_factory')) {
            $container->getDefinition('asset_mapper.cached_mapped_asset_factory')
                ->setClass(WatchScopedMappedAssetFactory::class);
        }
    }
}
