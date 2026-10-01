<?php

namespace App\Routing;

use Illuminate\Routing\UrlGenerator as BaseUrlGenerator;
use Illuminate\Support\Arr;

/**
 * Laravel's URL generator as FreeScout uses it:
 * - the root is APP_URL (modules can change it with the url_generator.app_url
 *   filter), because the request doesn't tell the subdirectory reliably;
 * - in a subdirectory installation, routes (registered under the
 *   subdirectory) don't repeat it after APP_URL;
 * - x_ query parameters (e.g. x_embed) are kept in every route URL, xs_ ones
 *   in URLs of the current page;
 * - route parameters are filled as in Laravel 5.5 (RouteUrlGenerator).
 *
 * Bound as "url" by App\Foundation\Application.
 */
class UrlGenerator extends BaseUrlGenerator
{
    /**
     * Get the URL to a named route.
     *
     * @param  \BackedEnum|string  $name
     * @param  mixed  $parameters
     * @param  bool  $absolute
     * @return string
     */
    public function route($name, $parameters = [], $absolute = true)
    {
        if (is_string($name) && $this->routes->getByName($name)) {
            foreach (request()->query() as $param => $value) {
                if (str_starts_with($param, 'x_')
                    || (str_starts_with($param, 'xs_') && $name == \Route::currentRouteName())
                ) {
                    $parameters = Arr::wrap($parameters);
                    $parameters[$param] = $value;
                }
            }
        }

        return parent::route($name, $parameters, $absolute);
    }

    /**
     * Get the base URL for the request.
     *
     * @param  string  $scheme
     * @param  string|null  $root
     * @return string
     */
    public function formatRoot($scheme, $root = null)
    {
        if (is_null($root) && is_null($this->cachedRoot)) {
            $app_url = \Eventy::filter('url_generator.app_url', $this->forcedRoot ?: config('app.url'));

            // Some systems add a slash at the end.
            $this->cachedRoot = rtrim((! $app_url || \Helper::isDefaultAppUrl($app_url)) ? $this->request->root() : $app_url, '/');
        }

        return parent::formatRoot($scheme, $root);
    }

    /**
     * Format the given URL segments into a single URL.
     *
     * @param  string  $root
     * @param  string  $path
     * @param  \Illuminate\Routing\Route|null  $route
     * @return string
     */
    public function format($root, $path, $route = null)
    {
        // Routes are registered under the subdirectory, and APP_URL ends with it.
        $subdirectory = \Helper::getSubdirectory(false, true);
        if ($subdirectory && preg_match('#'.preg_quote($subdirectory).'$#', trim($root, '/'))) {
            $path = preg_replace('#^'.preg_quote($subdirectory).'#', '', '/'.trim($path, '/'));
        }

        return parent::format($root, $path, $route);
    }

    /**
     * Get the Route URL generator instance.
     *
     * @return \App\Routing\RouteUrlGenerator
     */
    protected function routeUrl()
    {
        if (! $this->routeGenerator) {
            $this->routeGenerator = new RouteUrlGenerator($this, $this->request);
        }

        return $this->routeGenerator;
    }
}
