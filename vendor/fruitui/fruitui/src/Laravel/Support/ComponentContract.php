<?php

namespace FruitUI\Support;

use FruitUI\Fruit;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;
use InvalidArgumentException;

/** Small, explicit contracts for the native controls exposed by Blade. */
final class ComponentContract
{
    public const INPUT_TYPES = ['text', 'email', 'password', 'search', 'tel', 'url'];

    public const DATE_TYPES = ['date', 'datetime-local', 'month', 'week'];

    public const BUTTON_VARIANTS = ['default', 'primary', 'ghost', 'danger'];

    public const BUTTON_TYPES = ['button', 'submit', 'reset'];

    public const BUTTON_SIZES = ['small', 'regular', 'large'];

    public const ITEM_ROW_VARIANTS = ['quiet', 'filled'];

    private const ENHANCED = ['data-fruit-control', 'hidden'];

    /** Date and color pickers own their popup's ARIA association on the native input. */
    private const PICKER = ['aria-haspopup', 'aria-controls', 'aria-expanded'];

    /**
     * roles: explicit roles a caller may repeat or choose; every other role is rejected.
     * type: the fixed native type a caller may repeat; any other type is rejected.
     * options: allowed values for each documented prop.
     * owns: attributes the component manages; literal values and client bindings are rejected.
     * emits: fixed attributes the template renders itself, removed from the caller's bag.
     * requires: an attribute the native element needs, given literally or as a client binding.
     */
    private const CONTRACTS = [
        'alert' => ['roles' => ['status', 'alert', 'group'], 'options' => ['tone' => ['info', 'success', 'warning', 'danger']]],
        'attachment' => ['roles' => ['link'], 'requires' => 'href'],
        'avatar' => [],
        'badge' => ['roles' => ['status'], 'options' => ['tone' => ['neutral', 'accent', 'success', 'warning', 'danger'], 'variant' => ['filled', 'outline']]],
        'button' => ['roles' => ['button'], 'options' => ['variant' => self::BUTTON_VARIANTS, 'type' => self::BUTTON_TYPES, 'size' => self::BUTTON_SIZES]],
        'copy-button' => ['roles' => ['button'], 'type' => 'button', 'options' => ['variant' => ['default', 'primary', 'ghost'], 'size' => self::BUTTON_SIZES]],
        'card' => ['roles' => ['group', 'region']],
        'checkbox' => ['roles' => ['checkbox'], 'type' => 'checkbox', 'emits' => ['type', 'role']],
        'color' => ['type' => 'color', 'emits' => ['type'], 'owns' => self::PICKER, 'message' => 'owns its picker popup association'],
        'combobox' => ['owns' => [...self::ENHANCED, 'multiple', 'size'], 'message' => 'owns enhancement visibility and its single value contract', 'options' => ['search' => ['local', 'server']]],
        'composer' => ['roles' => ['form']],
        'date' => ['options' => ['type' => self::DATE_TYPES], 'owns' => self::PICKER, 'message' => 'owns its picker popup association'],
        'description-list' => [],
        'dialog' => ['roles' => ['dialog', 'alertdialog'], 'options' => ['size' => ['medium', 'large']]],
        'remote-dialog' => [],
        'disclosure' => ['roles' => ['group']],
        'editor' => ['owns' => self::ENHANCED, 'message' => 'owns enhancement visibility and its single value contract', 'options' => ['paste' => ['rich', 'plain']]],
        'empty-state' => ['roles' => ['group', 'region']],
        'field' => ['roles' => ['group']],
        'form-section' => ['roles' => ['region', 'group']],
        'fieldset' => ['roles' => ['group']],
        'file' => ['type' => 'file', 'emits' => ['type']],
        'floating-disclosure' => ['roles' => ['group'], 'options' => ['placement' => ['below', 'above']]],
        'input' => ['options' => ['type' => self::INPUT_TYPES]],
        'item-list' => ['roles' => ['list'], 'options' => ['selection' => ['none', 'multiple']]],
        'selectable-item-list' => ['roles' => ['list'], 'owns' => ['x-data', 'data-fruit-selection', 'data-selecting'], 'message' => 'owns its fruitListSelection helper when selection is multiple. Put application state on a parent'],
        'list-header' => [],
        'item-row' => ['roles' => ['button'], 'type' => 'button', 'options' => ['variant' => self::ITEM_ROW_VARIANTS]],
        'item-link' => ['roles' => ['link'], 'requires' => 'href', 'options' => ['variant' => self::ITEM_ROW_VARIANTS]],
        'menu' => ['roles' => ['group'], 'options' => ['placement' => ['below', 'above']], 'owns' => ['x-data'], 'message' => 'owns its Alpine keyboard helper. Put application state on a parent'],
        'menu-item' => ['roles' => ['menuitem'], 'type' => 'button', 'options' => ['variant' => ['default', 'danger']]],
        'menu-checkbox' => ['roles' => ['menuitemcheckbox'], 'type' => 'button'],
        'menu-radio' => ['roles' => ['menuitemradio'], 'type' => 'button'],
        'menu-link' => ['roles' => ['menuitem'], 'requires' => 'href'],
        'menu-separator' => ['roles' => ['separator']],
        'menu-group' => ['roles' => ['group']],
        'menu-trigger' => ['roles' => ['button']],
        'context-menu' => ['roles' => ['menu'], 'owns' => ['x-data', 'hidden'], 'message' => 'owns its fruitContextMenu helper and visibility. Put application state on a parent'],
        'meter' => ['roles' => ['meter']],
        'number' => ['type' => 'number', 'emits' => ['type']],
        'pagination' => ['roles' => ['navigation']],
        'pane' => ['roles' => ['group', 'region']],
        'progress' => ['roles' => ['progressbar']],
        'radio' => ['roles' => ['radio'], 'type' => 'radio', 'emits' => ['type', 'role']],
        'range' => ['type' => 'range', 'emits' => ['type']],
        'section-nav' => ['roles' => ['navigation']],
        'select' => [],
        'sidebar' => ['roles' => ['navigation']],
        'sidebar-group' => ['roles' => ['group']],
        'sidebar-item' => ['roles' => ['link'], 'requires' => 'href'],
        'splitter' => ['roles' => ['separator'], 'options' => ['edge' => ['start', 'end']], 'owns' => ['x-data'], 'message' => 'owns its orientation, focus, controlled pane and Alpine helper'],
        'search' => ['type' => 'search', 'emits' => ['type']],
        'segment' => ['roles' => ['radio'], 'type' => 'radio', 'emits' => ['type', 'role']],
        'segmented' => ['roles' => ['group', 'radiogroup']],
        'spinner' => [],
        'chip' => [],
        'presence' => ['owns' => ['data-available'], 'message' => 'renders data-available from its available prop'],
        'toolbar' => ['roles' => ['group', 'region']],
        'back-link' => ['roles' => ['link'], 'requires' => 'href'],
        'upload-list' => ['roles' => ['list']],
        'upload-row' => [],
        'notification-group' => ['roles' => ['list']],
        'notification' => ['roles' => ['link'], 'requires' => 'href'],
        'typing' => ['roles' => ['status']],
        'dropzone' => ['type' => 'file', 'emits' => ['type']],
        'command-palette' => ['roles' => ['dialog'], 'owns' => ['x-data'], 'message' => 'owns its fruitCommandPalette helper. Put application state on a parent'],
        'command' => ['roles' => ['option'], 'type' => 'button'],
        'command-link' => ['roles' => ['option'], 'requires' => 'href'],
        'command-group' => ['roles' => ['group']],
        'autocomplete' => ['owns' => ['x-data'], 'message' => 'owns its fruitAutocomplete helper. Put application state on a parent'],
        'selection-bar' => ['roles' => ['region'], 'owns' => ['x-data'], 'message' => 'owns its fruitSelectionBar helper; bind data-count (for example x-bind:data-count) to update it from script'],
        'skeleton' => [],
        'divider' => ['roles' => ['separator'], 'options' => ['tone' => ['neutral', 'accent'], 'align' => ['center', 'start']]],
        'message' => ['roles' => ['article', 'listitem'], 'options' => ['layout' => ['inline', 'stacked'], 'variant' => ['default', 'note', 'generated'], 'direction' => ['incoming', 'outgoing']]],
        'thread' => ['roles' => ['list']],
        'message-event' => ['roles' => ['note', 'listitem']],
        'timeline' => ['roles' => ['list']],
        'timeline-item' => [],
        'breadcrumbs' => ['roles' => ['navigation']],
        'crumb' => [],
        'switch' => ['roles' => ['switch'], 'type' => 'checkbox', 'emits' => ['type', 'role']],
        'tab' => ['roles' => ['tab'], 'type' => 'button'],
        'table' => ['roles' => ['table']],
        'tabs' => ['roles' => ['tablist']],
        'textarea' => ['roles' => ['textbox']],
        'time' => ['type' => 'time', 'emits' => ['type']],
        'toaster' => ['roles' => ['status'], 'owns' => ['x-data', 'x-show', 'x-text', 'aria-live', 'data-tone'], 'message' => 'owns its fruitToast helper, message and tone. Use the tone prop for the initial tone, and dispatch fruit-toast events for later messages'],
        'confirmer' => ['roles' => ['alertdialog'], 'owns' => ['x-data', 'open'], 'message' => 'owns its fruitConfirmer helper and open state. Call confirm() or $confirm() instead'],
        'token-field' => ['owns' => self::ENHANCED, 'message' => 'owns enhancement visibility and its single value contract', 'options' => ['submit' => ['text', 'list'], 'search' => ['local', 'server']]],
        'tooltip' => ['owns' => ['x-data'], 'message' => 'owns its fruitTooltip helper. Put application state on a parent'],
        'workspace' => ['roles' => ['group', 'region'], 'options' => ['frame' => ['card', 'fill']]],
    ];

