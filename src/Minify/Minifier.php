<?php

declare(strict_types=1);

namespace AssetOptimizer\Minify;

use AssetOptimizer\Binary\BinaryInstaller;
use AssetOptimizer\Binary\Tool;
use RuntimeException;
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
        try {
            $binary = $this->binaries->path(Tool::Minify);
        } catch (Throwable) {
            // Binary unavailable (download failed): ship the content unminified
            // rather than failing the whole build. A failure of the binary on
            // the content itself (below) still surfaces — that is a content
            // problem worth stopping the build for.
            return $content;
        }

        $process = new Process([$binary, '--type', $type]);
        $process->setInput($content);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'minify (--type %s) failed: %s',
                $type,
                trim($process->getErrorOutput()) ?: 'unknown error',
            ));
        }

        return $process->getOutput();
    }
}
