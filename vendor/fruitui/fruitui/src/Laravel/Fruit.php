<?php

namespace FruitUI;

use InvalidArgumentException;
use Livewire\Component;
use Livewire\Livewire;
use LogicException;

/**
 * Server-side feedback, callable from Livewire components, form objects, actions and controllers.
 *
 * Inside a Livewire request the messages are dispatched as browser events that
 * <x-fruit::toaster /> and named <x-fruit::dialog> elements receive.
 */
final class Fruit
{
    /**
     * The named accent colors, like the system accent color: data-fruit-accent on the .fruit-ui
     * scope or an ancestor. Validate a stored choice with Rule::in(Fruit::ACCENTS).
     */
    public const ACCENTS = ['blue', 'purple', 'pink', 'red', 'orange', 'yellow', 'green', 'graphite'];

    /** Toast tones: danger toasts are announced assertively and stay twice as long. */
    public const TOAST_TONES = ['neutral', 'success', 'danger'];

    /** Show a toast now; outside a Livewire request it shows on the next page. */
    public static function toast(string $message, string $tone = 'neutral'): void
    {
        self::requireTone($tone);
        $component = self::component();
        $component === null
            ? self::flashToast($message, $tone)
            : $component->dispatch('fruit-toast', message: $message, tone: $tone);
    }

    /** Show a toast on the next page, after a redirect. */
    public static function flashToast(string $message, string $tone = 'neutral'): void
    {
        self::requireTone($tone);
        session()->flash('fruit-toast', $message);
        session()->flash('fruit-toast-tone', $tone);
    }

    public static function openDialog(string $name): void
    {
        self::requireComponent(__FUNCTION__)->dispatch('fruit-dialog-open', name: $name);
    }

    public static function closeDialog(string $name): void
    {
        self::requireComponent(__FUNCTION__)->dispatch('fruit-dialog-close', name: $name);
    }

    /**
     * The tokens in a Token Field's value, as the browser shows them: trimmed, without blank
     * entries or exact duplicates. Accepts the newline-delimited text or, from submit="list",
     * the posted array. Join with "\n" to bind an array back.
     *
     * @param  string|array<int, mixed>|null  $value
     * @return list<string>
     */
    public static function tokens(string|array|null $value): array
    {
        $lines = is_array($value)
            ? array_filter($value, is_string(...))
            : preg_split('/\r?\n/', $value ?? '');
        $tokens = array_filter(array_map(trim(...), $lines), fn (string $token) => $token !== '');

        return array_values(array_unique($tokens));
    }

    private static function requireTone(string $tone): void
    {
        if (! in_array($tone, self::TOAST_TONES, true)) {
            throw new InvalidArgumentException('FruitUI toast tone must be one of: '.implode(', ', self::TOAST_TONES).'.');
        }
    }

    private static function component(): ?Component
    {
        // Livewire::current() returns false outside a component request.
        return class_exists(Livewire::class) && app()->bound('livewire') ? (Livewire::current() ?: null) : null;
    }

    private static function requireComponent(string $method): Component
    {
        return self::component()
            ?? throw new LogicException("Fruit::{$method}() dispatches to the browser and needs a Livewire request; bind the dialog with wire:model otherwise.");
    }
}
