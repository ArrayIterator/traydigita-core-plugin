<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core;

use TrayDigita\WP\Plugin\Core\Stack\Libs\Helpers\Hook;
use function str_starts_with;

final class Hooks
{
    /**
     * @var Core
     */
    public readonly Core $core;

    /**
     * @param Core $core
     */
    public function __construct(Core $core)
    {
        $this->core = $core;
    }

    /**
     * Creates a hook name with the appropriate prefix.
     *
     * @param string $hookName The name of the hook.
     * @return string The formatted hook name.
     */
    public function createHookName(string $hookName): string
    {
        $hookPrefix = $this->core->hookPrefix;
        return str_starts_with($hookName, "$hookPrefix:") ? $hookName : "$hookPrefix:$hookName";
    }

    /**
     * Applies a filter hook.
     *
     * @param string $hookName The name of the hook.
     * @param mixed ...$args Additional arguments to pass to the filter callback.
     * @return mixed The filtered value.
     * @see Hook::apply() for more details.
     */
    public function apply(string $hookName, mixed ...$args): mixed
    {
        return $this->directApply($this->createHookName($hookName), ...$args);
    }

    /**
     * Applies a filter hook without prefixing the hook name.
     *
     * @param string $hookName The name of the hook.
     * @param mixed ...$args Additional arguments to pass to the filter callback.
     * @return mixed The filtered value.
     * @see Hook::apply() for more details.
     */
    public function directApply(string $hookName, mixed ...$args): mixed
    {
        return Hook::apply($hookName, ...$args);
    }

    /**
     * Executes an action hook.
     *
     * @param string $hookName The name of the hook.
     * @param mixed ...$args Additional arguments to pass to the action callback.
     * @return void
     * @see Hook::do() for more details.
     */
    public function do(string $hookName, mixed ...$args): void
    {
        $this->directDo($this->createHookName($hookName), ...$args);
        ;
    }

    /**
     * Executes an action hook without prefixing the hook name.
     *
     * @param string $hookName The name of the hook.
     * @param mixed ...$args Additional arguments to pass to the action callback.
     * @return void
     * @see Hook::do() for more details.
     */
    public function directDo(string $hookName, mixed ...$args): void
    {
        Hook::do($hookName, ...$args);
    }

    /**
     * Checks if a hook has been executed.
     *
     * @param string $hookName The name of the hook.
     * @return int The number of times the hook has been executed.
     * @see Hook::did() for more details.
     */
    public function did(string $hookName): int
    {
        return $this->directDid($this->createHookName($hookName));
    }

    /**
     * Checks if a hook has been executed without prefixing the hook name.
     *
     * @param string $hookName The name of the hook.
     * @return int The number of times the hook has been executed.
     * @see Hook::did() for more details.
     */
    public function directDid(string $hookName): int
    {
        return Hook::did($hookName);
    }

    /**
     * Gets the name of the currently executing hook.
     *
     * @return string|null The name of the currently executing hook, or null if no hook is being executed.
     * @see Hook::current() for more details.
     */
    public function current(): ?string
    {
        return Hook::current();
    }

    /**
     * Checks if a hook is currently being executed.
     *
     * @param string $hookName The name of the hook.
     * @return bool True if the hook is currently being executed, false otherwise.
     * @see Hook::doing() for more details.
     */
    public function doing(string $hookName): bool
    {
        return $this->directDoing($this->createHookName($hookName));
    }

    /**
     * Checks if a hook is currently being executed without prefixing the hook name.
     *
     * @param string $hookName The name of the hook.
     * @return bool True if the hook is currently being executed, false otherwise.
     * @see Hook::doing() for more details.
     */
    public function directDoing(string $hookName): bool
    {
        return Hook::doing($hookName);
    }

    /**
     * Checks if a hook exists.
     *
     * @param string $hookName The name of the hook.
     * @return bool True if the hook exists, false otherwise.
     * @param callable|null $callback The callback function to check (optional).
     * @return bool True if the hook exists, false otherwise.
     * @see Hook::has() for more details.
     */
    public function has(string $hookName, ?callable $callback = null): bool
    {
        return $this->directHas($this->createHookName($hookName), $callback);
    }

    /**
     * Checks if a hook exists without prefixing the hook name.
     *
     * @param string $hookName The name of the hook.
     * @param callable|null $callback The callback function to check (optional).
     * @return bool True if the hook exists, false otherwise.
     * @see Hook::has() for more details.
     */
    public function directHas(string $hookName, ?callable $callback = null): bool
    {
        return Hook::has($hookName, $callback);
    }

    /**
     * Adds a callback to a hook.
     *
     * @param string $action The name of the action hook.
     * @param callable $callback The callback function to execute.
     * @param int $priority The priority of the action (default: 10).
     * @param int $acceptedArgs The number of arguments the callback accepts (default: 1).
     * @return bool True if the action was added, false otherwise.
     * @see Hook::add() for more details.
     */
    public function add(string $action, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return $this->directAdd($this->createHookName($action), $callback, $priority, $acceptedArgs);
    }

    /**
     * Adds a callback to a hook without prefixing the hook name.
     *
     * @param string $action The name of the action hook.
     * @param callable $callback The callback function to execute.
     * @param int $priority The priority of the action (default: 10).
     * @param int $acceptedArgs The number of arguments the callback accepts (default: 1).
     * @return bool True if the action was added, false otherwise.
     * @see Hook::add() for more details.
     */
    public function directAdd(string $action, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return Hook::add($action, $callback, $priority, $acceptedArgs);
    }

    /**
     * Removes a callback from a hook.
     *
     * @param string $action The name of the action hook.
     * @param callable $callback The callback function to remove.
     * @param int $priority The priority of the action (default: 10).
     * @return bool True if the action was removed, false otherwise.
     * @see Hook::remove() for more details.
     */
    public function remove(string $action, callable $callback, int $priority = 10): bool
    {
        return $this->directRemove($this->createHookName($action), $callback, $priority);
    }

    /**
     * Removes a callback from a hook without prefixing the hook name.
     *
     * @param string $action The name of the action hook.
     * @param callable $callback The callback function to remove.
     * @param int $priority The priority of the action (default: 10).
     * @return bool True if the action was removed, false otherwise.
     * @see Hook::remove() for more details.
     */
    public function directRemove(string $action, callable $callback, int $priority = 10): bool
    {
        return Hook::remove($action, $callback, $priority);
    }

    /**
     * Check if a hook is runnable.
     *
     * @param string $hookName The name of the hook.
     * @return bool True if the hook is runnable, false otherwise.
     */
    public function runnable(string $hookName): bool
    {
        return $this->directRunnable($this->createHookName($hookName));
    }

    /**
     * Check if a hook is runnable without prefixing the hook name.
     *
     * @param string $hookName The name of the hook.
     * @return bool True if the hook is runnable, false otherwise.
     */
    public function directRunnable(string $hookName): bool
    {
        return Hook::runnable($hookName);
    }
}
