<?php

declare(strict_types=1);

namespace AssetOptimizer\Watch;

use Symfony\Component\AssetMapper\AssetMapper;
use Symfony\Component\AssetMapper\ImportMap\ImportMapGenerator;
use Symfony\Component\AssetMapper\Path\PublicAssetsFilesystemInterface;
use Symfony\Component\AssetMapper\Path\PublicAssetsPathResolverInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

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
 *   in dev regardless of any compiler gating. They are removed only when the
 *   marker stamped by {@see recordCompileSource()} proves a watch compile
 *   wrote them — a deliberate `asset-map:compile` (say, a prod build on the
 *   same checkout) clears the marker, so healing can never destroy its output.
 *   The compiled asset *files* are deliberately left in place — their names
 *   are content hashes, so the next compile reuses the expensive WebP twins
 *   and watch restarts stay fast.
 *
 * Both transitions record their state *before* acting and do nothing when the
 * record cannot be written (returning false): an unrecordable heal would leave
 * the held/recorded mismatch in place and re-run the side effects on every
 * request.
 */
final readonly class WatchTransition
{
    /**
     * Present in public/assets while the config JSONs there were written by a
     * watch compile; refreshed by every asset-map:compile via
     * {@see recordCompileSource()}.
     */
    private const string WATCH_MARKER = '.asset-optimizer-watch';

    private Filesystem $fs;

    public function __construct(
        private WatchLock                         $lock,
        private PublicAssetsFilesystemInterface   $publicAssets,
        private PublicAssetsPathResolverInterface $pathResolver,
        private string                            $cacheDir,
    )
    {
        $this->fs = new Filesystem();
    }

    public function toHeld(): bool
    {
        if (!$this->lock->record(true)) {
            return false;
        }
        $this->clearAssetMapperCache();

        return true;
    }

    public function toReleased(): bool
    {
        if (!$this->lock->record(false)) {
            return false;
        }
        $this->clearAssetMapperCache();

        $dir = $this->publicAssetsDir();
        if (!$this->fs->exists($dir . '/' . self::WATCH_MARKER)) {
            return true; // JSONs (if any) come from a deliberate compile — not ours to remove
        }

        $entrypoints = [];
        foreach (new Finder()->files()->in($dir)->depth(0)->name(str_replace('%s', '*', ImportMapGenerator::ENTRYPOINT_CACHE_FILENAME_PATTERN)) as $file) {
            $entrypoints[] = $file->getPathname();
        }
        $this->fs->remove([
            $dir . '/' . AssetMapper::MANIFEST_FILE_NAME,
            $dir . '/' . ImportMapGenerator::IMPORT_MAP_CACHE_FILENAME,
            $dir . '/' . self::WATCH_MARKER,
            ...$entrypoints,
        ]);

        return true;
    }

    /**
     * Stamps the compile that is about to run with its source: marker present
     * while a watch drives compiles, gone after any compile without one. Runs
     * in every environment (a prod build on a dev checkout must clear the
     * marker) and is best-effort — a failure only widens or narrows what the
     * next {@see toReleased()} cleans, it must never break the compile itself.
     */
    public function recordCompileSource(): void
    {
        $dir = $this->publicAssetsDir();
        try {
            if ($this->lock->isHeld()) {
                $this->fs->mkdir($dir);
                $this->fs->touch($dir . '/' . self::WATCH_MARKER);
            } else {
                $this->fs->remove($dir . '/' . self::WATCH_MARKER);
            }
        } catch (IOException) {
            // leave the marker as-is; the compile matters more than the stamp
        }
    }

    /**
     * Removing a missing dir is a no-op, so both directions can share this.
     * The `asset_mapper` subdirectory mirrors FrameworkBundle's wiring of
     * asset_mapper.cached_mapped_asset_factory (no public constant exists).
     */
    private function clearAssetMapperCache(): void
    {
        $this->fs->remove($this->cacheDir . '/asset_mapper');
    }

    /**
     * getDestinationPath() is the public dir; the compiled files live under
     * the public prefix inside it (resolvePublicPath('') yields "/assets/").
     */
    private function publicAssetsDir(): string
    {
        return $this->publicAssets->getDestinationPath() . rtrim($this->pathResolver->resolvePublicPath(''), '/');
    }
}
