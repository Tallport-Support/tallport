/**
 * Tallport's editors (x-editor, built on FruitUI's x-fruit::editor).
 *
 * Images pasted or dropped into an editor arrive as FruitUI's
 * fruit-editor-upload event: they are uploaded to the editor's upload URL and
 * inserted where they were pasted.
 */
document.addEventListener('fruit-editor-upload', function (event) {
	var editor = event.target.closest('[data-upload-url]');
	if (!editor) {
		return;
	}
	var token = document.querySelector('meta[name="csrf-token"]');

	Array.prototype.forEach.call(event.detail.files, function (file) {
		var data = new FormData();
		data.append('file', file);
		data.append('attach', 0);

		fetch(editor.getAttribute('data-upload-url'), {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
			headers: {
				'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
				'X-Requested-With': 'XMLHttpRequest',
				'Accept': 'application/json'
			}
		}).then(function (response) {
			return response.json();
		}).then(function (response) {
			if (response.status == 'success' && response.url) {
				event.detail.insert(response.url, file.name);
			} else {
				showFloatingAlert('error', response.msg || Lang.get('messages.error_occurred'));
			}
		}).catch(function () {
			showFloatingAlert('error', Lang.get('messages.error_occurred'));
		});
	});
});
