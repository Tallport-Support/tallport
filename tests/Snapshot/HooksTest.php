<?php

namespace Tests\Snapshot;

use Tests\Concerns\AssertsSnapshots;
use Tests\TestCase;

/**
 * Every Eventy action and filter the application fires, with the number of
 * arguments it passes and where it is fired from. Modules hook into these,
 * so renaming a hook or changing its arguments breaks them.
 *
 * Found by reading the code (app/ and the compiled Blade views), so hooks are
 * listed whether or not a test happens to reach them.
 */
class HooksTest extends TestCase
{
    use AssertsSnapshots;

    public function testHooks()
    {
        $hooks = [];

        $php_files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())),
            '/\.php$/'
        );
        foreach ($php_files as $file) {
            $this->collectHooks(file_get_contents($file->getPathname()), $file->getPathname(), $hooks);
        }

        $blade = $this->app['blade.compiler'];
        $views = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))),
            '/\.blade\.php$/'
        );
        foreach ($views as $file) {
            $this->collectHooks($blade->compileString(file_get_contents($file->getPathname())), $file->getPathname(), $hooks);
        }

        foreach ($hooks as &$hook) {
            $hook['files'] = array_values(array_unique($hook['files']));
            sort($hook['files']);
        }
        unset($hook);
        ksort($hooks);

        $this->assertMatchesSnapshot('hooks', array_values($hooks));
    }

    protected function collectHooks($code, $path, array &$hooks)
    {
        $tokens = array_values(array_filter(token_get_all($code), function ($token) {
            return !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]);
        }));
        $file = ltrim(str_replace(base_path(), '', $path), '/');

        for ($i = 0; $i < count($tokens) - 3; $i++) {
            if (!is_array($tokens[$i]) || !in_array(ltrim($tokens[$i][1], '\\'), ['Eventy'])
                || !is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_DOUBLE_COLON
                || !is_array($tokens[$i + 2]) || !in_array($tokens[$i + 2][1], ['action', 'filter'])
                || $tokens[$i + 3] !== '('
            ) {
                continue;
            }

            // Split the call's arguments on top-level commas.
            $args = [[]];
            $depth = 0;
            for ($j = $i + 3; $j < count($tokens); $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if (in_array($text, ['(', '[', '{'])) {
                    $depth++;
                    if ($depth == 1) {
                        continue;
                    }
                } elseif (in_array($text, [')', ']', '}'])) {
                    $depth--;
                    if ($depth == 0) {
                        break;
                    }
                } elseif ($text === ',' && $depth == 1) {
                    $args[] = [];
                    continue;
                }
                $args[count($args) - 1][] = $tokens[$j];
            }
            if (!end($args)) {
                // Trailing comma.
                array_pop($args);
            }

            $name_tokens = $args[0];
            if (count($name_tokens) == 1 && is_array($name_tokens[0]) && $name_tokens[0][0] === T_CONSTANT_ENCAPSED_STRING) {
                $name = substr($name_tokens[0][1], 1, -1);
            } else {
                $name = '(dynamic) '.implode('', array_map(function ($token) {
                    return is_array($token) ? $token[1] : $token;
                }, $name_tokens));
            }

            $type = $tokens[$i + 2][1];
            $arg_count = count($args) - 1;
            $key = $name.' '.$type.' '.$arg_count;
            $hooks[$key]['type'] = $type;
            $hooks[$key]['name'] = $name;
            $hooks[$key]['arguments'] = $arg_count;
            $hooks[$key]['files'][] = $file;
        }
    }
}
