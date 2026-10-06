<?php

namespace App\Misc;

/**
 * The \Minify facade (FreeScout used devfactory/minify): joins JS and CSS
 * files into one minified build file in public/, named by the files and
 * their modification times, so a changed file makes a new build. In the
 * environments in config('minify.config.ignore_environments') the files are
 * linked one by one. Modules add their files through the "javascripts" and
 * "stylesheets" filters (resources/views/layouts/app.blade.php).
 *
 * Usage: {!! Minify::javascript(['/js/a.js', '/js/b.js']) !!}
 */
class Minify
{
    protected $config;

    protected $environment;

    protected $type;

    protected $files = [];

    protected $attributes = [];

    protected $full_url = false;

    protected $only_url = false;

    public function __construct(array $config, $environment)
    {
        $this->config = $config;
        $this->environment = $environment;
    }

    public function javascript($files, $attributes = [])
    {
        return $this->bundle('js', (array) $files, $attributes);
    }

    public function stylesheet($files, $attributes = [])
    {
        return $this->bundle('css', (array) $files, $attributes);
    }

    public function javascriptDir($dir, $attributes = [])
    {
        return $this->bundle('js', $this->filesInDir($dir, 'js'), $attributes);
    }

    public function stylesheetDir($dir, $attributes = [])
    {
        return $this->bundle('css', $this->filesInDir($dir, 'css'), $attributes);
    }

    public function withFullUrl()
    {
        $this->full_url = true;

        return $this;
    }

    public function onlyUrl()
    {
        $this->only_url = true;

        return $this;
    }

    /**
     * The build file's URL; null where minifying is off (the files are linked one by one).
     */
    public function url()
    {
        return $this->minifyHere() ? ($this->config[$this->type.'_url_path'] ?? $this->config[$this->type.'_build_path']).$this->build() : null;
    }

    public function __toString()
    {
        $base_url = $this->full_url ? request()->root() : '';

        if (!$this->minifyHere()) {
            $html = '';
            foreach ($this->files as $file) {
                $html .= $this->tag($this->isExternal($file) ? $file : $base_url.$file);
            }

            return $html;
        }

        $url = $base_url.($this->config[$this->type.'_url_path'] ?? $this->config[$this->type.'_build_path']).$this->build();

        return $this->only_url ? $url : $this->tag($url);
    }

    /**
     * A new bundle (the facade is a singleton).
     */
    protected function bundle($type, array $files, $attributes)
    {
        $bundle = clone $this;
        $bundle->type = $type;
        $bundle->files = array_values($files);
        $bundle->attributes = (array) $attributes;
        $bundle->full_url = $bundle->only_url = false;

        foreach ($bundle->files as $file) {
            if (!$bundle->isExternal($file) && !file_exists(public_path($file))) {
                throw new \InvalidArgumentException("File '".public_path($file)."' does not exist");
            }
        }

        return $bundle;
    }

    protected function minifyHere()
    {
        return !in_array($this->environment, $this->config['ignore_environments'] ?? []);
    }

    /**
     * Make the build file if needed; its name.
     */
    protected function build()
    {
        $dir = public_path($this->config[$this->type.'_build_path']);
        $hash = md5(implode('-', $this->files).($this->config['hash_salt'] ?? ''));
        $time = 0;
        if (empty($this->config['disable_mtime'])) {
            foreach ($this->files as $file) {
                $time += $this->isExternal($file) ? hexdec(substr(md5($file), 0, 8)) : filemtime(public_path($file));
            }
        }
        $filename = $hash.($time ?: '').'.'.$this->type;

        if (file_exists($dir.$filename)) {
            return $filename;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Buildpath '{$dir}' does not exist");
        }
        // Builds of the same files from before they changed.
        foreach (glob($dir.$hash.'*') ?: [] as $old) {
            @unlink($old);
        }

        if ($this->type == 'js') {
            // Each file on its own; ones already minified (vendor builds) as they are: JShrink
            // mangles a template literal inside another's ${…} (FruitUI's build has many).
            $minified = '';
            foreach ($this->files as $file) {
                $contents = $this->contents($file);
                $minified .= ($this->isMinified($file) ? $contents : \JShrink\Minifier::minify($contents)).";\n";
            }
        } else {
            $contents = '';
            foreach ($this->files as $file) {
                $contents .= $this->contents($file)."\n";
            }
            $minified = (new \MatthiasMullie\Minify\CSS($contents))->minify();
        }

        if (file_put_contents($dir.$filename, $minified) === false) {
            throw new \RuntimeException("File '{$dir}{$filename}' cannot be saved");
        }

        return $filename;
    }

    /**
     * A script that comes minified: a vendor build or a .min.js file.
     */
    protected function isMinified($file)
    {
        $path = (string) parse_url($file, PHP_URL_PATH);

        return str_starts_with(ltrim($path, '/'), 'vendor/') || str_ends_with($path, '.min.js');
    }

    protected function contents($file)
    {
        if (!$this->isExternal($file)) {
            return file_get_contents(public_path($file));
        }

        $contents = @file_get_contents(str_starts_with($file, '//') ? 'https:'.$file : $file);
        if ($contents === false) {
            throw new \RuntimeException("File '{$file}' does not exist");
        }

        return $contents;
    }

    protected function tag($url)
    {
        if ($this->type == 'js') {
            return '<script '.$this->attributeString(['src' => $url] + $this->attributes).'></script>'.PHP_EOL;
        }

        return '<link '.$this->attributeString(['href' => $url, 'rel' => 'stylesheet'] + $this->attributes).'>'.PHP_EOL;
    }

    protected function attributeString(array $attributes)
    {
        $html = [];
        foreach ($attributes as $key => $value) {
            if (is_numeric($key)) {
                $key = $value;
            }
            if (is_bool($value)) {
                $html[] = $key;
            } elseif (!is_null($value)) {
                $html[] = $key.'="'.htmlentities($value, ENT_QUOTES, 'UTF-8', false).'"';
            }
        }

        return implode(' ', $html);
    }

    protected function isExternal($file)
    {
        return (bool) preg_match('/^(https?:)?\/\//', $file);
    }

    protected function filesInDir($dir, $extension)
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(public_path($dir), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() == $extension) {
                $files[] = str_replace(public_path(), '', $file->getPathname());
            }
        }
        empty($this->config['reverse_sort']) ? sort($files) : rsort($files);

        return $files;
    }
}
