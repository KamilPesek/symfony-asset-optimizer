<?php

declare(strict_types=1);

namespace AssetOptimizer\Watch;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Service\ResetInterface;
use function dirname;
use function sprintf;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const LOCK_UN;

/**
 * Advisory-lock signal for "an asset-optimizer:watch is running".
 *
 * The watch process holds an exclusive flock on var/asset-optimizer/watch.lock
 * for its whole lifetime; other processes probe liveness by trying to acquire
 * a *shared* lock on the same file — shared probes never conflict with each
 * other (concurrent requests probing at once must not read as "held"), only
 * with the holder's exclusive lock. flock is released by the OS when the
 * holder's fd closes — including on any process death, even SIGKILL — so the
 * signal can never go stale, unlike a pid or marker file.
 */
final class WatchLock implements ResetInterface
{
    private const string LOCK_FILE = '/var/asset-optimizer/watch.lock';

    private const string STATE_FILE = '/var/asset-optimizer/watch.state';

    private readonly Filesystem $fs;

    /** @var resource|null held handle — non-null only inside the watch process */
    private $handle = null;

    /**
     * Memoized probe: supports() asks once per asset during a compile or
     * request. Cleared between requests via {@see reset()} so worker-mode
     * runtimes (FrankenPHP, RoadRunner) re-probe instead of serving a stale
     * answer for the container's whole lifetime.
     */
    private ?bool $probed = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    )
    {
        $this->fs = new Filesystem();
    }

    /**
     * Take the lock for this process, keeping it until release() or process
     * death. False when another watch already holds it.
     *
     * @throws WatchLockUnavailableException when the lock file cannot be
     *                                       created — a permissions problem,
     *                                       not a running watch
     */
    public function hold(): bool
    {
        if (null !== $this->handle) {
            return true;
        }

        $path = $this->projectDir . self::LOCK_FILE;
        try {
            $this->fs->mkdir(dirname($path), 0o755);
        } catch (IOException $e) {
            throw new WatchLockUnavailableException(sprintf('Cannot create the lock directory "%s" — check permissions.', dirname($path)), previous: $e);
        }

        $handle = @fopen($path, 'c');
        if (false === $handle) {
            throw new WatchLockUnavailableException(sprintf('Cannot open the lock file "%s" — check permissions.', $path));
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
     * kernel.reset: drop the memoized probe between requests. The held handle
     * stays — the watch process owns it for its whole lifetime.
     */
    public function reset(): void
    {
        $this->probed = null;
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

        $path = $this->projectDir . self::LOCK_FILE;
        if (!$this->fs->exists($path)) {
            return $this->probed = false; // no lock file → no watch has ever run
        }

        $handle = @fopen($path, 'r');
        if (false === $handle) {
            // The file exists but can't be opened (a watch run as another user
            // left it unreadable): the lock is unprobeable, not absent. Answer
            // with the recorded state so an unreadable lock never manufactures
            // a held/recorded mismatch that would fight a possibly live watch.
            return $this->probed = $this->recordedHeld();
        }
        // A shared lock succeeds unless the watch holds its exclusive one, and
        // never collides with other probes running at the same moment.
        if (flock($handle, LOCK_SH | LOCK_NB)) {
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
     * when it heals after an abnormal termination. A missing or unreadable
     * record reads as "released", the state every checkout starts in.
     */
    public function recordedHeld(): bool
    {
        try {
            return 'on' === $this->fs->readFile($this->projectDir . self::STATE_FILE);
        } catch (IOException) {
            return false; // missing or unreadable record → "released"
        }
    }

    /**
     * False when the record cannot be written. Callers must skip the
     * transition's side effects in that case: a mismatch that can never be
     * recorded as healed would re-run them on every request.
     */
    public function record(bool $held): bool
    {
        try {
            // dumpFile creates the directory and writes atomically (tmp file
            // + rename), so a killed write can never leave a torn record.
            $this->fs->dumpFile($this->projectDir . self::STATE_FILE, $held ? 'on' : 'off');
        } catch (IOException) {
            return false;
        }

        return true;
    }
}
