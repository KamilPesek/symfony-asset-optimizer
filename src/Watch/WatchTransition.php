<?php

declare(strict_types=1);

namespace AssetOptimizer\Watch;

use Symfony\Component\AssetMapper\Path\PublicAssetsFilesystemInterface;
use Symfony\Component\AssetMapper\Path\PublicAssetsPathResolverInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Side effects of the watch lock changing hands, shared by the clean path
 * ({@see \AssetOptimizer\Command\WatchCommand}) and the healing path
 * ({@see \AssetOptimizer\EventListener\WatchTransitionListener}).
 *
 * Two things pin dev to a state and must be reset when the lock flips:
 *
 * - AssetMapper's compiled-asset cache, in both directions: it is keyed on
 *   source freshness only and cannot know the optimization gate changed, so
 *   cached digests would stay in the previous state.
 * - The compiled config JSONs (manifest.json, importmap.json,
 *   entrypoint.*.json), on release only: while they exist in public/assets,
 *   AssetMapper serves the compiled (optimized, and eventually stale) assets
 *   in dev regardless of any compiler gating. The compiled asset *files* are
 *   deliberately left in place — their names are content hashes, so the next
 *   compile reuses the expensive WebP twins and watch restarts stay fast.
 */
final readonly class WatchTransition
{
    public function __construct(
        private WatchLock                         $lock,
        private PublicAssetsFilesystemInterface   $publicAssets,
        private PublicAssetsPathResolverInterface $pathResolver,
        private string                            $cacheDir,
    )
    {
    }

    public function toHeld(): void
    {
        $this->clearAssetMapperCache();
        $this->lock->record(true);
    }

    public function toReleased(): void
    {
        $this->clearAssetMapperCache();

        // getDestinationPath() is the public dir; the compiled files live under
        // the public prefix inside it (resolvePublicPath('') yields "/assets/").
        $dir = $this->publicAssets->getDestinationPath() . rtrim($this->pathResolver->resolvePublicPath(''), '/');
        new Filesystem()->remove([
            $dir . '/manifest.json',
            $dir . '/importmap.json',
            ...glob($dir . '/entrypoint.*.json') ?: [],
        ]);

        $this->lock->record(false);
    }

    /**
     * Removing a missing dir is a no-op, so both directions can share this.
     */
    private function clearAssetMapperCache(): void
    {
        new Filesystem()->remove($this->cacheDir . '/asset_mapper');
    }
}
