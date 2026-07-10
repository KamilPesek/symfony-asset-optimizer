<?php

declare(strict_types=1);

namespace AssetOptimizer\Watch;

use RuntimeException;

/**
 * The watch lock file cannot be created or opened — an environment problem
 * (typically var/ permissions), distinct from {@see WatchLock::hold()}
 * returning false, which means another watch legitimately holds the lock.
 */
final class WatchLockUnavailableException extends RuntimeException
{
}
