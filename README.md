# Asset Optimizer Bundle

Symfony's AssetMapper maps, versions and serves your assets — but it doesn't
optimize them, and the usual answers (esbuild, svgo, imagemin, squoosh…) drag
Node, npm and `node_modules` into a PHP project. This bundle fills that gap
**npm-free**: each job uses a standalone binary that is auto-downloaded on
first use (like sass-bundle's dart-sass) and degrades gracefully — a failed
download never breaks the build (JPEG/PNG optimization falls back to pure-PHP
GD, WebP/AVIF twins wait for the binary and are retried on the next compile,
minify ships the files unminified).

- **Minify** JS, CSS and SVG → **`tdewolff/minify`** — production compile only.
- **Optimize** PNG → **`oxipng`** (lossless); JPEG → **PHP GD**.
- **Generate WebP** twins (`<name>.jpg.webp`) → **`cwebp`** (max effort `-m 6`),
  during `asset-map:compile`, incrementally.
- **Generate AVIF** twins (`<name>.jpg.avif`) → **`avifenc`**, same compile,
  written only when smaller than both the raster and the WebP twin.
- **Serve** AVIF/WebP transparently via a web-server `Accept` rule (Apache/Caddy).

Everything runs inside `asset-map:compile`. **No Node, no npm, no `node_modules`.**

## Quick start

**1. Install:**

```bash
composer require pesek/symfony-asset-optimizer
```

If Flex doesn't register the bundle (the recipe isn't published yet), add it:

```php
// config/bundles.php
AssetOptimizer\AssetOptimizerBundle::class => ['all' => true],
```

