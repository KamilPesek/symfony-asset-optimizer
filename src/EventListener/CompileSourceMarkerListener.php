<?php

declare(strict_types=1);

namespace AssetOptimizer\EventListener;

use AssetOptimizer\Watch\WatchTransition;
use Symfony\Component\AssetMapper\Event\PreAssetsCompileEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Stamps every asset-map:compile with its source (watch subprocess vs.
 * deliberate build) via {@see WatchTransition::recordCompileSource()}, so the
 * watch cleanup later knows whether the config JSONs in public/assets are its
 * own to remove. Deliberately registered in every environment: a prod build
 * on a dev checkout must clear the watch marker so nothing ever deletes its
 * manifest.
 */
#[AsEventListener(event: PreAssetsCompileEvent::class)]
final readonly class CompileSourceMarkerListener
{
    public function __construct(
        private WatchTransition $transition,
    )
    {
    }

    public function __invoke(PreAssetsCompileEvent $event): void
    {
        $this->transition->recordCompileSource();
    }
}
