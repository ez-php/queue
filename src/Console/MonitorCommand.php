<?php

declare(strict_types=1);

namespace EzPhp\Queue\Console;

use EzPhp\Console\CommandInterface;
use EzPhp\Console\Input;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\FailedJobRepositoryInterface;

/**
 * Class MonitorCommand
 *
 * Console command that prints a snapshot of queue depths and failed-job counts.
 *
 * Usage: ez queue:monitor [--queues=default] [--watch=0]
 *
 *   --queues=q1,q2   Comma-separated queue names to inspect (default: "default")
 *   --watch=N        Refresh every N seconds; 0 = single snapshot and exit (default: 0)
 *
 * Output example:
 *
 *   Queue Monitor — 2026-04-17 10:00:00
 *   ─────────────────────────────────────
 *   Queue       Pending
 *   ─────────────────────────────────────
 *   default     5
 *   emails      2
 *   ─────────────────────────────────────
 *   Failed jobs: 3
 *
 * When the queue driver does not implement FailedJobRepositoryInterface, the
 * "Failed jobs" line shows "n/a".
 *
 * @package EzPhp\Queue\Console
 */
final readonly class MonitorCommand implements CommandInterface
{
    /**
     * MonitorCommand Constructor
     *
     * @param QueueInterface $queue
     */
    public function __construct(private QueueInterface $queue)
    {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'queue:monitor';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Monitor queue depths and failed-job counts';
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        return 'Usage: ez queue:monitor [--queues=default] [--watch=0]';
    }

    /**
     * @param list<string> $args
     *
     * @return int
     */
    public function handle(array $args): int
    {
        $input = new Input($args);
        $watch = (int) $input->option('watch', '0');

        $queuesArg = $input->option('queues', 'default');
        $queues = array_values(array_filter(array_map('trim', explode(',', $queuesArg))));

        if ($queues === []) {
            $queues = ['default'];
        }

        do {
            $this->printSnapshot($queues);

            if ($watch > 0) {
                echo "\n(Refreshing every {$watch}s — Ctrl+C to stop)\n";
                sleep($watch);
            }
        } while ($watch > 0);

        return 0;
    }

    /**
     * Print one snapshot of all requested queues plus the failed-job count.
     *
     * @param list<string> $queues
     *
     * @return void
     */
    private function printSnapshot(array $queues): void
    {
        $separator = str_repeat('─', 40);
        $timestamp = date('Y-m-d H:i:s');

        echo "Queue Monitor — {$timestamp}\n";
        echo $separator . "\n";
        echo sprintf("%-20s %s\n", 'Queue', 'Pending');
        echo $separator . "\n";

        foreach ($queues as $name) {
            $size = $this->queue->size($name);
            echo sprintf("%-20s %d\n", $name, $size);
        }

        echo $separator . "\n";

        if ($this->queue instanceof FailedJobRepositoryInterface) {
            $failedCount = count($this->queue->all());
            echo "Failed jobs:         {$failedCount}\n";
        } else {
            echo "Failed jobs:         n/a\n";
        }
    }
}
