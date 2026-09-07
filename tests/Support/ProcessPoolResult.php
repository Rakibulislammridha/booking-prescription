<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

final class ProcessPoolResult
{
    /** @param  array<int, Process>  $processes */
    public function __construct(public readonly array $processes) {}

    public function failed(): int
    {
        return count(array_filter($this->processes, fn (Process $p) => ! $p->isSuccessful()));
    }

    /** @return array<int, string> one entry per worker */
    public function outputs(): array
    {
        return array_map(fn (Process $p) => $p->getOutput(), $this->processes);
    }

    /**
     * Every non-empty stdout line of every worker (workers print one JSON line per result).
     *
     * @return array<int, string>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->outputs() as $output) {
            foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }
}
