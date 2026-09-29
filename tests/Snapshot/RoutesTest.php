<?php

namespace Tests\Snapshot;

use Illuminate\Support\Facades\Route;
use Tests\Concerns\AssertsSnapshots;
use Tests\TestCase;

/**
 * Every HTTP route with its methods, name, action and middleware, plus the
 * actions each ajax endpoint dispatches on. Routes are the application's
 * public surface, so any change here should be deliberate.
 */
class RoutesTest extends TestCase
{
    use AssertsSnapshots;

    public function testRoutes()
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();
            $routes[] = [
                'methods'    => array_values(array_diff($route->methods(), ['HEAD'])),
                'uri'        => $route->uri(),
                'name'       => $route->getName(),
                'action'     => $action === 'Closure' ? 'Closure' : str_replace('App\\Http\\Controllers\\', '', $action),
                'middleware' => array_values(array_map(function ($middleware) {
                    return is_string($middleware) ? $middleware : get_class($middleware);
                }, $route->gatherMiddleware())),
                'where'      => $route->wheres ?: new \stdClass(),
            ];
        }

        usort($routes, function ($a, $b) {
            return [$a['uri'], implode(',', $a['methods'])] <=> [$b['uri'], implode(',', $b['methods'])];
        });

        $this->assertMatchesSnapshot('routes', $routes);
    }

    /**
     * Ajax endpoints switch on $request->action; the list of actions is part of
     * the contract with the frontend (public/js/main.js) and with modules.
     */
    public function testAjaxActions()
    {
        $actions = [];
        foreach (glob(app_path('Http/Controllers/*.php')) as $file) {
            $class = 'App\\Http\\Controllers\\'.basename($file, '.php');
            $lines = file($file);
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                if ($method->class !== $class || stripos($method->name, 'ajax') !== 0) {
                    continue;
                }
                $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
                preg_match_all("/^\s*case\s+'([^']+)'\s*:/m", $body, $m);
                if ($m[1]) {
                    $cases = array_values(array_unique($m[1]));
                    sort($cases);
                    $actions[basename($file, '.php').'@'.$method->name] = $cases;
                }
            }
        }
        ksort($actions);

        $this->assertMatchesSnapshot('ajax_actions', $actions);
    }
}
