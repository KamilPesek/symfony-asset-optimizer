<?php

declare(strict_types=1);

namespace AssetOptimizer\EventListener;

use AssetOptimizer\Watch\WatchLock;
use AssetOptimizer\Watch\WatchTransition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Exception\IOException;
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
 * request after any unclean stop (or externally started watch) heals the
 * state. Config JSONs from a deliberate `asset-map:compile` are protected by
 * the watch marker (see WatchTransition), not by this guard.
 *
 * Healing is strictly best-effort: a transition that cannot record itself
 * declines to act (see {@see WatchTransition}), and filesystem failures are
 * logged instead of thrown — a janitor must never take dev down.
 *
 * Priority is above AssetMapper's dev server subscriber (35) so asset requests
 * also see a consistent state. Cost on the steady path: one flock probe and
 * one small file read per request — dev only; non-debug containers don't even
 * register this service (see config/services.php).
 */
#[AsEventListener(event: RequestEvent::class, priority: 64)]
final readonly class WatchTransitionListener
{
    public function __construct(
        private WatchLock        $watchLock,
        private WatchTransition  $transition,
        #[Autowire('%kernel.debug%')]
        private bool             $debug,
        private ?LoggerInterface $logger = null,
    )
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$this->debug || !$event->isMainRequest()) {
            return;
        }

        $held = $this->watchLock->isHeld();
        if ($held === $this->watchLock->recordedHeld()) {
            return;
        }

        try {
            $healed = $held ? $this->transition->toHeld() : $this->transition->toReleased();
        } catch (IOException $e) {
            $this->logger?->warning('asset-optimizer: healing the watch state failed — fix the ownership/permissions of var/cache and public/assets. {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return;
        }
        if (!$healed) {
            $this->logger?->warning('asset-optimizer: the watch state changed but var/asset-optimizer/watch.state is not writable — image digests may be stale until permissions are fixed.');
        }
    }
}
