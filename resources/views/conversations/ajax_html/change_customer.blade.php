<div class="f-stack modal-form">
	<select class="f-input change-customer-input" data-customer_email="{{ $conversation->customer_email }}" placeholder="{{ __('Search for a customer by name or email') }}…" autocomplete="off" aria-label="{{ __('Search for a customer by name or email') }}"></select>

	<div id="change-customer-suggestions" class="hidden">
		<h3 class="f-headline">{{ __('Suggestions') }}</h3>
	</div>

	<div id="change-customer-create" class="hidden">
		<h3 class="f-headline customer-not-found-title">{{ __('No customers found. Would you like to create one?') }}</h3>
		<h3 class="f-headline customer-create-title">{{ __('Create a new customer') }}</h3>

		<form class="f-stack">
			<div class="form-group">
				<input type="text" class="f-input" name="first_name" placeholder="{{ __('First Name') }}" aria-label="{{ __('First Name') }}" required="required">
				<span class="help-block"></span>
			</div>
			<div class="form-group">
				<input type="text" class="f-input" name="last_name" placeholder="{{ __('Last Name') }}" aria-label="{{ __('Last Name') }}">
				<span class="help-block"></span>
			</div>
			<div class="form-group">
				<input type="email" class="f-input" name="email" placeholder="{{ __('Email') }}" aria-label="{{ __('Email') }}" required="required">
				<span class="help-block"></span>
			</div>
			<div class="modal-form__actions">
				<button class="f-button f-button--primary" type="submit" data-loading-text="{{ __('Saving') }}…">{{ __('Save') }}</button>
			</div>
		</form>
	</div>

	<div id="change-customer-create-trigger">
		<a href="#">{{ __('Create a new customer') }}</a>
	</div>
</div>
