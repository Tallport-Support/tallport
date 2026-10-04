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

/**
 * The reply editor's "Paste as Plain Text" toggle: remembered per user in
 * this browser, it switches FruitUI's data-fruit-paste on the editor.
 */
document.addEventListener('alpine:init', function () {
	window.Alpine.data('editorPlainPaste', function () {
		return {
			plain: false,
			key: 'editor_plain_text_paste_' + (document.body.getAttribute('data-auth_user_id') || ''),
			init: function () {
				try {
					this.plain = window.localStorage.getItem(this.key) == '1';
				} catch (e) {}
				this.apply();
			},
			apply: function () {
				var editor = this.$el.closest('.f-editor');
				if (editor) {
					editor.setAttribute('data-fruit-paste', this.plain ? 'plain' : 'rich');
				}
			},
			toggle: function () {
				this.plain = !this.plain;
				try {
					if (this.plain) {
						window.localStorage.setItem(this.key, '1');
					} else {
						window.localStorage.removeItem(this.key);
					}
				} catch (e) {}
				this.apply();
			}
		};
	});
});
