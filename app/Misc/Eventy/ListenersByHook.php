<?php

namespace App\Misc\Eventy;

/**
 * Eventy keeps all listeners in one collection and filters and sorts it on
 * every hook call. FreeScout fires hundreds of hooks per request, so listeners
 * are kept per hook, sorted by priority when added.
 */
trait ListenersByHook
{
    public function __construct()
    {
        $this->listeners = [];
    }

    /**
     * Adds a listener.
     *
     * @param  string  $hook
     * @param  mixed  $callback
     * @param  int  $priority
     * @param  int  $arguments
     * @return $this
     */
    public function listen($hook, $callback, $priority = 20, $arguments = 1)
    {
        $this->listeners[$hook][] = [
            'callback'  => $callback,
            'priority'  => $priority,
            'arguments' => $arguments,
        ];
        // Stable, so listeners with the same priority run in the order added.
        usort($this->listeners[$hook], function ($a, $b) {
            return (int) $a['priority'] - (int) $b['priority'];
        });

        return $this;
    }

    /**
     * Removes a listener.
     *
     * @param  string  $hook
     * @param  mixed  $callback
     * @param  int  $priority
     */
    public function remove($hook, $callback, $priority = 20)
    {
        foreach ($this->listeners[$hook] ?? [] as $key => $listener) {
            if ($listener['callback'] == $callback && $listener['priority'] == $priority) {
                unset($this->listeners[$hook][$key]);
            }
        }
    }

    /**
     * Removes all listeners of a hook, or of all hooks.
     *
     * @param  string|null  $hook
     */
    public function removeAll($hook = null)
    {
        if ($hook) {
            unset($this->listeners[$hook]);
        } else {
            $this->listeners = [];
        }
    }

    /**
     * The listeners of a hook, by priority.
     *
     * @param  string  $hook
     * @return array
     */
    public function getListeners($hook = null)
    {
        return $this->listeners[$hook] ?? [];
    }

    /**
     * The arguments for a listener: missing ones are null, so a listener
     * asking for more arguments than the hook passes still gets called.
     *
     * @param  array  $listener
     * @param  array  $args
     * @return array
     */
    protected function listenerArguments($listener, $args)
    {
        $parameters = [];
        for ($i = 0; $i < $listener['arguments']; $i++) {
            $parameters[] = $args[$i] ?? null;
        }

        return $parameters;
    }
}
