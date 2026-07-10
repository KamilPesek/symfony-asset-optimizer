<?php

declare(strict_types=1);

use AssetOptimizer\Binary\BinaryInstaller;
use AssetOptimizer\Command\WatchCommand;
use AssetOptimizer\Compiler\ImageOptimizeCompiler;
use AssetOptimizer\Compiler\JsCssMinifyCompiler;
use AssetOptimizer\Compiler\SvgMinifyCompiler;
use AssetOptimizer\EventListener\BinaryDownloadOutputListener;
use AssetOptimizer\EventListener\CompileSourceMarkerListener;
use AssetOptimizer\EventListener\WatchTransitionListener;
use AssetOptimizer\Image\GdImageProcessor;
use AssetOptimizer\Image\ImageOptimizer;
use AssetOptimizer\Minify\Minifier;
use AssetOptimizer\Path\WebpTwinFilesystem;
use AssetOptimizer\Watch\WatchLock;
use AssetOptimizer\Watch\WatchTransition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(BinaryInstaller::class);
    $services->set(Minifier::class);
    $services->set(GdImageProcessor::class);
    $services->set(ImageOptimizer::class);

    // Liveness signal between the watch command (holder) and the image
    // compiler (prober) — see WatchLock for the flock semantics.
    $services->set(WatchLock::class);

    // Shared side effects of the lock changing hands (cache + compiled-JSON
    // cleanup). The public-assets filesystem has no autowiring alias, so it is
    // wired by id; this resolves to the WebpTwinFilesystem decoration, which
    // still exposes the destination path.
    $services->set(WatchTransition::class)
        ->args([
            service(WatchLock::class),
            service('asset_mapper.local_public_assets_filesystem'),
            service('asset_mapper.public_assets_path_resolver'),
            param('kernel.cache_dir'),
        ]);

    // Compilers run at a very low priority — after the sass compiler and
    // AssetMapper's JS import-path rewriting. autoconfigure is off so the
    // asset_mapper.compiler tag isn't added twice.
    foreach ([JsCssMinifyCompiler::class, SvgMinifyCompiler::class, ImageOptimizeCompiler::class] as $compiler) {
        $services->set($compiler)
            ->autoconfigure(false)
            ->tag('asset_mapper.compiler', ['priority' => -256]);
    }

    // WebP twins are generated inside the asset write path — works for every
    // compile entry point (console command, programmatic, watch subprocess).
    $services->set(WebpTwinFilesystem::class)
        ->decorate('asset_mapper.local_public_assets_filesystem')
        ->args([
            service('.inner'),
            service(ImageOptimizer::class),
            service(WatchLock::class),
            param('kernel.debug'),
            param('asset_optimizer.webp_enabled'),
            param('asset_optimizer.webp_quality'),
        ]);

    // Registered via #[AsEventListener] / #[AsCommand] attributes (autoconfigure on).
    $services->set(BinaryDownloadOutputListener::class);
    $services->set(CompileSourceMarkerListener::class);
    $services->set(WatchCommand::class);

    // The healing listener can only ever act in debug, so non-debug containers
    // skip it entirely instead of invoking a no-op on every request.
    if ($builder->getParameter('kernel.debug')) {
        $services->set(WatchTransitionListener::class);
    }
};
