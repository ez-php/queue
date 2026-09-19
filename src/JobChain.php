<?php

declare(strict_types=1);

namespace EzPhp\Queue;

use EzPhp\Contracts\QueueInterface;

/**
 * Class JobChain
 *
 * Runs jobs strictly one after another: the next job is pushed only after the
 * previous one succeeded. If a job fails for good, the rest of the chain is dropped.
 *
 *     JobChain::of(new Resize($id), new Publish($id), new Notify($id))->dispatch($queue);
 *
 * The remaining jobs travel inside the serialized payload of the current one
 * (`Job::withChain()`), so no extra storage is needed and every driver works.
 *
 * @package EzPhp\Queue
 */
final class JobChain
{
    /**
     * @var non-empty-list<Job>
     */
    private array $jobs;

    /**
     * JobChain Constructor
     *
     * @param Job  $first
     * @param Job  ...$rest
     */
    private function __construct(Job $first, Job ...$rest)
    {
        $this->jobs = [$first, ...array_values($rest)];
    }

    /**
     * @param Job $first
     * @param Job ...$rest
     *
     * @return self
     */
    public static function of(Job $first, Job ...$rest): self
    {
        return new self($first, ...$rest);
    }

    /**
     * Push the first job; the others follow as each one succeeds.
     *
     * @param QueueInterface $queue
     *
     * @return void
     */
    public function dispatch(QueueInterface $queue): void
    {
        $first = $this->jobs[0];

        $queue->push($first->withChain(array_slice($this->jobs, 1)));
    }
}
