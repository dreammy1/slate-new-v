<?php
/**
 * Slate — Hook system.
 *
 * WordPress-style filters and actions. Plugins use this to extend the shell and
 * each other without touching core files.
 *
 *   FILTERS  pass a value through listeners, each may modify it.
 *   ACTIONS  fire an event; listeners run for side effects.
 *
 * Listeners are sorted by priority (lower = earlier). Listeners registered with
 * the same priority run in registration order.
 *
 * Phase 1 A3: migrated from includes/Hook.php into the Slate\Kernel\Event
 * namespace. The old global name `Hook` continues to resolve via a class_alias
 * in src/compat/aliases.php, so every Hook::addAction(...) and
 * class_exists('Hook') keeps working unchanged. Behavior is identical — only the
 * namespace changed. (`slate_log()` resolves to the global function via PHP's
 * namespace function-fallback; `\Throwable` stays globally qualified.)
 */

declare(strict_types=1);

namespace Slate\Kernel\Event;

// NOTE: intentionally NOT `final` — the migration preserves the original class's
// exact surface (it was not final). Tightening happens later, deliberately.
class Hook
{
    /** @var array<string, array<int, array<array{cb: callable, args: int}>>> */
    private static array $filters = [];

    /** @var array<string, array<int, array<array{cb: callable, args: int}>>> */
    private static array $actions = [];

    // ── Filters ───────────────────────────────────────────────

    public static function addFilter(string $name, callable $cb, int $priority = 10, int $acceptedArgs = 1): void
    {
        self::$filters[$name][$priority][] = ['cb' => $cb, 'args' => max(1, $acceptedArgs)];
    }

    /**
     * Apply all registered filters to $value. Listeners may receive additional
     * context as varargs; they must return the (possibly modified) first arg.
     */
    public static function applyFilters(string $name, $value, ...$context)
    {
        if (empty(self::$filters[$name])) return $value;
        $buckets = self::$filters[$name];
        ksort($buckets);
        foreach ($buckets as $listeners) {
            foreach ($listeners as $l) {
                $args = array_slice(array_merge([$value], $context), 0, $l['args']);
                try {
                    $value = call_user_func_array($l['cb'], $args);
                } catch (\Throwable $e) {
                    slate_log("Hook filter '$name' threw: " . $e->getMessage(), 'error');
                }
            }
        }
        return $value;
    }

    public static function removeFilter(string $name, callable $cb, int $priority = 10): bool
    {
        if (empty(self::$filters[$name][$priority])) return false;
        foreach (self::$filters[$name][$priority] as $i => $l) {
            if ($l['cb'] === $cb) {
                unset(self::$filters[$name][$priority][$i]);
                return true;
            }
        }
        return false;
    }

    // ── Actions ───────────────────────────────────────────────

    public static function addAction(string $name, callable $cb, int $priority = 10, int $acceptedArgs = 1): void
    {
        self::$actions[$name][$priority][] = ['cb' => $cb, 'args' => max(0, $acceptedArgs)];
    }

    public static function doAction(string $name, ...$args): void
    {
        if (empty(self::$actions[$name])) return;
        $buckets = self::$actions[$name];
        ksort($buckets);
        foreach ($buckets as $listeners) {
            foreach ($listeners as $l) {
                $callArgs = array_slice($args, 0, $l['args']);
                try {
                    call_user_func_array($l['cb'], $callArgs);
                } catch (\Throwable $e) {
                    slate_log("Hook action '$name' threw: " . $e->getMessage(), 'error');
                }
            }
        }
    }

    public static function removeAction(string $name, callable $cb, int $priority = 10): bool
    {
        if (empty(self::$actions[$name][$priority])) return false;
        foreach (self::$actions[$name][$priority] as $i => $l) {
            if ($l['cb'] === $cb) {
                unset(self::$actions[$name][$priority][$i]);
                return true;
            }
        }
        return false;
    }

    // ── Introspection ─────────────────────────────────────────

    public static function hasFilter(string $name): bool
    {
        return !empty(self::$filters[$name]);
    }

    public static function hasAction(string $name): bool
    {
        return !empty(self::$actions[$name]);
    }

    /** For tests: reset all listeners. */
    public static function reset(): void
    {
        self::$filters = [];
        self::$actions = [];
    }
}
