/**
 * Lists of conversations (App\Livewire\ConversationList): the selection the
 * bulk actions work on. Shift-click selects the range from the last
 * conversation checked. And the chat mode's Accept Chat and End Chat.
 */
document.addEventListener('alpine:init', function () {
	window.Alpine.data('tallportConversationList', function () {
		return {
			selected: [],
			last_checked: null,
			checked: function (event) {
				var checkbox = event.target;
				if (event.shiftKey && this.last_checked && this.last_checked.isConnected) {
					var checkboxes = Array.prototype.slice.call(this.$root.querySelectorAll('input.conv-checkbox'));
					var start = checkboxes.indexOf(checkbox);
					var end = checkboxes.indexOf(this.last_checked);
					var range = checkboxes.slice(Math.min(start, end), Math.max(start, end) + 1).map(function (item) {
						return item.value;
					});
					var selected = this.selected.filter(function (id) {
						return range.indexOf(id) == -1;
					});
					this.selected = checkbox.checked ? selected.concat(range) : selected;
					document.getSelection().removeAllRanges();
				}
				this.last_checked = checkbox;
			}
		};
	});

	// Accept Chat (assign to me) and End Chat (close), then the chat again.
	window.Alpine.data('tallportChatAction', function (data) {
		return {
			run: function (button) {
				Tallport.busy(button, true);
				Tallport.post(laroute.route('conversations.ajax'), data).then(function (response) {
					if (Tallport.isSuccess(response)) {
						window.location.reload();
					} else {
						Tallport.result(response);
						Tallport.busy(button, false);
					}
				});
			}
		};
	});
});
