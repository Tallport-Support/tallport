<?php
// A model written for Laravel 8 and older (no return types on the interface
// methods), as modules have them. Loaded by ModuleCompatibilityTest in a
// separate process: an incompatible declaration is a fatal error.
require __DIR__.'/../../../vendor/autoload.php';

trait OldStyleSerializes
{
    public function jsonSerialize()
    {
        return ['serialized' => true];
    }
}

class OldStyleModel extends Illuminate\Database\Eloquent\Model
{
    use OldStyleSerializes;

    public function offsetGet($offset)
    {
        return 'value';
    }

    public function offsetExists($offset)
    {
        return true;
    }
}

$model = new OldStyleModel();
echo json_encode($model).' '.$model['anything'];
