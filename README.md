# Asset Optimizer Bundle

Symfony's AssetMapper maps, versions and serves your assets — but it doesn't
optimize them, and the usual answers (esbuild, svgo, imagemin, squoosh…) drag
Node, npm and `node_modules` into a PHP project. This bundle fills that gap
**npm-free**: each job uses a standalone binary that is auto-downloaded on
first use (like sass-bundle's dart-sass) and degrades gracefully — a failed
download never breaks the build (images/WebP fall back to pure-PHP GD, minify
ships the files unminified).

- **Minify** JS, CSS and SVG → **`tdewolff/minify`** — production compile only.
- **Optimize** PNG → **`oxipng`** (lossless); JPEG → **PHP GD**.
- **Generate WebP** twins (`<name>.jpg.webp`) → **`cwebp`** (max effort `-m 6`),
  during `asset-map:compile`, incrementally.
- **Serve** WebP transparently via a web-server `Accept` rule (Apache/Caddy).

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

Every optimization is now on by default — minify, image optimization and WebP
twins all run inside `asset-map:compile`. Config is optional; to tweak it, copy
[`recipe/config/packages/asset_optimizer.yaml`](recipe/config/packages/asset_optimizer.yaml)
into your project (see [Configuration](#configuration)).

**2. Serve the WebP twins** *(optional, but recommended — the rest works
without it)*: the twins are generated next to your images, but browsers only
receive them after you add a small rewrite rule to your web server
(Apache `.htaccess` or Caddy) — see [WebP serving rule](#webp-serving-rule).

Day to day:

- **Prod build:** `APP_ENV=prod bin/console asset-map:compile` — sass → minify
  → optimize → WebP twins → manifest, in one command.
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

Plain dev serving delivers your images untouched. Running
`asset-optimizer:watch` flips dev to the prod-like preview: it holds an
advisory lock that switches the image compiler on, and keeps `public/assets`
compiled so the web server serves the optimized rasters statically — which is
also what makes the [WebP serving rule](#webp-serving-rule) kick in. (The two
dev states use different content-hash digests — raw vs. optimized bytes — so
the watch clears AssetMapper's cache on start.) Stopping the watch keeps the
compiled build in place and dev keeps serving it: the same contract as running
`asset-map:compile` in dev, or sass-bundle's `var/sass` output — compiled
artifacts persist until you delete them. `rm -rf public/assets` returns dev to
live raw serving. Minify gates on `!kernel.debug`.

**Source maps:** dev is unaffected (JS is served as raw source; sass-bundle's
SCSS map works as usual). Prod output ships **without** source maps — tdewolff
strips `sourceMappingURL` comments and cannot emit maps. If you need debuggable
prod assets, disable minify per type (`js.enabled: false`, `css.enabled: false`).

### Requirements — no npm

- The **`gd`** PHP extension with WebP support (`ext-gd`, standard on most builds) — used
  for JPEG and as the fallback encoder. `ext-phar` for `.tar.gz` extraction.
- **`ext-pcntl`** *(optional, CLI only)* — lets `asset-optimizer:watch` exit
  gracefully on Ctrl-C. Without it the stop is a hard kill, which ends in the
  exact same state (the lock releases with the process and the compiled
  preview stays in place) — you only lose the goodbye message.
- Network access on the **first** compile: the `minify`, `cwebp` and `oxipng` binaries are
  downloaded once into `var/asset-optimizer/` (cached thereafter). Build-time only —
  each binary is fetched lazily, only when an asset of its type is compiled.

Nothing in your `package.json`, no `node_modules`. If a binary can't be
downloaded, the build still succeeds: image and WebP jobs fall back to pure-PHP
GD automatically, and JS/CSS/SVG are shipped unminified.

> **JPEG note:** JPEG stays on GD — mozjpeg (the tool that would beat it) publishes no
> standalone binary. `oxipng` (PNG) and `cwebp -m6` (WebP) are the real wins over GD.
> Binary versions are pinned in the `Binary\Tool` enum.

### WebP serving rule

The bundle writes a `.webp` twin **next to** each compiled raster, appending the
extension (`shuttle-<hash>.jpg` → `shuttle-<hash>.jpg.webp`). The web server swaps
it in when the browser accepts WebP. Image URLs — including CSS
`background-image` — need no changes.

**Apache** — add to `public/.htaccess` (requires `mod_rewrite` + `mod_headers`),
before the "serve existing file" rewrite:

```apache
<IfModule mod_rewrite.c>
    RewriteCond %{HTTP_ACCEPT} image/webp
    RewriteCond %{REQUEST_FILENAME} \.(jpe?g|png)$
    RewriteCond %{REQUEST_FILENAME}\.webp -f
    RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.webp [T=image/webp,L]
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.(jpe?g|png|webp)$">
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
    @images path *.jpg *.jpeg *.png *.webp
    header @images Vary Accept

    php_server
}
```

The same `@webp` block works in plain Caddy in front of PHP-FPM. Nothing else
changes when migrating from Apache — the twin naming contract
(`<file>.webp` appended) is server-independent, and the bundle itself
(generation) is untouched.

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
    ignore_paths:
        - '*.min.js'
        - '*.min.css'
```

> **Changing quality later:** AssetMapper caches compiled assets keyed on the
> *source file*, not on this config. After changing a quality value, clear the
> matching env's cache (`APP_ENV=prod bin/console cache:clear`) before
> recompiling, or already-compiled images keep their old bytes. Fresh CI/deploy
> builds are unaffected.

### Commands

- **Dev preview:** `bin/console asset-optimizer:watch`
    - watches `assets/`
    - recompiles on change (sass runs automatically inside the compile if sass-bundle is installed)
    - optimized images + WebP are served **only while it runs** — without a
      watch, dev serves raw originals
    - JS/CSS stay unminified
    - stop with Ctrl-C — the optimized build **stays** in `public/assets` and
      dev keeps serving it (frozen, like after any `asset-map:compile`).
      Restart the watch to update it — the kept files mean the next start
      reuses the expensive WebP twins instead of re-encoding — or
      `rm -rf public/assets` to go back to serving raw sources live
    - only one instance can run at a time; a killed watch (`kill -9`) ends in
      the same frozen state as a clean stop — nothing to clean up
    - dev-only: it refuses to run with `kernel.debug` off (e.g. `APP_ENV=prod`) —
      use `asset-map:compile` for builds.
- **Prod build:** `APP_ENV=prod bin/console asset-map:compile`
    - one command: sass → minify → optimize → WebP twins → manifest.
    - running `asset-map:compile` manually **in dev** (without a watch) writes
      raw, unoptimized assets plus a manifest that pins dev to that snapshot —
      AssetMapper then serves it instead of your live sources. Undo with
      `rm -rf public/assets`, the same reset as after a watch.
