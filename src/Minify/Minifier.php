<?php

declare(strict_types=1);

namespace AssetOptimizer\Minify;

use AssetOptimizer\Binary\BinaryInstaller;
use RuntimeException;
use Symfony\Component\Process\Process;

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
        $process = new Process([$this->binaries->path('minify'), '--type', $type]);
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
