{{-- Give the conversation another customer, or a new one (a FruitUI remote dialog; tallportChangeCustomer in public/js/conversations.js). --}}
<div class="f-stack modal-form" x-data="tallportChangeCustomer({{ $conversation->id }}, @js($conversation->customer_email))">
    <x-fruit::search :label="__('Search for a customer by name or email')" :placeholder="__('Search for a customer by name or email').'…'" x-model="query" x-on:input.debounce.250ms="search()" autocomplete="off" />

    <ul class="f-item-list change-customer-results" x-show="results.length" x-cloak>
        <template x-for="result in results" :key="result.id">
            <li><button type="button" class="f-item-row" x-on:click="choose(result.id)"><span class="f-item-row__title" x-text="result.text"></span></button></li>
        </template>
    </ul>
    <p class="f-muted customer-not-found-title" x-show="searched && !results.length" x-cloak>{{ __('No customers found. Would you like to create one?') }}</p>

    <div x-show="creating" x-cloak>
        <h3 class="f-headline">{{ __('Create a New Customer') }}</h3>
        <form class="f-stack" x-on:submit.prevent="create($refs.save)">
            <x-fruit::field :label="__('First Name')" control-id="change-customer-first-name">
                <x-fruit::input id="change-customer-first-name" name="first_name" required />
            </x-fruit::field>
            <x-fruit::field :label="__('Last Name')" control-id="change-customer-last-name">
                <x-fruit::input id="change-customer-last-name" name="last_name" />
            </x-fruit::field>
            <x-fruit::field :label="__('Email')" control-id="change-customer-email">
                <x-fruit::input type="email" id="change-customer-email" name="email" required />
            </x-fruit::field>
            <div class="modal-form__actions">
                <button class="f-button f-button--primary" type="submit" x-ref="save">{{ __('Save') }}</button>
            </div>
        </form>
    </div>
    <div x-show="!creating">
        <button type="button" class="f-button f-button--ghost f-button--small" x-on:click="creating = true">{{ __('Create a New Customer') }}</button>
    </div>
</div>
