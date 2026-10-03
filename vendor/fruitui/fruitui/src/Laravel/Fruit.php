<?php

namespace FruitUI;

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
    /** Show a toast now; outside a Livewire request it shows on the next page. */
    public static function toast(string $message): void
    {
        $component = self::component();
        $component === null
            ? self::flashToast($message)
            : $component->dispatch('fruit-toast', message: $message);
    }

    /** Show a toast on the next page, after a redirect. */
    public static function flashToast(string $message): void
    {
        session()->flash('fruit-toast', $message);
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
     * The tokens in a Token Field's newline-delimited value, as the browser shows them:
     * trimmed, without blank lines or exact duplicates. Join with "\n" to bind an array back.
     *
     * @return list<string>
     */
    public static function tokens(?string $value): array
    {
        $tokens = array_filter(array_map(trim(...), preg_split('/\r?\n/', $value ?? '')), fn (string $token) => $token !== '');

        return array_values(array_unique($tokens));
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
