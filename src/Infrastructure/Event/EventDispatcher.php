<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Event;

use ValeApp\Domain\Shared\Event\EventDispatcherInterface;

/**
 * Hook/Filter event dispatcher — WordPress-style but clean.
 *
 * Actions:  fire-and-forget side-effects.
 * Filters:  transform a value through a callback chain.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    /** @var array<string, array<int, callable[]>> action listeners keyed by priority */
    private array $actions = [];

    /** @var array<string, array<int, callable[]>> filter callbacks keyed by priority */
    private array $filters = [];

    public function addAction(string $event, callable $listener, int $priority = 10): void
    {
        $this->actions[$event][$priority][] = $listener;
    }

    public function doAction(string $event, mixed ...$args): void
    {
        if (!isset($this->actions[$event])) {
            return;
        }

        foreach ($this->sortedListeners($this->actions[$event]) as $listener) {
            $listener(...$args);
        }
    }

    public function addFilter(string $filter, callable $callback, int $priority = 10): void
    {
        $this->filters[$filter][$priority][] = $callback;
    }

    public function applyFilter(string $filter, mixed $value, mixed ...$args): mixed
    {
        if (!isset($this->filters[$filter])) {
            return $value;
        }

        foreach ($this->sortedListeners($this->filters[$filter]) as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }

    public function hasListeners(string $event): bool
    {
        return isset($this->actions[$event]) || isset($this->filters[$event]);
    }

    // ── Internals ────────────────────────────────────────────────────

    /**
     * Yield listeners sorted by priority (ascending).
     *
     * @param  array<int, callable[]> $bucket
     * @return \Generator<callable>
     */
    private function sortedListeners(array $bucket): \Generator
    {
        ksort($bucket);
        foreach ($bucket as $listeners) {
            foreach ($listeners as $listener) {
                yield $listener;
            }
        }
    }
}
