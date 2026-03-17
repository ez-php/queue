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
 * Usage: ez queue:work [queue] [--sleep=3] [--max-jobs=0]
 *
 *   queue         Name of the queue to process (default: "default")
 *   --sleep=N     Seconds to sleep when the queue is empty (default: 3)
 *   --max-jobs=N  Stop after processing N jobs; 0 means run forever (default: 0)
 *
 * @package EzPhp\Queue\Console
 */
final class WorkCommand implements CommandInterface
{
    /**
     * WorkCommand Constructor
     *
     * @param Worker $worker
     */
    public function __construct(private readonly Worker $worker)
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
        return 'Usage: ez queue:work [queue] [--sleep=3] [--max-jobs=0]';
    }

    /**
     * @param list<string> $args
     *
     * @return int
     */
    public function handle(array $args): int
    {
        $input = new Input($args);
        $queue = $input->argument(0) ?? 'default';
        $sleep = (int) $input->option('sleep', '3');
        $maxJobs = (int) $input->option('max-jobs', '0');

        echo "Starting queue worker on queue [{$queue}]...\n";
        echo "Sleep: {$sleep}s | Max jobs: " . ($maxJobs === 0 ? 'unlimited' : (string) $maxJobs) . "\n";

        $this->worker->work($queue, $sleep, $maxJobs);

        return 0;
    }
}
