<?php

namespace Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * The queue's `retry_after` against the jobs that actually run on it.
 *
 * Laravel's rule: `retry_after` must exceed the longest job's `$timeout`, or the queue
 * decides a merely slow job has been abandoned and hands it to a second worker while
 * the first is still working. It cost us a real afternoon — the catalogue sync, which
 * declares thirty minutes because enriching thousands of properties takes that long,
 * was reclaimed after ninety seconds, marked MaxAttemptsExceeded while running
 * perfectly well, and left racing a duplicate of itself against the supplier.
 *
 * A test rather than a comment because the failure is silent: nothing warns you, the
 * job appears to fail, and the obvious response — press it again — makes it worse.
 */
class QueueTimeoutTest extends TestCase
{
    /**
     * Every queued job in the application, with the timeout it declares.
     *
     * @return array<string, int>
     */
    private function jobTimeouts(): array
    {
        $timeouts = [];

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\'.Str::before(basename($file), '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            // A job with no $timeout inherits the worker's, which is not ours to police.
            $default = $reflection->getDefaultProperties()['timeout'] ?? null;

            if (is_int($default)) {
                $timeouts[$class] = $default;
            }
        }

        return $timeouts;
    }

    public function test_the_queue_waits_longer_than_its_slowest_job(): void
    {
        $timeouts = $this->jobTimeouts();

        $this->assertNotEmpty($timeouts, 'no queued jobs found — this test has stopped testing anything');

        $retryAfter = (int) config('queue.connections.database.retry_after');
        $slowest = max($timeouts);
        $name = array_search($slowest, $timeouts, true);

        $this->assertGreaterThan(
            $slowest,
            $retryAfter,
            "queue.connections.database.retry_after ({$retryAfter}s) must exceed the longest job timeout — ".
            class_basename((string) $name)." declares {$slowest}s. Below it, that job is reclaimed while ".
            'still running and ends up racing a duplicate of itself.'
        );
    }
}
