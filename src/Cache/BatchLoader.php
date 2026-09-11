<?php

declare(strict_types=1);

namespace CodeIT\Cache;

/**
 * Collects cache reads that were asked for but not needed yet, and serves all of
 * them together.
 *
 * A page that reads five lists and a dozen rows normally pays one round trip per
 * call. Registering them here costs two: one for every key the callers already
 * know, and one for the row keys the first batch revealed. Whatever neither batch
 * held is then loaded per table through the model's own batched loader.
 *
 * This is batching, not concurrency. PHP has no way to run two blocking queries
 * at once without an event loop, so the win comes from asking for many keys in a
 * single command rather than from overlapping waits.
 */
final class BatchLoader
{
    /** @var list<array{keys: callable, receive: callable, deferred: Deferred}> */
    private array $pending = [];

    private bool $flushing = false;

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function cache(): CacheInterface
    {
        return $this->cache;
    }

    /**
     * Registers a read.
     *
     * $keys returns the cache keys this read wants in the current round, and
     * $receive is handed the values for them, keyed by key. Returning keys again
     * from $keys puts the read into the next round, which is how a cached find()
     * turns its ID list into rows.
     *
     * @param callable(): array<string> $keys
     * @param callable(array<string, mixed>): void $receive
     * @param callable(): array{0: array<int|string, mixed>, 1: int|null} $resolver
     */
    public function defer(callable $keys, callable $receive, callable $resolver): Deferred
    {
        $deferred = new Deferred($this, $resolver);
        $this->pending[] = ['keys' => $keys, 'receive' => $receive, 'deferred' => $deferred];
        return $deferred;
    }

    /** Serves every registered read, then resolves them. */
    public function flush(): void
    {
        if ($this->flushing || !$this->pending) {
            return;
        }
        $this->flushing = true;
        try {
            $round = $this->pending;
            $this->pending = [];

            // Two rounds are enough: the first serves the keys the callers know,
            // the second the row keys that the first round's ID lists revealed.
            for ($pass = 0; $pass < 2 && $round; $pass++) {
                $this->serve($round);
            }

            foreach ($round as $entry) {
                $entry['deferred']->resolve();
            }
        } finally {
            $this->flushing = false;
        }
    }

    /** @param list<array{keys: callable, receive: callable, deferred: Deferred}> $round */
    private function serve(array $round): void
    {
        $keys = [];
        foreach ($round as $entry) {
            foreach (($entry['keys'])() as $key) {
                $keys[$key] = true;
            }
        }
        if (!$keys) {
            return;
        }

        $keys = array_keys($keys);
        $values = $this->cache->mget($keys);
        if (!is_array($values)) {
            $values = [];
        }

        $byKey = [];
        foreach ($keys as $position => $key) {
            $byKey[$key] = $values[$position] ?? false;
        }
        foreach ($round as $entry) {
            ($entry['receive'])($byKey);
        }
    }
}
