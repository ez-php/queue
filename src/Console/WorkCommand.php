<?php

declare(strict_types=1);

namespace EzPhp\Queue\Console;

use EzPhp\Console\CommandInterface;
use EzPhp\Console\Input;
use EzPhp\Queue\Worker;

/**
 * Class WorkCommand
 *
 * Console command that starts a queue worker process.
 *
 * Usage: ez queue:work [queues] [--sleep=3] [--max-jobs=0]
 *
 *   queues        Comma-separated list of queues in priority order
 *                 (default: "default"). Example: "high,default,low"
 *   --sleep=N     Seconds to sleep when all queues are empty (default: 3)
 *   --max-jobs=N  Stop after processing N jobs; 0 means run forever (default: 0)
 *
 * @package EzPhp\Queue\Console
 */
final readonly class WorkCommand implements CommandInterface
{
    /**
     * WorkCommand Constructor
     *
     * @param Worker $worker
     */
    public function __construct(private Worker $worker)
    {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'queue:work';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Start processing jobs on the given queue';
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        return 'Usage: ez queue:work [queues] [--sleep=3] [--max-jobs=0]';
    }

    /**
     * @param list<string> $args
     *
     * @return int
     */
    public function handle(array $args): int
    {
        $input = new Input($args);
        $queuesArg = $input->argument(0) ?? 'default';
        $sleep = (int) $input->option('sleep', '3');
        $maxJobs = (int) $input->option('max-jobs', '0');

        // Support comma-separated priority queues: "high,default,low"
        $queues = array_values(array_filter(array_map('trim', explode(',', $queuesArg))));

        if ($queues === []) {
            $queues = ['default'];
        }

        $queuesLabel = implode(', ', $queues);
        echo "Starting queue worker on queue(s) [{$queuesLabel}]...\n";
        echo "Sleep: {$sleep}s | Max jobs: " . ($maxJobs === 0 ? 'unlimited' : (string) $maxJobs) . "\n";

        $this->worker->work(count($queues) === 1 ? $queues[0] : $queues, $sleep, $maxJobs);

        $stats = $this->worker->getStats();
        echo sprintf(
            "\nDone. Processed: %d | Retried: %d | Permanently failed: %d\n",
            $stats['processed'],
            $stats['retried'],
            $stats['failed'],
        );

        return 0;
    }
}
