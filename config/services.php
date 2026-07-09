<?php

declare(strict_types=1);

use AssetOptimizer\Binary\BinaryInstaller;
use AssetOptimizer\Command\WatchCommand;
use AssetOptimizer\Compiler\ImageOptimizeCompiler;
use AssetOptimizer\Compiler\JsCssMinifyCompiler;
use AssetOptimizer\Compiler\SvgMinifyCompiler;
use AssetOptimizer\EventListener\BinaryDownloadOutputListener;
use AssetOptimizer\EventListener\WebpTwinGenerator;
use AssetOptimizer\Image\GdImageProcessor;
use AssetOptimizer\Image\ImageOptimizer;
use AssetOptimizer\Minify\Minifier;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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
    foreach ([JsCssMinifyCompiler::class, SvgMinifyCompiler::class, ImageOptimizeCompiler::class] as $compiler) {
        $services->set($compiler)
            ->autoconfigure(false)
            ->tag('asset_mapper.compiler', ['priority' => -256]);
    }

    // Registered via #[AsEventListener] / #[AsCommand] attributes (autoconfigure on).
    $services->set(BinaryDownloadOutputListener::class);
    $services->set(WebpTwinGenerator::class);
    $services->set(WatchCommand::class);
};
