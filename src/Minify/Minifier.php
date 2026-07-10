<?php

declare(strict_types=1);

namespace AssetOptimizer\Minify;

use AssetOptimizer\Binary\BinaryInstaller;
use AssetOptimizer\Binary\Tool;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Minifies text content (js, css, svg) by piping it through the tdewolff/minify
 * binary via stdin/stdout.
 */
final class Minifier
{
    /** @var array<string, true> degradations already logged this run — deduped */
    private array $logged = [];

    public function __construct(
        private readonly BinaryInstaller $binaries,
        private readonly ?LoggerInterface $logger = null,
    )
    {
    }

    public function minify(string $content, string $type): string
    {
        // Any failure — the binary can't be downloaded, can't be executed, or
        // exits non-zero — degrades to shipping the content unminified rather
        // than breaking the build. This mirrors the image path (which falls
        // back to GD) so no single tool can take the whole compile down. The
        // degradation is logged so a silently-unminified prod build is not
        // invisible: shipping bloated assets is the exact failure the tool
        // exists to prevent.
        try {
            $process = new Process([$this->binaries->path(Tool::Minify), '--type', $type]);
            $process->setInput($content);
            $process->setTimeout(60);
            $process->run();

            if ($process->isSuccessful()) {
                return $process->getOutput();
            }

            $this->logDegraded($type, trim($process->getErrorOutput()) ?: 'non-zero exit');
        } catch (Throwable $e) {
            $this->logDegraded($type, $e->getMessage());
        }

        return $content;
    }

    private function logDegraded(string $type, string $reason): void
    {
        // A compile minifies many assets of the same type; an unavailable or
        // consistently-failing binary would otherwise emit one identical line
        // per asset. Dedup on type+reason so each distinct failure is logged
        // once, not once per asset.
        $key = $type . "\0" . $reason;
        if (isset($this->logged[$key])) {
            return;
        }
        $this->logged[$key] = true;

        $this->logger?->warning(
            'Asset Optimizer: minify (--type {type}) failed ({reason}); shipping "{type}" unminified.',
            ['type' => $type, 'reason' => $reason],
        );
    }
}