    /** Validate documented options and reject attributes that would change the component's semantics. */
    public static function validate(string $component, ComponentAttributeBag $attributes, array $options = []): void
    {
        $contract = self::CONTRACTS[$component] ?? throw new InvalidArgumentException("Unknown FruitUI component: {$component}.");
        foreach ($options as $name => $value) {
            self::option($component, $name, $value, $contract['options'][$name]);
        }
        if (isset($contract['requires'])) {
            $required = $contract['requires'];
            if (! $attributes->has($required) && ! $attributes->has(":{$required}") && ! $attributes->has("x-bind:{$required}")) {
                throw new InvalidArgumentException("FruitUI {$component} requires {$required} on its native element.");
            }
        }
        $roles = $contract['roles'] ?? [];
        $owned = $contract['owns'] ?? [];
        foreach ($attributes->all() as $name => $value) {
            $name = strtolower($name);
            if ($name === 'as') {
                throw new InvalidArgumentException("FruitUI {$component} has a fixed native element; compose another component instead of using as.");
            }
            if ($name === 'type' && $value !== ($contract['type'] ?? null)) {
                throw new InvalidArgumentException("FruitUI {$component} has a fixed native type; use the component for the control you need.");
            }
            if ($name === 'role' && ! in_array($value, $roles, true)) {
                throw new InvalidArgumentException("FruitUI {$component} uses its native control semantics; use a separate component instead of overriding role.");
            }
            // Server-bound props are validated above. Client bindings must
            // not turn a text field into a choice or change a control's role.
            // A password input may bind its type to reveal the characters it holds.
            if ((self::binds($name, 'type') && ! self::reveals($component, $options)) || self::binds($name, 'role')) {
                throw new InvalidArgumentException("FruitUI {$component} does not support {$name}; type and role belong to its component contract.");
            }
            foreach ($owned as $attribute) {
                if ($name === $attribute || self::binds($name, $attribute)) {
                    throw new InvalidArgumentException("FruitUI {$component} {$contract['message']}.");
                }
            }
        }
    }

