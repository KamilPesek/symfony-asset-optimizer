<?php

declare(strict_types=1);

namespace AssetOptimizer\EventListener;

use AssetOptimizer\Image\ImageOptimizer;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Finder\Finder;
use function strlen;

/**
 * After `asset-map:compile` finishes, generates a WebP twin (via GD) next to
 * each compiled raster: <name>.jpg -> <name>.jpg.webp (extension appended).
 *
 * Incremental: skips twins that already exist (filenames are content hashes, so
 * an existing twin means the content is unchanged) and any twin that would not
 * be smaller than its source.
 */
final readonly class WebpTwinGenerator
{
    public function __construct(
        private ImageOptimizer $optimizer,
        #[Autowire('%kernel.project_dir%')]
        private string         $projectDir,
        #[Autowire('%asset_optimizer.webp_enabled%')]
        private bool           $enabled,
        #[Autowire('%asset_optimizer.webp_quality%')]
        private int            $quality,
    )
    {
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        if (!$this->enabled ||
            0 !== $event->getExitCode() ||
            'asset-map:compile' !== $event->getCommand()?->getName()
        ) {
            return;
        }

        $dir = $this->projectDir . '/public/assets';
        if (!is_dir($dir)) {
            return;
        }

        $made = 0;
        foreach (new Finder()->files()->in($dir)->name('/\.(jpe?g|png)$/i') as $file) {
            $path = $file->getRealPath();
            $twin = $path . '.webp';
            if (is_file($twin)) {
                continue; // content-hashed name → already converted
            }

            $webp = $this->optimizer->webp($path, $this->quality);
            if (null !== $webp && strlen($webp) < filesize($path)) {
                file_put_contents($twin, $webp);
                ++$made;
            }
        }

        if ($made > 0) {
            $event->getOutput()->writeln(sprintf('<info>Asset Optimizer:</info> generated %d WebP twin(s).', $made));
        }
    }
}
