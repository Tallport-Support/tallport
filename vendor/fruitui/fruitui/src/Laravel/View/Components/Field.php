<?php

namespace FruitUI\View\Components;

use FruitUI\Support\FieldContext;
use Illuminate\View\Component;
use InvalidArgumentException;

/** A scoped association for one control, independent of application props/state. */
class Field extends Component
{
    /** Stacked puts the label above the control; row puts it beside, as in grouped settings. */
    public const LAYOUTS = ['stacked', 'row'];

    public FieldContext $fruitField;

    public function __construct(
        public mixed $controlId = null,
        public mixed $label = null,
        public mixed $description = null,
        public mixed $error = null,
        public mixed $bag = 'default',
        public mixed $layout = 'stacked',
    ) {
        if (! in_array($layout, self::LAYOUTS, true)) {
            throw new InvalidArgumentException('FruitUI Field layout must be one of: '.implode(', ', self::LAYOUTS).'.');
        }
        if ($controlId !== null && (! is_string($controlId) || trim($controlId) === '' || preg_match('/\s/', $controlId))) {
            throw new InvalidArgumentException('FruitUI Field control-id must be a nonempty id without whitespace.');
        }
        if (! is_string($bag) || $bag === '') {
            throw new InvalidArgumentException('FruitUI Field bag must name an error bag.');
        }
        // A label slot replaces the prop; the view checks that one of them has text.
        if ($label !== null && (! is_string($label) || trim($label) === '')) {
            throw new InvalidArgumentException('FruitUI Field requires a nonempty label.');
        }
        if (($description !== null && ! is_string($description)) || ($error !== null && ! is_string($error))) {
            throw new InvalidArgumentException('FruitUI Field description and error must be text strings.');
        }
        $this->fruitField = new FieldContext($controlId, $description, $error, $bag);
    }

    public function render()
    {
        return view('fruit::components.field');
    }
}
