<?php

namespace FruitUI\Rules;

use Closure;
use FruitUI\Fruit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

/**
 * Validate every token in a Token Field's value with ordinary Laravel rules.
 * The error belongs to the field itself, so <x-fruit::field> shows it. The value is the
 * newline-delimited text, or the posted list from submit="list".
 *
 *     'cc' => ['nullable', new Tokens('email')]
 */
final class Tokens implements ValidationRule
{
    /** @param  string|array<int, mixed>  $rules  Rules for each token. */
    public function __construct(private readonly string|array $rules) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! (is_array($value) && array_is_list($value) && array_filter($value, is_string(...)) === $value)) {
            $fail('validation.string')->translate();

            return;
        }
        foreach (Fruit::tokens($value) as $token) {
            if (Validator::make(['token' => $token], ['token' => $this->rules])->fails()) {
                $fail('The :attribute field contains an invalid entry: :value.')->translate(['value' => $token]);

                return;
            }
        }
    }
}
