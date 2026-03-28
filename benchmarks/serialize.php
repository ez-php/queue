<?php

declare(strict_types=1);

/**
 * Performance benchmark for EzPhp\Queue\Job serialization.
 *
 * Measures the overhead of serializing and deserializing queue jobs
 * before they are stored in the backend. This is the hot path that
 * runs on every push() and pop() call.
 *
 * Exits with code 1 if the per-round-trip time exceeds the defined threshold,
 * allowing CI to detect performance regressions automatically.
 *
 * Usage:
 *   php benchmarks/serialize.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use EzPhp\Queue\Job;

const ITERATIONS = 10000;
const THRESHOLD_MS = 0.5; // per-round-trip upper bound in milliseconds

// ── Sample job ────────────────────────────────────────────────────────────────

final class SendEmailJob extends Job
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly int $priority,
    ) {
    }

    public function handle(): void
    {
        // no-op
    }
}

// ── Benchmark ─────────────────────────────────────────────────────────────────

$job = new SendEmailJob(
    to: 'user@example.com',
    subject: 'Welcome to ez-php',
    body: str_repeat('Hello World! ', 20),
    priority: 5,
);

// Warm-up
$serialized = serialize($job);
$deserialized = unserialize($serialized);

$start = hrtime(true);

for ($i = 0; $i < ITERATIONS; $i++) {
    $s = serialize($job);
    /** @var SendEmailJob $d */
    $d = unserialize($s);
    // Access a property to prevent dead-code elimination
    $_ = $d->to;
}

$end = hrtime(true);

$totalMs = ($end - $start) / 1_000_000;
$perRoundTrip = $totalMs / ITERATIONS;
$payloadBytes = strlen($serialized);

echo sprintf(
    "Queue Job Serialize Benchmark\n" .
    "  Payload size         : %d bytes\n" .
    "  Iterations           : %d\n" .
    "  Total time           : %.2f ms\n" .
    "  Per round-trip       : %.3f ms\n" .
    "  Threshold            : %.1f ms\n",
    $payloadBytes,
    ITERATIONS,
    $totalMs,
    $perRoundTrip,
    THRESHOLD_MS,
);

if ($perRoundTrip > THRESHOLD_MS) {
    echo sprintf(
        "FAIL: %.3f ms exceeds threshold of %.1f ms\n",
        $perRoundTrip,
        THRESHOLD_MS,
    );
    exit(1);
}

echo "PASS\n";
exit(0);
