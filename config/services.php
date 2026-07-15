<?php

declare(strict_types=1);

use AssetOptimizer\Binary\BinaryInstaller;
use AssetOptimizer\Command\WatchCommand;
use AssetOptimizer\Compiler\ImageOptimizeCompiler;
use AssetOptimizer\Compiler\MinifyCompiler;
use AssetOptimizer\EventListener\BinaryDownloadOutputListener;
use AssetOptimizer\Image\GdImageProcessor;
use AssetOptimizer\Image\ImageOptimizer;
use AssetOptimizer\Minify\Minifier;
use AssetOptimizer\Path\TwinFilesystem;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(BinaryInstaller::class);
    $services->set(Minifier::class);
    $services->set(GdImageProcessor::class);
    $services->set(ImageOptimizer::class);

    // Compilers run at a very low priority — after the sass compiler and
    // AssetMapper's JS import-path rewriting. autoconfigure is off so the
    // asset_mapper.compiler tag isn't added twice.
    foreach ([MinifyCompiler::class, ImageOptimizeCompiler::class] as $compiler) {
        $services->set($compiler)
            ->autoconfigure(false)
            ->tag('asset_mapper.compiler', ['priority' => -256]);
    }

    // WebP/AVIF twins are generated inside the asset write path — works for
    // every compile entry point (console command, programmatic, watch
    // subprocess).
    $services->set(TwinFilesystem::class)
        ->decorate('asset_mapper.local_public_assets_filesystem')
        ->args([
            service('.inner'),
            service(ImageOptimizer::class),
            param('kernel.debug'),
            param('asset_optimizer.webp_enabled'),
            param('asset_optimizer.webp_quality'),
            param('asset_optimizer.avif_enabled'),
            param('asset_optimizer.avif_quality'),
        ]);

    // Registered via #[AsEventListener] / #[AsCommand] attributes (autoconfigure on).
    $services->set(BinaryDownloadOutputListener::class);
    $services->set(WatchCommand::class);
};
