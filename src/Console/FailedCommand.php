<?php

declare(strict_types=1);

namespace EzPhp\Queue\Console;

use EzPhp\Console\CommandInterface;
use EzPhp\Console\Input;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Queue\FailedJobRepositoryInterface;

/**
 * Class FailedCommand
 *
 * Manages permanently failed jobs stored in the failed-job archive.
 *
 * Usage:
 *   ez queue:failed list              — List all failed jobs
 *   ez queue:failed retry {id}        — Retry a single failed job
 *   ez queue:failed delete {id}       — Permanently delete a failed job
 *   ez queue:failed flush             — Delete all failed jobs
 *
 * Requires a queue driver that implements FailedJobRepositoryInterface
 * (e.g. DatabaseDriver).
 *
 * @package EzPhp\Queue\Console
 */
final readonly class FailedCommand implements CommandInterface
{
    /**
     * FailedCommand Constructor
     *
     * @param FailedJobRepositoryInterface $repository
     * @param QueueInterface               $queue
     */
    public function __construct(
        private FailedJobRepositoryInterface $repository,
        private QueueInterface $queue,
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'queue:failed';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Manage permanently failed jobs';
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        return implode("\n", [
            'Usage: ez queue:failed <subcommand> [id]',
            '',
            'Subcommands:',
            '  list          List all failed jobs',
            '  retry {id}    Move a failed job back onto its queue',
            '  delete {id}   Permanently delete a failed job',
            '  flush         Delete all failed jobs',
        ]);
    }

    /**
     * @param list<string> $args
     *
     * @return int
     */
    public function handle(array $args): int
    {
        $input = new Input($args);
        $subcommand = $input->argument(0) ?? 'list';

        return match ($subcommand) {
            'list' => $this->listFailed(),
            'retry' => $this->retryFailed((int) ($input->argument(1) ?? '0')),
            'delete' => $this->deleteFailed((int) ($input->argument(1) ?? '0')),
            'flush' => $this->flushFailed(),
            default => $this->unknownSubcommand($subcommand),
        };
    }

    /**
     * @return int
     */
    private function listFailed(): int
    {
        $jobs = $this->repository->all();

        if ($jobs === []) {
            echo "No failed jobs.\n";

            return 0;
        }

        foreach ($jobs as $job) {
            $firstLine = explode("\n", $job['exception'])[0];
            echo sprintf(
                "[%d] queue=%-12s  failed_at=%s\n     %s\n",
                $job['id'],
                $job['queue'],
                $job['failed_at'],
                $firstLine,
            );
        }

        return 0;
    }

    /**
     * @param int $id
     *
     * @return int
     */
    private function retryFailed(int $id): int
    {
        if ($id <= 0) {
            echo "Error: provide a valid job id. Usage: ez queue:failed retry {id}\n";

            return 1;
        }

        if ($this->repository->retry($id, $this->queue)) {
            echo "Failed job [{$id}] has been pushed back onto its queue.\n";

            return 0;
        }

        echo "No failed job found with id [{$id}].\n";

        return 1;
    }

    /**
     * @param int $id
     *
     * @return int
     */
    private function deleteFailed(int $id): int
    {
        if ($id <= 0) {
            echo "Error: provide a valid job id. Usage: ez queue:failed delete {id}\n";

            return 1;
        }

        if ($this->repository->forget($id)) {
            echo "Failed job [{$id}] has been deleted.\n";

            return 0;
        }

        echo "No failed job found with id [{$id}].\n";

        return 1;
    }

    /**
     * @return int
     */
    private function flushFailed(): int
    {
        $this->repository->flush();
        echo "All failed jobs have been deleted.\n";

        return 0;
    }

    /**
     * @param string $subcommand
     *
     * @return int
     */
    private function unknownSubcommand(string $subcommand): int
    {
        echo "Unknown subcommand: {$subcommand}\n\n" . $this->getHelp() . "\n";

        return 1;
    }
}
