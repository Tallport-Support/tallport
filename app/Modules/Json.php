<?php

namespace App\Modules;

use Illuminate\Filesystem\Filesystem;
use Nwidart\Modules\Collection;

/**
 * A module's module.json, read once: its contents can be passed in (from the
 * modules manifest, App\Modules\Module::json()), and changes made with set()
 * (the "active" flag Tallport keeps in the database) stay.
 */
class Json extends \Nwidart\Modules\Json
{
    public function __construct($path, ?Filesystem $filesystem = null, array $data = [])
    {
        if (!$data) {
            parent::__construct($path, $filesystem);

            return;
        }

        $this->path = (string) $path;
        $this->filesystem = $filesystem ?: new Filesystem();
        $this->attributes = new Collection($data);
    }

    /**
     * The attributes read already, or from the file (not cached per file:
     * the modules manifest is).
     *
     * @return array
     */
    public function getAttributes()
    {
        if ($this->attributes && $this->attributes->toArray()) {
            return $this->attributes->toArray();
        }

        $attributes = json_decode($this->getContents(), 1);
        if (json_last_error() > 0) {
            throw new \Nwidart\Modules\Exceptions\InvalidJsonException('Error processing file: '.$this->getPath().'. Error: '.json_last_error_msg());
        }

        return $attributes;
    }
}
