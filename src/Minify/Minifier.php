<?php

declare(strict_types=1);

namespace AssetOptimizer\Minify;

use AssetOptimizer\Binary\BinaryInstaller;
use AssetOptimizer\Binary\Tool;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Minifies text content (js, css, svg) by piping it through the tdewolff/minify
 * binary via stdin/stdout.
 */
final class Minifier
{
    public function __construct(private readonly BinaryInstaller $binaries)
    {
    }

    public function minify(string $content, string $type): string
    {
        // Any failure — the binary can't be downloaded, can't be executed, or
        // exits non-zero — degrades to shipping the content unminified rather
        // than breaking the build. This mirrors the image path (which falls
        // back to GD) so no single tool can take the whole compile down.
        try {
            $process = new Process([$this->binaries->path(Tool::Minify), '--type', $type]);
            $process->setInput($content);
            $process->setTimeout(60);
            $process->run();

            if ($process->isSuccessful()) {
                return $process->getOutput();
            }
        } catch (Throwable) {
            // fall through to the unminified content
        }

        return $content;
    }
}
