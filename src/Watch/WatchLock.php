<?php

declare(strict_types=1);

namespace AssetOptimizer\Watch;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use function dirname;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * Advisory-lock signal for "an asset-optimizer:watch is running".
 *
 * The watch process holds an exclusive flock on var/asset-optimizer/watch.lock
 * for its whole lifetime; other processes probe liveness by trying to acquire
 * the same lock. flock is released by the OS when the holder's fd closes —
 * including on any process death, even SIGKILL — so the signal can never go
 * stale, unlike a pid or marker file.
 */
final class WatchLock
{
    private const string LOCK_FILE = '/var/asset-optimizer/watch.lock';

    private const string STATE_FILE = '/var/asset-optimizer/watch.state';

    /** @var resource|null held handle — non-null only inside the watch process */
    private $handle = null;

    /** Memoized probe: supports() asks once per asset during a compile or request. */
    private ?bool $probed = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    )
    {
    }

    /**
     * Take the lock for this process, keeping it until release() or process
     * death. False when another watch already holds it (or the file cannot be
     * created).
     */
    public function hold(): bool
    {
        if (null !== $this->handle) {
            return true;
        }

        $path = $this->projectDir . self::LOCK_FILE;
        $dir = dirname($path);
        // The `&& !is_dir()` re-check absorbs a concurrent mkdir by another process.
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return false;
        }

        $handle = @fopen($path, 'c');
        if (false === $handle) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if (null === $this->handle) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    /**
     * Is any process (this one included) currently holding the lock?
     */
    public function isHeld(): bool
    {
        if (null !== $this->handle) {
            return true;
        }
        if (null !== $this->probed) {
            return $this->probed;
        }

        $handle = @fopen($this->projectDir . self::LOCK_FILE, 'r');
        if (false === $handle) {
            return $this->probed = false; // no lock file → no watch has ever run
        }
        // If the lock is free to take, nobody holds it.
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            flock($handle, LOCK_UN);
            fclose($handle);

            return $this->probed = false;
        }
        fclose($handle);

        return $this->probed = true;
    }

    /**
     * Last lock state whose cache-clearing side effects were performed —
     * recorded by the watch on clean start/stop and by the transition listener
     * when it heals after an abnormal termination. Null when never recorded.
     */
    public function recordedHeld(): ?bool
    {
        return match (@file_get_contents($this->projectDir . self::STATE_FILE)) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }

    public function record(bool $held): void
    {
        $path = $this->projectDir . self::STATE_FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return; // best-effort: an unwritable record just means one extra heal
        }
        @file_put_contents($path, $held ? 'on' : 'off');
    }
}
