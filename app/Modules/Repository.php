<?php

namespace App\Modules;

use Nwidart\Modules\Collection;
use App\Modules\Json;

/**
 * nwidart's module repository with FreeScout's additions: active flags from
 * the modules table, an in-memory copy of the modules cache, lookups by
 * alias, and module options. Bound as "modules" by AppServiceProvider.
 */
class Repository extends \Nwidart\Modules\Laravel\Repository
{
    /**
     * Modules cache, kept in memory.
     *
     * @var array|null
     */
    protected $cache;

    /**
     * Active flags by module alias.
     *
     * @var array
     */
    public static $active_cache = [];

    /**
     * {@inheritdoc}
     */
    protected function createModule(...$args)
    {
        return new Module(...$args);
    }

    /**
     * Get & scan all modules, with the active flag from the modules table.
     *
     * @return array
     */
    public function scan()
    {
        $modules = [];

        foreach ($this->getScanPaths() as $path) {
            $manifests = $this->app['files']->glob("{$path}/module.json");

            is_array($manifests) || $manifests = [];

            foreach ($manifests as $manifest) {
                $name = Json::make($manifest)->get('name');

                $modules[$name] = $this->createModule($this->app, $name, dirname($manifest));
                // The cached configuration is rebuilt by tallport:clear-cache.
                $alias = $modules[$name]->getAlias();
                if ($alias) {
                    $modules[$name]->json()->set('active', (int) \App\Module::isActive($alias));
                }
            }
        }

        return $modules;
    }

    /**
     * Get all modules.
     *
     * @param  bool  $forceScan
     * @return array
     */
    public function all($forceScan = false) : array
    {
        if (!$this->config('cache.enabled') || $forceScan) {
            return $this->scan();
        }

        return $this->formatCached($this->getCached());
    }

    /**
     * Forget the modules cache.
     */
    public function clearCache()
    {
        $this->cache = null;
        $this->app['cache']->forget($this->config('cache.key'));
    }

    /**
     * Format the cached data as array of modules.
     *
     * @param  array  $cached
     * @return array
     */
    protected function formatCached($cached)
    {
        $modules = [];

        foreach ($cached as $name => $module) {
            // The path the module has been scanned from, with symlinks not
            // resolved (modules cached before it was stored only have 'path').
            $path = !empty($module['scanned_path']) ? $module['scanned_path'] : $module['path'];

            $modules[$name] = $this->createModule($this->app, $name, $path);
        }

        return $modules;
    }

    /**
     * Get cached modules.
     *
     * @return array
     */
    public function getCached()
    {
        if ($this->cache) {
            return $this->cache;
        }

        return $this->app['cache']->remember($this->config('cache.key'), $this->config('cache.lifetime'), function () {
            $modules = $this->scan();

            $array = (new Collection($modules))->toArray();
            // Collection::toArray() only keeps the resolved path, but the path
            // the module has been scanned from is needed to build migration
            // paths relative to base_path().
            foreach ($array as $key => $item) {
                if (isset($modules[$key])) {
                    $array[$key]['scanned_path'] = $modules[$key]->getScannedPath();
                }
            }

            $this->cache = $array;

            return $array;
        });
    }

    /**
     * Get active modules.
     *
     * @return array
     */
    public function getActive() : array
    {
        return $this->enabled();
    }

    /**
     * Find a specific module by its alias (case-insensitive).
     *
     * @param  string  $alias
     * @return \App\Modules\Module|null
     */
    public function findByAlias($alias)
    {
        foreach ($this->all() as $module) {
            if (strtolower($module->getAlias()) === $alias) {
                return $module;
            }
        }
    }

    /**
     * Check whether a module is active.
     *
     * @param  string  $alias
     * @param  bool  $use_cache
     * @return bool
     */
    public function isActive($alias, $use_cache = true)
    {
        if ($use_cache && isset(self::$active_cache[$alias])) {
            return self::$active_cache[$alias];
        }

        $module = $this->findByAlias($alias);
        $is_active = $module && $module->active();
        self::$active_cache[$alias] = $is_active;

        return $is_active;
    }

    /**
     * Get the path of a module by its alias, '' if there is no such module.
     *
     * @param  string  $module_alias
     * @return string
     */
    public function getModulePathByAlias($module_alias)
    {
        $module = $this->findByAlias($module_alias);

        return $module ? $module->getPath().'/' : '';
    }

    /**
     * Get a module option; the default comes from the module's config.
     *
     * @param  string  $module_alias
     * @param  string  $option_name
     * @param  mixed  $default
     * @return mixed
     */
    public function getOption($module_alias, $option_name, $default = false)
    {
        if (func_num_args() == 2) {
            $options = \Config::get(strtolower($module_alias).'.options');
            if (isset($options[$option_name]) && isset($options[$option_name]['default'])) {
                $default = $options[$option_name]['default'];
            }
        }

        return \Option::get($module_alias.'.'.$option_name, $default);
    }

    /**
     * Set a module option.
     *
     * @param  string  $module_alias
     * @param  string  $option_name
     * @param  mixed  $option_value
     * @return mixed
     */
    public function setOption($module_alias, $option_name, $option_value)
    {
        return \Option::set(strtolower($module_alias).'.'.$option_name, $option_value);
    }

    /**
     * Get the public URL path of a module.
     *
     * @param  string  $module_alias
     * @return string
     */
    public function getPublicPath($module_alias)
    {
        return '/modules/'.$module_alias;
    }
}
