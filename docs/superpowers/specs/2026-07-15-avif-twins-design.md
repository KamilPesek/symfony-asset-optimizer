# AVIF twin support — design

2026-07-15. Approved in brainstorming session.

## Goal

Generate `.avif` twins next to compiled rasters the same way `.webp` twins are
generated today: `photo-<hash>.jpg` → `photo-<hash>.jpg.avif`, served by a
web-server `Accept` rule that prefers AVIF, then WebP, then the raster.

## Decisions (from brainstorming)

- **Encoder:** pinned, checksummed standalone binary via `BinaryInstaller`
  (mirroring cwebp), with a pure-PHP GD `imageavif()` fallback. Binary is
  `avifenc` (libavif) if its releases ship artifacts for all five platform
  combos (linux amd64/arm64, mac x64/arm64, windows x64); otherwise `cavif`.
  Resolved when pinning real checksums.
  - **Resolution (revised at implementation):** `avifenc` kept despite
    covering only 3 of 5 combos (linux x86-64, macOS arm64, windows x64).
    The missing combos (notably linux/arm64: Docker on Apple Silicon,
    Graviton) take the GD route, documented in the README; where GD lacks
    AVIF support those platforms produce no `.avif` twins, so twin sets
    differ across architectures. Revisit (or swap to `cavif`) if the arm64
    gap starts to matter.
- **Size policy:** write `.avif` only when smaller than the raster AND smaller
  than the `.webp` twin candidate from the same write pass (when one was
  produced). AVIF must never be a byte-size regression versus what the server
  would otherwise serve.
- **Dev/watch:** same gate as WebP — twins only in prod compiles and
  watch-mode compiles; plain dev serving stays raw. No format-specific gate.
- **Defaults:** `avif.enabled: true`, `avif.quality: 60` (avifenc's own
  default; ~60 AVIF ≈ 80 WebP visually).

## Architecture (approach A — generalize the decorator)

`WebpTwinFilesystem` → `TwinFilesystem` (same namespace, `final readonly`,
still decorating `asset_mapper.local_public_assets_filesystem`). Per raster
write:

1. Gates unchanged: `\.(jpe?g|png)$` match, per-format `enabled`, dev gate
   (`debug && !WatchMode::active()` → skip).
2. Encode WebP (cwebp → GD fallback) — today's path unchanged.
3. Write `.webp` if smaller than raster (today's rule).
4. Encode AVIF (binary → GD fallback).
5. Write `.avif` if smaller than raster and smaller than the WebP bytes from
   step 2 (in-memory strlen comparison, no disk read-back).
6. Incremental skip per format independently: an existing `.webp`/`.avif`
   next to the content-hashed name skips that format only, so enabling AVIF
   later back-fills twins without re-encoding WebP.
7. Best-effort: any Throwable → raster ships without that twin.

New code:

- `Tool::Avifenc` (or `Cavif`): pinned version, per-platform URLs, SHA-256
  checksums.
- `ImageOptimizer::avif(string $sourcePath, int $quality): ?string` mirroring
  `webp()`.
- `GdImageProcessor::avif()` guarded by `function_exists('imageavif')`.

## Config

```yaml
asset_optimizer:
    avif:
        enabled: true
        quality: 60     # avifenc scale; ~60 AVIF ≈ 80 WebP visually
```

Parameters `asset_optimizer.avif_enabled` / `asset_optimizer.avif_quality`,
passed explicitly to the decorator in `config/services.php`. Recipe yaml gets
the commented block.

## Error handling

Same ladder as WebP at every rung: binary download fails → warn once, GD
fallback; GD lacks AVIF → null, no twin, silent; encode empty/oversized →
skipped. Neither path available → WebP-only behavior, same contract as today.

## Serving (README docs)

Apache/Caddy snippets check AVIF before WebP (`Accept: image/avif` +
file-exists on `$1.$2.avif` first), `Vary: Accept` unchanged, `.avif` added to
the images match, `AddType image/avif` where needed.

## Verification

In the asset-build container: `cache:clear`, `debug:asset-map`, real
`asset-map:compile` under `ASSET_OPTIMIZER_WATCH=1`; assert `.avif` twins land,
are smaller than raster and `.webp`; `avif.enabled: false` produces none; GD
fallback exercised by hiding the binary; `gd_info()` checked so we know which
path the smoke test exercised.
