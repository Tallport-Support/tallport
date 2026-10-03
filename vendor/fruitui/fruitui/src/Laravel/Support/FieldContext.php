<?php

namespace FruitUI\Support;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ComponentAttributeBag;
use InvalidArgumentException;

/**
 * One Field's label/description/error association, shared with its child control.
 *
 * The child adapter renders before the Field's own markup, so it settles the
 * control's id and validation message, and the Field then labels and displays them.
 */
final class FieldContext
{
    private ?string $boundId = null;

    private ?string $resolved = null;

    public function __construct(
        private readonly ?string $controlId,
        public readonly ?string $description,
        private readonly ?string $error,
        private readonly string $bag = 'default',
    ) {}

    /**
     * Associate the child control: settle its id and resolve its error.
     *
     * The id is control-id, the child's own id, or one derived from its wire:model or name.
     */
    public function bind(ComponentAttributeBag $attributes): string
    {
        if ($this->boundId !== null) {
            throw new InvalidArgumentException('FruitUI Field associates one control; group related choices in a Fieldset.');
        }
        $own = $attributes->get('id');
        if (is_string($own) && $own !== '') {
            if ($this->controlId !== null && $own !== $this->controlId) {
                throw new InvalidArgumentException('FruitUI Field control-id must match its control id.');
            }
            $this->boundId = $own;
        } else {
            $key = self::errorKey($attributes);
            $this->boundId = $this->controlId ?? ($key === null ? null : 'field-'.str_replace('.', '-', $key));
        }
        if ($this->boundId === null) {
            throw new InvalidArgumentException('FruitUI Field needs a control-id, or a control with an id, wire:model or name.');
        }
        if ($this->error === null) {
            $this->resolved = $this->lookup(self::errorKey($attributes));
        }

        return $this->boundId;
    }

    public function id(): string
    {
        return $this->boundId ?? $this->controlId
            ?? throw new InvalidArgumentException('FruitUI Field needs a control-id when its control is plain HTML.');
    }

    public function descriptionId(): string
    {
        return $this->id().'-description';
    }

    public function errorId(): string
    {
        return $this->id().'-error';
    }

    /** An explicit error wins; null looks up the shared validation errors; an empty string means none. */
    public function error(): ?string
    {
        return $this->error === null ? $this->resolved : ($this->error === '' ? null : $this->error);
    }

    private function lookup(?string $key): ?string
    {
        $errors = app('view')->shared('errors');
        if ($errors instanceof ViewErrorBag) {
            $errors = $errors->getBag($this->bag);
        }
        $message = $key !== null && $errors instanceof MessageBag ? $errors->first($key) : '';

        return $message === '' ? null : $message;
    }

    /** wire:model="form.email" → form.email; name="items[0][title]" → items.0.title; name="tags[]" → tags. */
    private static function errorKey(ComponentAttributeBag $attributes): ?string
    {
        foreach ($attributes->all() as $name => $value) {
            if (is_string($value) && $value !== '' && preg_match('/^wire:model(\.|$)/', $name)) {
                return $value;
            }
        }
        $name = $attributes->get('name');
        if (! is_string($name) || $name === '') {
            return null;
        }

        return str_replace(['][', '[', ']'], ['.', '.', ''], preg_replace('/\[\]$/', '', $name));
    }
}
