<?php

declare(strict_types=1);

namespace AssetOptimizer\EventListener;

use AssetOptimizer\Binary\BinaryInstaller;
use Symfony\Component\AssetMapper\Event\PreAssetsCompileEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Wires the compile command's output into the BinaryInstaller so a first-time
 * binary download (minify/cwebp/oxipng) shows a progress bar during
 * `asset-map:compile`.
 */
final readonly class BinaryDownloadOutputListener
{
    public function __construct(private BinaryInstaller $installer)
    {
    }

    #[AsEventListener(event: PreAssetsCompileEvent::class)]
    public function onPreCompile(PreAssetsCompileEvent $event): void
    {
        $this->installer->setOutput($event->getOutput());
    }
}
