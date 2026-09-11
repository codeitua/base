<?php

declare(strict_types=1);

namespace CodeIT\Cache;

/**
 * A result that has been asked for but not fetched yet.
 *
 * Reading it resolves every deferred result registered with the same loader, so
 * ten lists cost the round trips of one. Nothing here is asynchronous: the work
 * is batched, not parallel, which is the only form of "at the same time" PHP
 * offers a blocking request.
 */
final class Deferred
{
    /** @var array<int|string, mixed>|null */
    private ?array $rows = null;

    private ?int $total = null;

    /**
     * @param callable(): array{0: array<int|string, mixed>, 1: int|null} $resolver
     */
    public function __construct(
        private readonly BatchLoader $loader,
        private $resolver,
    ) {
    }

    /** @return array<int|string, mixed> rows keyed by id */
    public function rows(): array
    {
        if ($this->rows === null) {
            $this->loader->flush();
        }
        return $this->rows ?? [];
    }

    /** Total matching rows, when the deferred call asked for one. */
    public function total(): ?int
    {
        $this->rows();
        return $this->total;
    }

    public function isResolved(): bool
    {
        return $this->rows !== null;
    }

    /** Called by the loader once the batched reads are in. */
    public function resolve(): void
    {
        if ($this->rows !== null) {
            return;
        }
        [$rows, $total] = ($this->resolver)();
        $this->rows = $rows;
        $this->total = $total;
    }
}
