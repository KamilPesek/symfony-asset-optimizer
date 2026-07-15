# CLAUDE.md

Symfony bundle (`pesek/symfony-asset-optimizer`): AssetMapper compilers for
image optimization (GD/oxipng), WebP twins, and JS/CSS/SVG minify — no npm.
PHP >= 8.4. No tests yet, no tags/releases yet, no vendor/ checked out here.

## Guiding principle

Keep it simple and clear. Prefer fewer moving parts and a runtime story that
fits in one sentence over cleverness. When a review finding offers a "deeper"
fix, weigh it against this explicitly before applying it.

## Dev / verification environment

- The repo lives in WSL (`~/Development/symfony-asset-optimizer`). PHP is NOT
  installed in WSL — use the running `asset-build` Docker container.
- The consuming test app is `~/Development/asset-build/app`, mounted in the
  container at `/var/www/html/htdocs`. This bundle is bind-mounted at
  `/var/www/html/symfony-asset-optimizer` and installed via a composer path
  repository, so edits here are live in the container.
- Lint: `docker exec asset-build php -l /var/www/html/symfony-asset-optimizer/src/...`
- Console: `docker exec -w /var/www/html/htdocs asset-build php bin/console <cmd>`
  Useful: `cache:clear`, `debug:container <service-id>`, `debug:asset-map`
  (exercises the mapped-asset factory without writing `public/assets`).
- To exercise watch-mode behavior in one process: prefix with
  `ASSET_OPTIMIZER_WATCH=1`.

## Architecture notes (non-obvious)

- **Watch marker**: `WatchMode::ENV` (`ASSET_OPTIMIZER_WATCH`). Readers must
  compare strictly against `'1'` (`WatchMode::active()`) — a presence check
  would let an inherited `=0`/empty value flip the dev preview on.
- **Two dev states**: plain dev serving writes/serves raw originals; an
  `asset-optimizer:watch` compile subprocess runs with the env var set and
  produces optimized bytes, hence different content-hash digests by design.
- **Cache isolation**: the two states must never share AssetMapper's
  mtime-keyed MappedAsset cache. `WatchScopedAssetCachePass` swaps the class
  of FrameworkBundle's `asset_mapper.cached_mapped_asset_factory` definition
  for `WatchScopedMappedAssetFactory`, which caches under
  `asset_mapper-watch` when watch-flagged. The swap relies on the framework
  class's constructor staying `(innerFactory, cacheDir, debug)` — re-verify on
  Symfony major/minor bumps (wiring lives in FrameworkBundle
  `Resources/config/asset_mapper.php`). This replaced an earlier
  clear-the-shared-cache-around-compiles approach that had races and silent
  failure modes.
- Consuming apps upgrading across that change may need a one-time
  `cache:clear` to drop optimized entries an old watch left in the shared
  `asset_mapper` namespace.
- `asset-map:compile` already triggers the sass build via sass-bundle's
  `PreAssetsCompileEvent`, so the watch covers sass + images + WebP with one
  subprocess command.
- The watch's single-instance guard is a `FlockStore` flock — the OS releases
  it on any process death, so it cannot go stale; don't replace it with a
  pid/marker file.