Every optimization is now on by default — minify, image optimization and
WebP/AVIF twins all run inside `asset-map:compile`. Config is optional; to
tweak it, copy
[`recipe/config/packages/asset_optimizer.yaml`](recipe/config/packages/asset_optimizer.yaml)
into your project (see [Configuration](#configuration)).

**2. Serve the twins** *(optional, but recommended — the rest works
without it)*: the WebP/AVIF twins are generated next to your images, but
browsers only receive them after you add a small rewrite rule to your web
server (Apache `.htaccess` or Caddy) — see
[Twin serving rule](#twin-serving-rule-webp--avif).

Day to day:

- **Prod build:** `APP_ENV=prod bin/console asset-map:compile` — sass → minify
  → optimize → WebP/AVIF twins → manifest, in one command.
- **Dev preview:** `bin/console asset-optimizer:watch` — see [Commands](#commands).
- Requirements (GD, first-compile network access): see
  [Requirements](#requirements--no-npm).

---

## Details

### Behavior per environment

|                       | dev (dynamic serving)            | dev + `asset-optimizer:watch` | prod compile |
|-----------------------|----------------------------------|-------------------------------|--------------|
| JS / CSS / SVG minify | **off** — assets stay debuggable | **off**                       | on           |
| JPEG / PNG optimize   | **off** — raw originals          | **on**                        | on           |
| WebP twins            | **off**                          | on                            | on           |
| AVIF twins            | **off**                          | on                            | on           |

Plain dev serving delivers your images untouched. Running
`asset-optimizer:watch` flips dev to the prod-like preview: its compiles run
with the image optimizer switched on, and it keeps `public/assets`
compiled so the web server serves the optimized rasters statically — which is
also what makes the [twin serving rule](#twin-serving-rule-webp--avif) kick in. (The two
dev states use different content-hash digests — raw vs. optimized bytes — so
watch compiles keep their asset cache in a separate namespace and never share
entries with plain dev serving.) Stopping the watch keeps the
compiled build in place and dev keeps serving it: the same contract as running
`asset-map:compile` in dev, or sass-bundle's `var/sass` output — compiled
artifacts persist until you delete them. `rm -rf public/assets` returns dev to
live raw serving. Minify gates on `!kernel.debug`.

**Source maps:** dev is unaffected (JS is served as raw source; sass-bundle's
SCSS map works as usual). Prod output ships **without** source maps — tdewolff
strips `sourceMappingURL` comments and cannot emit maps. If you need debuggable
prod assets, disable minify per type (`js.enabled: false`, `css.enabled: false`).

### Requirements — no npm

- The **`gd`** PHP extension (`ext-gd`, standard on most builds) — used for
  JPEG and as the PNG fallback encoder. `ext-phar` for `.tar.gz` extraction.
- **`ext-zip`** for `.zip` extraction: all tools on Windows, and `avifenc` on
  every OS (libavif only ships `.zip`). Missing on a slim Linux image →
  AVIF twins are silently skipped.
- **`ext-pcntl`** *(optional, CLI only)* — lets `asset-optimizer:watch` exit
  gracefully on Ctrl-C. Without it the stop is a hard kill, which ends in the
  exact same state (the lock releases with the process and the compiled
  preview stays in place) — you only lose the goodbye message.
- Network access on the **first** compile: the `minify`, `cwebp`, `oxipng` and
  `avifenc` binaries are downloaded once into `var/asset-optimizer/` (cached
  thereafter). Build-time only — each binary is fetched lazily, only when an
  asset of its type is compiled.

Nothing in your `package.json`, no `node_modules`. If a binary can't be
downloaded, the build still succeeds: JPEG/PNG optimization falls back to
pure-PHP GD automatically, WebP/AVIF twins are skipped for that compile and
retried on the next one (twins are never GD-encoded — GD's output is notably
larger than the pinned binaries', and a GD-made twin or rejection verdict
would stick around in `public/assets` after the binary turns up), and
JS/CSS/SVG are shipped unminified. `avifenc` has upstream artifacts for linux
x86-64, macOS arm64 and Windows x64; other platforms ship without `.avif`
twins.

> **JPEG note:** JPEG stays on GD — mozjpeg (the tool that would beat it) publishes no
> standalone binary. `oxipng` (PNG) and `cwebp -m6` (WebP) are the real wins over GD.
> Binary versions are pinned in the `Binary\Tool` enum.

### Twin serving rule (WebP + AVIF)

The bundle writes `.webp` and `.avif` twins **next to** each compiled raster,
appending the extension (`shuttle-<hash>.jpg` → `shuttle-<hash>.jpg.webp` /
`shuttle-<hash>.jpg.avif`). The web server swaps one in when the browser
accepts the format. Image URLs — including CSS `background-image` — need no
changes.

The server never compares file sizes — the compile already did. A `.webp` twin
is only written when it is smaller than the raster, and an `.avif` twin only
when it is smaller than **both** the raster and the WebP twin. So a twin's
mere existence proves it is the best candidate of its tier, and the rules
below just check existence, AVIF first. Both formats are kept side by side
because browser support differs: a browser that accepts WebP but not AVIF
(older Safari, some webviews) still gets the `.webp` rung instead of falling
back to the full raster.

A candidate that came out *larger* leaves a zero-byte `<name>.<ext>.skip`
marker instead, so recompiles (and every watch tick) don't re-run the
expensive encode just to reject it again — same content, same verdict. The
markers live next to the twins, ship with them — a zero-byte `.skip` is
publicly fetchable like any file in `public/` — and disappear with
`public/assets`.

**Apache** — add to `public/.htaccess` (requires `mod_rewrite` + `mod_headers`),
before the "serve existing file" rewrite:

```apache
<IfModule mod_rewrite.c>
    # AVIF first — when it exists it is the smallest candidate.
    RewriteCond %{HTTP_ACCEPT} image/avif
    RewriteCond %{REQUEST_FILENAME} \.(jpe?g|png)$
    RewriteCond %{REQUEST_FILENAME}\.avif -f
    RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.avif [T=image/avif,L]

    RewriteCond %{HTTP_ACCEPT} image/webp
    RewriteCond %{REQUEST_FILENAME} \.(jpe?g|png)$
    RewriteCond %{REQUEST_FILENAME}\.webp -f
    RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.webp [T=image/webp,L]
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.(jpe?g|png|webp|avif)$">
        Header append Vary Accept
    </FilesMatch>
</IfModule>
```

**FrankenPHP / Caddy** — FrankenPHP embeds Caddy, so the rule goes in its
`Caddyfile`, inside the site block and **before** `php_server` (which would
otherwise serve the original file first). A minimal FrankenPHP example:

```caddy
{
    frankenphp
}

example.com {
    root * /app/public

    # AVIF first — when it exists it is the smallest candidate.
    @avif {
        header Accept *image/avif*
        path *.jpg *.jpeg *.png
        file {path}.avif
    }
    handle @avif {
        header Vary Accept
        rewrite * {path}.avif
        file_server
    }

    # Serve the WebP twin when the browser accepts it and the file exists.
    @webp {
        header Accept *image/webp*
        path *.jpg *.jpeg *.png
        file {path}.webp
    }
    handle @webp {
        header Vary Accept
        rewrite * {path}.webp
        file_server
    }

    # Vary on all image responses so shared caches key correctly.
    @images path *.jpg *.jpeg *.png *.webp *.avif
    header @images Vary Accept

    php_server
}
```

The same `@avif`/`@webp` blocks work in plain Caddy in front of PHP-FPM.
Nothing else changes when migrating from Apache — the twin naming contract
(`<file>.webp` / `<file>.avif` appended) is server-independent, and the bundle
itself (generation) is untouched.

`Vary: Accept` is required in both servers — the same URL returns different
bytes per client, and shared caches/CDNs must key on it.

### Configuration

Defaults shown — the file is optional if they suit you. The bundle ships the
same file, fully commented out, as a Flex recipe in
[`recipe/`](recipe/) (contrib format, ready for `symfony/recipes-contrib` once
the bundle is public).

```yaml
# config/packages/asset_optimizer.yaml
asset_optimizer:
    js:
        enabled: true
    css:
        enabled: true          # covers sass-bundle output too
    svg:
        enabled: true
    jpg_png:
        enabled: true
        quality: 80            # JPEG re-encode quality (PNG is lossless)
    webp:
        enabled: true
        quality: 80            # cwebp -q
    avif:
        enabled: true
        quality: 60            # avifenc -q; ~60 AVIF ≈ 80 WebP visually
    ignore_paths:
        - '*.min.js'
        - '*.min.css'
```

> **Changing quality later:** two separate caches hold old results, with
> different remedies.
> - `jpg_png.quality`: AssetMapper caches compiled assets keyed on the *source
>   file*, not on this config. Clear the matching env's cache
>   (`APP_ENV=prod bin/console cache:clear`) before recompiling; the new raster
>   bytes then get new hashed filenames, so twins and markers re-derive on
>   their own.
> - `webp.quality` / `avif.quality`: twins and `.skip` markers are gated purely
>   on file existence in `public/assets` — `cache:clear` has **no** effect
>   here. Remove `public/assets` and recompile: that re-evaluates rejected
>   candidates *and* re-encodes accepted twins that still carry old-quality
>   bytes. The same wipe applies after a bundle upgrade that bumps the pinned
>   encoder versions, and when the same `public/assets` gets compiled under
>   different per-env quality values (markers carry no config, so the first
>   env's verdict would silently win).
>
> Fresh CI/deploy builds are unaffected either way.

### Commands

- **Dev preview:** `bin/console asset-optimizer:watch`
    - watches `assets/`
    - recompiles on change (sass runs automatically inside the compile if sass-bundle is installed)
    - optimized images + WebP/AVIF are served **only while it runs** — without a
      watch, dev serves raw originals
    - JS/CSS stay unminified
    - stop with Ctrl-C — the optimized build **stays** in `public/assets` and
      dev keeps serving it (frozen, like after any `asset-map:compile`).
      Restart the watch to update it — the kept files mean the next start
      reuses the expensive WebP/AVIF twins instead of re-encoding — or
      `rm -rf public/assets` to go back to serving raw sources live
    - only one instance can run at a time; a killed watch (`kill -9`) ends in
      the same frozen state as a clean stop — nothing to clean up
    - `--tick=<ms>` sets the poll tick (default 100, clamped to 10–60000 with
      a warning); raise it for very large asset trees where the per-tick stat
      sweep gets expensive
    - dev-only: it refuses to run with `kernel.debug` off (e.g. `APP_ENV=prod`) —
      use `asset-map:compile` for builds.
- **Prod build:** `APP_ENV=prod bin/console asset-map:compile`
    - one command: sass → minify → optimize → WebP/AVIF twins → manifest.
    - running `asset-map:compile` manually **in dev** writes
      raw, unoptimized assets plus a manifest that pins dev to that snapshot —
      AssetMapper then serves it instead of your live sources. Undo with
      `rm -rf public/assets`, the same reset as after a watch.
