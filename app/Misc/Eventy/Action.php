<?php

namespace App\Misc\Eventy;

class Action extends \TorMorten\Eventy\Action
{
    use ListenersByHook;

    /**
     * Calls the listeners of an action.
     *
     * @param  string  $action
     * @param  array  $args
     */
    public function fire($action, $args)
    {
        foreach ($this->getListeners($action) as $listener) {
            call_user_func_array($this->getFunction($listener['callback']), $this->listenerArguments($listener, $args));
        }
    }
}
