/**
 * The conversation page: chat mode's Accept Chat and End Chat.
 */
document.addEventListener('alpine:init', function () {
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
