<?php

namespace App\Misc\Eventy;

class Filter extends \TorMorten\Eventy\Filter
{
    use ListenersByHook;

    /**
     * Passes the value (the first argument) through the listeners of a filter.
     *
     * @param  string  $action
     * @param  array  $args
     * @return mixed
     */
    public function fire($action, $args)
    {
        $this->value = isset($args[0]) ? $args[0] : '';
        foreach ($this->getListeners($action) as $listener) {
            $args[0] = $this->value;
            $this->value = call_user_func_array($this->getFunction($listener['callback']), $this->listenerArguments($listener, $args));
        }

        return $this->value;
    }
}
