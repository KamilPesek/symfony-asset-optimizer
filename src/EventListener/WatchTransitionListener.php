<?php

declare(strict_types=1);

namespace AssetOptimizer\EventListener;

use AssetOptimizer\Watch\WatchLock;
use AssetOptimizer\Watch\WatchTransition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Dev-only self-healing of the watch state.
 *
 * The watch runs a {@see WatchTransition} on clean start and stop so image
 * digests flip between raw and optimized. When it dies without cleanup
 * (kill -9, OOM, or Ctrl-C in an environment without pcntl where no signal
 * handler can run), the flock auto-releases but the stale cache and compiled
 * config JSONs keep serving the previous state. This listener probes the lock
 * once per request and, whenever the observed state differs from the
 * {@see WatchLock::recordedHeld()} one, runs the missed transition — the first
 * request after any unclean stop (or externally started watch) heals the state.
 * A never-recorded state only heals toward "held" (an externally started
 * watch); with no watch running it is left alone rather than treated as a
 * missed stop.
 *
 * Priority is above AssetMapper's dev server subscriber (35) so asset requests
 * also see a consistent state. Cost on the steady path: one flock probe and
 * one small file read per request, in dev only.
 */
#[AsEventListener(event: RequestEvent::class, priority: 64)]
final readonly class WatchTransitionListener
{
    public function __construct(
        private WatchLock       $watchLock,
        private WatchTransition $transition,
        #[Autowire('%kernel.debug%')]
        private bool            $debug,
    )
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$this->debug || !$event->isMainRequest()) {
            return;
        }

        $held = $this->watchLock->isHeld();
        $recorded = $this->watchLock->recordedHeld();
        // A never-recorded state (null) means no watch has ever run here: there
        // is nothing to heal, and running toReleased() would destructively
        // remove compiled JSONs the user may have just built deliberately
        // (e.g. a prod asset-map:compile on this checkout).
        if ($held === $recorded || (!$held && null === $recorded)) {
            return;
        }

        $held ? $this->transition->toHeld() : $this->transition->toReleased();
    }
}
