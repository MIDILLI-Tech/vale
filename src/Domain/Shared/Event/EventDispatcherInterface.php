<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\Event;

/**
 * Event dispatcher contract — WordPress-style hooks/filters for Vale.
 *
 * Hooks (actions):  do side-effects, return nothing.
 * Filters:          transform a value through a chain of callbacks.
 */
interface EventDispatcherInterface
{
    /**
     * Register an action listener.
     *
     * @param string   $event    Event name (e.g. 'post.published')
     * @param callable $listener Callback to execute
     * @param int      $priority Lower = runs first (default 10)
     */
    public function addAction(string $event, callable $listener, int $priority = 10): void;

    /**
     * Dispatch an action (fire-and-forget, no return value).
     *
     * @param string $event   Event name
     * @param mixed  ...$args Arguments forwarded to listeners
     */
    public function doAction(string $event, mixed ...$args): void;

    /**
     * Register a filter callback.
     *
     * @param string   $filter   Filter name (e.g. 'post.title')
     * @param callable $callback Receives value + args, must return modified value
     * @param int      $priority Lower = runs first
     */
    public function addFilter(string $filter, callable $callback, int $priority = 10): void;

    /**
     * Apply filters — pass a value through all registered callbacks.
     *
     * @param string $filter Filter name
     * @param mixed  $value  Initial value
     * @param mixed  ...$args Extra arguments passed to each callback
     * @return mixed Filtered value
     */
    public function applyFilter(string $filter, mixed $value, mixed ...$args): mixed;

    /**
     * Check if any listeners are registered for an event/filter.
     */
    public function hasListeners(string $event): bool;
}
