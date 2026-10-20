<?php

declare(strict_types=1);

namespace EzPhp\Queue;

use EzPhp\Contracts\JobInterface;
use Throwable;

/**
 * Class JobSerializer
 *
 * Turns a job into the storage envelope every queue driver persists, and back.
 *
 * The envelope records every class that occurs anywhere in the serialized
 * payload — the job itself, nested objects (a `Mailable`, an event, a
 * `PushMessage`), chained jobs, enums — and `unserialize()` restricts
 * `allowed_classes` to exactly that list. `allowed_classes` applies to every
 * object in the payload, not only the top level, so restricting it to the job
 * class alone turns nested objects into `__PHP_Incomplete_Class` and breaks
 * typed properties.
 *
 * Jobs implementing JsonPayloadJobInterface are stored as JSON instead
 * (`format: json`, `data` = `{"payload": …, "attempts": n}`) and restored via
 * fromPayload() without any unserialize() call.
 *
 * Trust model: the list lives in the same record as the payload, so it limits
 * the classes that can be instantiated to the ones present at push() time, but
 * it is no defence against someone who can write arbitrary rows to the queue
 * backend — that party controls both fields. Queue storage must only ever be
 * written by push()/failed().
 *
 * @package EzPhp\Queue
 */
final class JobSerializer
{
    /**
     * Build the storage envelope for a job.
     *
     * @param JobInterface $job
     *
     * @throws QueueException When the job cannot be serialized (e.g. it holds a closure).
     *
     * @return array{class: class-string, classes: list<string>, data: string, format?: 'json'}
     */
    public static function serialize(JobInterface $job): array
    {
        if ($job instanceof JsonPayloadJobInterface) {
            try {
                $data = json_encode(['payload' => $job->toPayload(), 'attempts' => $job->getAttempts()], JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new QueueException('Job payload cannot be JSON-encoded: ' . $e->getMessage(), 0, $e);
            }

            return ['class' => $job::class, 'classes' => [$job::class], 'data' => $data, 'format' => 'json'];
        }

        try {
            $data = serialize($job);
        } catch (Throwable $e) {
            throw new QueueException('Job cannot be serialized: ' . $e->getMessage(), 0, $e);
        }

        return [
            'class' => $job::class,
            'classes' => self::classesIn($data),
            'data' => $data,
        ];
    }

    /**
     * Restore a job from an envelope produced by serialize().
     *
     * Envelopes written before the `classes` list existed fall back to the
     * top-level `class` only.
     *
     * @param array<mixed> $envelope Decoded envelope as returned by serialize().
     *
     * @throws QueueException When the envelope is malformed or does not contain a JobInterface.
     *
     * @return JobInterface
     */
    public static function unserialize(array $envelope): JobInterface
    {
        $class = $envelope['class'] ?? null;
        $data = $envelope['data'] ?? null;

        if (!is_string($class) || !is_string($data)) {
            throw new QueueException('Invalid job payload envelope.');
        }

        if (($envelope['format'] ?? null) === 'json') {
            return self::fromJson($class, $data);
        }

        $allowed = [$class];

        if (isset($envelope['classes']) && is_array($envelope['classes'])) {
            foreach ($envelope['classes'] as $name) {
                if (is_string($name)) {
                    $allowed[] = $name;
                }
            }
        }

        /** @var mixed $job */
        $job = unserialize($data, ['allowed_classes' => array_values(array_unique($allowed))]);

        if (!$job instanceof JobInterface) {
            throw new QueueException('Deserialized payload is not a JobInterface instance.');
        }

        return $job;
    }

    /**
     * Restore a JSON-stored job through its fromPayload(), re-applying the attempt count.
     *
     * @param string $class
     * @param string $data
     *
     * @throws QueueException When the class is not a JsonPayloadJobInterface or the data is invalid.
     *
     * @return JobInterface
     */
    private static function fromJson(string $class, string $data): JobInterface
    {
        if (!is_subclass_of($class, JsonPayloadJobInterface::class)) {
            throw new QueueException("JSON job payload names '{$class}', which does not implement " . JsonPayloadJobInterface::class . '.');
        }

        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new QueueException('Invalid JSON job payload: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded) || !is_array($decoded['payload'] ?? null)) {
            throw new QueueException('Invalid JSON job payload: missing "payload".');
        }

        /** @var array<string, mixed> $payload */
        $payload = $decoded['payload'];
        $job = $class::fromPayload($payload);
        $attempts = is_int($decoded['attempts'] ?? null) ? $decoded['attempts'] : 0;

        while ($job->getAttempts() < $attempts) {
            $job->incrementAttempts();
        }

        return $job;
    }

    /**
     * Collect the class names of every object and enum in a serialized string.
     *
     * Scans the serialize() format for `O:` (objects), `C:` (Serializable) and
     * `E:` (enum cases) tokens. A string value that happens to contain such a
     * token can only widen the list with a name the pusher itself wrote.
     *
     * @param string $data
     *
     * @return list<string>
     */
    private static function classesIn(string $data): array
    {
        $classes = [];

        if (preg_match_all('/(?:^|[;{}])[OC]:\d+:"([^"]+)"/', $data, $m) > 0) {
            $classes = $m[1];
        }

        if (preg_match_all('/(?:^|[;{}])E:\d+:"([^":]+):[^"]*"/', $data, $m) > 0) {
            $classes = array_merge($classes, $m[1]);
        }

        return array_values(array_unique($classes));
    }
}
