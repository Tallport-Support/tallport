<?php

namespace App\Routing;

use Illuminate\Routing\RouteUrlGenerator as BaseRouteUrlGenerator;
use Illuminate\Support\Arr;

/**
 * Fills route parameters as Laravel 5.5 did: remaining parameters, null
 * included, fill the placeholders in order, and a null named parameter gives
 * an empty segment instead of an error. Modules (e.g. GlobalMailbox) pass null
 * for required parameters.
 */
class RouteUrlGenerator extends BaseRouteUrlGenerator
{
    /**
     * Replace all of the wildcard parameters for a route path.
     *
     * @param  string  $path
     * @param  array  $parameters
     * @return string
     */
    protected function replaceRouteParameters($path, array &$parameters)
    {
        $path = $this->replaceNamedParameters($path, $parameters);

        $path = preg_replace_callback('/\{.*?\}/', function ($match) use (&$parameters) {
            return (empty($parameters) && ! str_ends_with($match[0], '?}'))
                ? $match[0]
                : $this->encodeParameter(array_shift($parameters));
        }, $path);

        return trim(preg_replace('/\{.*?\?\}/', '', $path), '/');
    }

    /**
     * Replace all of the named parameters in the path.
     *
     * @param  string  $path
     * @param  array  $parameters
     * @return string
     */
    protected function replaceNamedParameters($path, &$parameters)
    {
        return preg_replace_callback('/\{(.*?)(\?)?\}/', function ($m) use (&$parameters) {
            if (isset($parameters[$m[1]])) {
                return $this->encodeParameter(Arr::pull($parameters, $m[1]));
            } elseif (isset($this->defaultParameters[$m[1]])) {
                return $this->encodeParameter($this->defaultParameters[$m[1]]);
            }

            return $m[0];
        }, $path);
    }
}
