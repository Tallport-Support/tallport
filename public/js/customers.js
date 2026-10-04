/**
 * Customers: the edit form's lists of emails, phones, websites and social
 * profiles, and the customer list's pages in search.
 */
document.addEventListener('alpine:init', function () {
	/**
	 * Lists of inputs (.multi-container): .multi-add adds an empty copy of
	 * the first .multi-item, .multi-remove removes one (or empties the last).
	 */
	window.Alpine.data('tallportMultiInput', function () {
		return {
			click: function (e) {
				var add = e.target.closest('.multi-add');
				var remove = e.target.closest('.multi-remove');
				if (add) {
					e.preventDefault();
					this.add(add.closest('.multi-container'));
				} else if (remove) {
					e.preventDefault();
					this.remove(remove.closest('.multi-container'), remove.closest('.multi-item'));
				}
			},
			items: function (container) {
				return Array.prototype.filter.call(container.children, function (child) {
					return child.classList.contains('multi-item');
				});
			},
			add: function (container) {
				var items = this.items(container);
				var helps = container.querySelectorAll(':scope > .block-help');
				var help = helps[helps.length - 1];
				var index = parseInt(help ? help.getAttribute('data-max-i') : '');
				if (isNaN(index)) {
					index = 0;
				}
				index++;

				var clone = items[0].cloneNode(true);
				clone.querySelectorAll('input, select, textarea').forEach(function (input) {
					if (input.name) {
						input.name = input.name.replace(/^([^\[]+\[)([0-9]+)(\])/, '$1' + index + '$3');
					}
					input.value = '';
				});
				clone.querySelectorAll('.help-block, .f-error').forEach(function (error) {
					error.remove();
				});
				clone.classList.remove('has-error');
				items[items.length - 1].after(clone);

				if (help) {
					help.setAttribute('data-max-i', index);
				}
			},
			remove: function (container, item) {
				if (this.items(container).length > 1) {
					item.remove();
				} else {
					item.querySelectorAll('input, select, textarea').forEach(function (input) {
						input.value = '';
					});
				}
			}
		};
	});

	/**
	 * The customers found by a search (customers/partials/customers_table):
	 * its pager loads another page in place.
	 */
	window.Alpine.data('tallportCustomersTable', function () {
		return {
			page: function (e) {
				var link = e.target.closest('.pager-nav');
				if (!link) {
					return;
				}
				e.preventDefault();
				if (link.getAttribute('aria-disabled') == 'true') {
					return;
				}
				var table = this.$el;
				var data = new FormData();
				data.append('action', 'customers_pagination');
				data.append('page', link.getAttribute('data-page'));
				if (document.body.classList.contains('body-search')) {
					new URLSearchParams(window.location.search).forEach(function (value, key) {
						if (key == 'q') {
							data.append('filter[q]', value);
						} else if (key == 'f' || key.indexOf('f[') == 0) {
							data.append('filter[f]' + key.substring(1), value);
						}
					});
				}
				table.setAttribute('aria-busy', 'true');
				Tallport.post(laroute.route('customers.ajax'), data).then(function (response) {
					if (Tallport.isSuccess(response) && typeof response.html != 'undefined') {
						table.outerHTML = response.html;
					} else {
						table.removeAttribute('aria-busy');
						Tallport.result(response);
					}
				});
			}
		};
	});
});