    /** Validate a form control and join its Field: one call for every control adapter. */
    public static function control(string $component, ComponentAttributeBag $attributes, ?FieldContext $field, array $options = []): ComponentAttributeBag
    {
        self::validate($component, $attributes, $options);
        $attributes = $attributes->filter(fn ($value, $name) => ! in_array(strtolower($name), self::CONTRACTS[$component]['emits'] ?? [], true));

        return self::fieldControl($attributes, $field);
    }

    public static function uploadRow(mixed $name, mixed $state, mixed $progress, ComponentAttributeBag $attributes): void
    {
        self::validate('upload-row', $attributes);
        self::option('upload-row', 'state', $state, ['uploading', 'complete', 'error', 'cancelled']);
        if (! is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('FruitUI upload-row needs the file name.');
        }
        if ($progress !== null && filter_var($progress, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]) === false) {
            throw new InvalidArgumentException('FruitUI upload-row progress must be a whole percentage from 0 to 100.');
        }
    }

    public static function commandPalette(mixed $name, mixed $shortcut, ComponentAttributeBag $attributes): void
    {
        self::validate('command-palette', $attributes);
        if (! is_string($name) || ! preg_match('/^[\w-]+$/', $name)) {
            throw new InvalidArgumentException('FruitUI command-palette needs a name of letters, digits, dashes or underscores; events open it by name.');
        }
        if ($shortcut !== null && (! is_string($shortcut) || ! preg_match('/^[a-z0-9]$/i', $shortcut))) {
            throw new InvalidArgumentException('FruitUI command-palette shortcut must be one letter or digit, used with Cmd or Ctrl.');
        }
    }

    public static function autocomplete(mixed $trigger, ComponentAttributeBag $attributes): void
    {
        self::validate('autocomplete', $attributes);
        if ($trigger !== null && (! is_string($trigger) || ! preg_match('/^[^\s\w]{1,2}$/u', $trigger))) {
            throw new InvalidArgumentException('FruitUI autocomplete trigger must be one or two punctuation characters, such as @ or :.');
        }
    }

    public static function selectionBar(mixed $count, ComponentAttributeBag $attributes): void
    {
        self::validate('selection-bar', $attributes);
        if (filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new InvalidArgumentException('FruitUI selection-bar count must be a whole number of selected items.');
        }
    }

    public static function skeleton(mixed $lines, ComponentAttributeBag $attributes): void
    {
        self::validate('skeleton', $attributes);
        if (filter_var($lines, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]) === false) {
            throw new InvalidArgumentException('FruitUI skeleton lines must be a whole number from 1 to 20.');
        }
    }

    /** A crumb links to an ancestor page with href, or names the current page. */
    public static function crumb(mixed $current, ComponentAttributeBag $attributes): void
    {
        self::validate('crumb', $attributes);
        if (! is_bool($current)) {
            throw new InvalidArgumentException('FruitUI crumb current must be a boolean.');
        }
        $linked = $attributes->has('href') || $attributes->has(':href') || $attributes->has('x-bind:href');
        if ($current === $linked) {
            throw new InvalidArgumentException('FruitUI crumb needs an href, or current for the current page, but not both.');
        }
    }

    /** A search field needs an accessible name: its label prop, its own ARIA name, or a Field. */
    public static function search(mixed $label, ComponentAttributeBag $attributes, ?FieldContext $field): ComponentAttributeBag
    {
        if ($label !== null && (! is_string($label) || trim($label) === '')) {
            throw new InvalidArgumentException('FruitUI search label must be text.');
        }
        if ($label === null && $field === null && ! $attributes->has('aria-label') && ! $attributes->has('aria-labelledby')) {
            throw new InvalidArgumentException('FruitUI search needs an accessible name: a label, aria-label, aria-labelledby or a Field.');
        }

        return self::control('search', $attributes, $field);
    }

    public static function presence(mixed $available, ComponentAttributeBag $attributes): void
    {
        self::validate('presence', $attributes);
        if (! is_bool($available)) {
            throw new InvalidArgumentException('FruitUI presence available must be a boolean.');
        }
    }

    /** Returns whether a checkbox or radio menu item's checked state is bound on the client. */
    public static function menuChoice(string $component, mixed $checked, ComponentAttributeBag $attributes): bool
    {
        self::validate($component, $attributes);
        if (! is_bool($checked)) {
            throw new InvalidArgumentException("FruitUI {$component} checked must be a boolean.");
        }
        if ($attributes->has('aria-checked')) {
            throw new InvalidArgumentException("FruitUI {$component} renders aria-checked from its checked prop; bind x-bind:aria-checked for client state.");
        }

        return $attributes->has('x-bind:aria-checked') || $attributes->has(':aria-checked');
    }

    /** Returns whether the dialog's open state is bound with wire:model or x-model. */
    public static function dialog(mixed $name, ComponentAttributeBag $attributes, mixed $size = 'medium'): bool
    {
        self::validate('dialog', $attributes, ['size' => $size]);
        if ($name !== null && (! is_string($name) || ! preg_match('/^[\w.:-]+$/', $name))) {
            throw new InvalidArgumentException('FruitUI dialog name must be letters, digits, dashes, dots, colons or underscores.');
        }
        $names = array_map('strtolower', array_keys($attributes->all()));
        $bound = (bool) preg_grep('/^(wire:model|x-model)(\.|$)/', $names);
        if ($bound && preg_grep('/^(:|x-bind:)?(x-data|x-modelable)(\.|$)/', $names)) {
            throw new InvalidArgumentException('FruitUI dialog owns its open state when bound with wire:model or x-model. Put application state on a parent.');
        }

        return $bound;
    }

    public static function sidebarItem(mixed $current, ComponentAttributeBag $attributes): void
    {
        self::validate('sidebar-item', $attributes);
        if (! is_bool($current)) {
            throw new InvalidArgumentException('FruitUI sidebar-item current must be a boolean.');
        }
    }

    public static function tooltip(mixed $text, mixed $textId, ComponentAttributeBag $attributes): void
    {
        self::validate('tooltip', $attributes);
        if (! self::identifier($text) || ! self::identifier($textId) || preg_match('/\s/', $textId)) {
            throw new InvalidArgumentException('FruitUI tooltip requires text and a text-id that its trigger references with aria-describedby.');
        }
    }

    /** Returns the message to show first: the explicit one, or the flashed fruit-toast session value. */
    /**
     * The toaster's initial message and tone, from its props or the flashed session values.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function toaster(mixed $duration, mixed $message, mixed $tone, ComponentAttributeBag $attributes): array
    {
        self::validate('toaster', $attributes);
        if (! is_int($duration) && ! (is_string($duration) && ctype_digit($duration))) {
            throw new InvalidArgumentException('FruitUI toaster duration must be a nonnegative number of milliseconds.');
        }
        if ($message === null && app()->bound('session')) {
            $message = app('session')->get('fruit-toast');
            $tone ??= app('session')->get('fruit-toast-tone');
        }
        if ($message !== null && ! is_string($message)) {
            throw new InvalidArgumentException('FruitUI toaster message must be text.');
        }
        $tone ??= 'neutral';
        if (! in_array($tone, Fruit::TOAST_TONES, true)) {
            throw new InvalidArgumentException('FruitUI toaster tone must be one of: '.implode(', ', Fruit::TOAST_TONES).'.');
        }

        return [$message === '' ? null : $message, $tone];
    }

    /** A message's options; `mine` marks a sent message the viewer wrote. */
    public static function message(mixed $layout, mixed $variant, mixed $direction, mixed $mine, ComponentAttributeBag $attributes): void
    {
        self::validate('message', $attributes, ['layout' => $layout, 'variant' => $variant, 'direction' => $direction]);
        if (! is_bool($mine)) {
            throw new InvalidArgumentException('FruitUI message mine must be a boolean.');
        }
        if ($mine && $direction !== 'outgoing') {
            throw new InvalidArgumentException('FruitUI message mine marks a sent message; use it with direction="outgoing".');
        }
    }

    /** A Field's label prop or label slot, which must have visible text. */
    public static function fieldLabel(mixed $label): mixed
    {
        $text = $label instanceof ComponentSlot ? trim(strip_tags($label->toHtml())) : (is_string($label) ? trim($label) : '');
        if ($text === '') {
            throw new InvalidArgumentException('FruitUI Field requires a nonempty label: a label prop or a label slot.');
        }

        return $label;
    }

    public static function splitter(mixed $pane, mixed $flexible, mixed $variable, mixed $min, mixed $max, mixed $reserve, mixed $edge, ComponentAttributeBag $attributes): void
    {
        self::validate('splitter', $attributes, ['edge' => $edge]);
        $bounds = [$min, $max, $reserve];
        if (! self::identifier($pane) || ! self::identifier($flexible) || $pane === $flexible
            || ! is_string($variable) || ! preg_match('/^--f-[\w-]+$/', $variable)
            || array_filter($bounds, fn ($bound) => ! is_numeric($bound) || ! is_finite((float) $bound) || $bound <= 0)
            || $max < $min) {
            throw new InvalidArgumentException('FruitUI splitter requires distinct pane/flexible IDs, a --f- variable and positive width bounds.');
        }
        foreach (['tabindex' => '0', 'aria-orientation' => 'vertical', 'aria-controls' => $pane] as $attribute => $fixed) {
            foreach ($attributes->all() as $name => $value) {
                $name = strtolower($name);
                if (self::binds($name, $attribute) || ($name === $attribute && (string) $value !== $fixed)) {
                    throw new InvalidArgumentException('FruitUI splitter owns its orientation, focus, controlled pane and Alpine helper.');
                }
            }
        }
    }

    /**
     * Link a checkbox, radio or switch to its description. The id follows the control's id, or a
     * hash of its name, value and model, so it stays stable across Livewire renders.
     *
     * @return array{0: ComponentAttributeBag, 1: ?string}
     */
    public static function choiceDescription(ComponentAttributeBag $attributes, mixed $description): array
    {
        $text = $description instanceof Htmlable ? $description->toHtml() : (string) $description;
        if (trim(strip_tags($text)) === '') {
            return [$attributes, null];
        }
        $base = $attributes->get('id') ?? 'f-choice-'.substr(md5(implode('|', [
            $attributes->get('name'), $attributes->get('value'), $attributes->get('wire:model'), $attributes->get('x-model'), $text,
        ])), 0, 10);
        // Distinct from a Field's own {id}-description.
        $id = "{$base}-choice-description";
        $describedBy = trim($attributes->get('aria-describedby', '').' '.$id);

        return [$attributes->except('aria-describedby')->merge(['aria-describedby' => $describedBy]), $id];
    }

    /**
     * A form section's heading level and title id. The id follows the section's id, or the title.
     *
     * @return array{0: int, 1: ?string}
     */
    public static function formSection(mixed $title, mixed $level, ComponentAttributeBag $attributes): array
    {
        self::validate('form-section', $attributes);
        if (! in_array((int) $level, [2, 3, 4], true) || (string) (int) $level !== (string) $level) {
            throw new InvalidArgumentException('FruitUI form-section level must be one of: 2, 3, 4.');
        }
        $text = trim(strip_tags($title instanceof Htmlable ? $title->toHtml() : (string) $title));
        if ($text === '') {
            return [(int) $level, null];
        }

        return [(int) $level, ($attributes->get('id') ?? 'f-section-'.Str::slug($text)).'-title'];
    }

    public static function wrapper(mixed $attributes): ComponentAttributeBag
    {
        if (! is_array($attributes) && ! $attributes instanceof ComponentAttributeBag) {
            throw new InvalidArgumentException('FruitUI wrapper must be an attribute array or bag.');
        }
        $bag = $attributes instanceof ComponentAttributeBag ? $attributes : new ComponentAttributeBag($attributes);
        foreach ($bag->all() as $name => $value) {
            if (! in_array($name, ['id', 'class', 'style', 'dir', 'lang'], true) && ! preg_match('/^data-[a-z0-9-]+$/', $name)) {
                throw new InvalidArgumentException('FruitUI wrapper accepts presentation and data attributes; control bindings belong on the native control.');
            }
        }

        return $bag;
    }

    /** Merge a Field's label, description and error associations into its control. */
    private static function fieldControl(ComponentAttributeBag $attributes, ?FieldContext $field): ComponentAttributeBag
    {
        if ($field === null) {
            return $attributes;
        }
        $id = $field->bind($attributes);
        $error = $field->error();
        $described = preg_split('/\s+/', trim($attributes->get('aria-describedby', '')), -1, PREG_SPLIT_NO_EMPTY);
        if ($field->description !== null && $field->description !== '') {
            $described[] = $field->descriptionId();
        }
        if ($error !== null) {
            $described[] = $field->errorId();
            $attributes = $attributes->except('aria-invalid')->merge(['aria-invalid' => 'true']);
        }
        if ($described) {
            $attributes = $attributes->except('aria-describedby')->merge(['aria-describedby' => implode(' ', array_unique($described))]);
        }

        return $attributes->merge(['id' => $id]);
    }

    /** Whether an attribute name is a Blade or Alpine client binding of the given attribute, with optional modifiers. */
    /** A password input's show/hide toggle switches between password and text, staying a text entry. */
    private static function reveals(string $component, array $options): bool
    {
        return $component === 'input' && ($options['type'] ?? null) === 'password';
    }

    private static function binds(string $name, string $attribute): bool
    {
        foreach ([':', 'x-bind:'] as $prefix) {
            if ($name === $prefix.$attribute || str_starts_with($name, $prefix.$attribute.'.')) {
                return true;
            }
        }

        return false;
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function option(string $component, string $name, mixed $value, array $allowed): void
    {
        if (! in_array($value, $allowed, true)) {
            $choices = implode(', ', $allowed);
            $hint = $component === 'input' ? ' Use checkbox, radio, or switch components for selection controls.' : '';
            throw new InvalidArgumentException("FruitUI {$component} {$name} must be one of: {$choices}.{$hint}");
        }
    }
}
