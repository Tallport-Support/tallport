<?php

namespace App\Misc;

/**
 * Named routes for JavaScript (laroute.route('...') in public/js/laroute.js
 * and each module's public/modules/<alias>/js/laroute.js).
 *
 * A route is included when its action has 'laroute' => true (config
 * laroute.filter "only"; "all" includes every route except 'laroute' =>
 * false). The application's file gets the application's routes, a module's
 * file only that module's.
 */
class JsRoutes
{
    /**
     * The routes as [['uri' => ..., 'name' => ...], ...], URIs without the
     * installation's subdirectory.
     *
     * @param  string|null  $module  Module alias, or null for the application.
     * @param  iterable|null  $route_collection  Default: the application's routes.
     * @return array
     */
    public static function routes($module = null, $route_collection = null)
    {
        $filter = config('laroute.filter', 'all');
        $subdirectory = \Helper::getSubdirectory(true);
        $routes = [];

        foreach ($route_collection ?? app('router')->getRoutes() as $route) {
            $action = $route->getAction();
            $laroute = $action['laroute'] ?? null;
            if (!$route->getName()
                || ($filter == 'all' && $laroute === false)
                || ($filter == 'only' && $laroute !== true)
            ) {
                continue;
            }

            // Module routes go only into that module's file.
            preg_match('/^Modules\\\\([^\\\\]+)\\\\/', $action['controller'] ?? '', $m);
            $route_module = isset($m[1]) ? strtolower($m[1]) : null;
            if ($route_module !== ($module ? strtolower($module) : null)) {
                continue;
            }

            $uri = $route->uri();
            if ($subdirectory) {
                $uri = preg_replace('#^'.preg_quote($subdirectory).'#', '', $uri);
            }
            $routes[] = ['uri' => $uri, 'name' => $route->getName()];
        }

        return $routes;
    }

    /**
     * Write a laroute JS file from a template ($NAMESPACE$, $ROUTES$,
     * $ABSOLUTE$, $ROOTURL$ and $PREFIX$ are filled in).
     *
     * @param  string  $template  Template path, relative to the application.
     * @param  string  $file  Path of the JS file to write.
     * @param  string|null  $module  Module alias, or null for the application.
     * @param  array  $options  'namespace' and 'prefix' instead of the config.
     * @return string The file path.
     */
    public static function generate($template, $file, $module = null, array $options = [])
    {
        $data = [
            'namespace' => $options['namespace'] ?? config('laroute.namespace'),
            'routes'    => json_encode(self::routes($module), JSON_PRETTY_PRINT),
            'absolute'  => config('laroute.absolute', false) ? 'true' : 'false',
            'rootUrl'   => config('app.url', ''),
            'prefix'    => $options['prefix'] ?? config('laroute.prefix', ''),
        ];

        $js = file_get_contents(base_path($template));
        foreach ($data as $key => $value) {
            $js = str_ireplace('$'.strtoupper($key).'$', (string) $value, $js);
        }

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $js);

        return $file;
    }
}
