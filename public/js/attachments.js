/**
 * Attachments in conversations: a viewer (images, PDFs, text, audio, video,
 * attached emails) in a FruitUI dialog, and deleting. Any element with
 * data-attachment-id, data-mime (and data-email-url) around an .attachment-link
 * opens in it: the messages' files and the conversation's Attachments list.
 */
(function () {
	var items = function () {
		return Array.prototype.slice.call(document.querySelectorAll('[data-attachment-id][data-mime]')).filter(function (item) {
			return kind(item) !== '';
		});
	};

	// What the viewer shows for an attachment, or ''.
	var kind = function (item) {
		var mime = (item.getAttribute('data-mime') || '').toLowerCase();
		var link = item.querySelector('.attachment-link');
		var ext = ((link ? link.getAttribute('href') : '') || '').split('?')[0].split('.').pop().toLowerCase();
		if (item.getAttribute('data-email-url')) {
			return 'email';
		}
		if (mime.indexOf('image/') === 0 && mime.indexOf('svg') == -1) {
			return 'image';
		}
		if (mime == 'application/pdf') {
			return 'frame';
		}
		if (['text/plain', 'text/x-diff', 'application/json'].indexOf(mime) != -1 && ['txt', 'diff', 'patch', 'json', 'log'].indexOf(ext) != -1) {
			return 'frame';
		}
		if (mime.indexOf('audio/') === 0) {
			return 'audio';
		}
		if (mime.indexOf('video/') === 0) {
			return 'video';
		}
		return '';
	};

	var media = function (item) {
		var url = item.querySelector('.attachment-link').getAttribute('href');
		var element;
		switch (kind(item)) {
			case 'image':
				element = document.createElement('img');
				element.alt = '';
				break;
			case 'frame':
				element = document.createElement('iframe');
				break;
			case 'email':
				element = document.createElement('iframe');
				element.setAttribute('sandbox', 'allow-popups allow-downloads');
				url = item.getAttribute('data-email-url');
				break;
			default:
				element = document.createElement(kind(item));
				element.controls = true;
		}
		element.src = url;
		return element;
	};

	var show = function (item) {
		var link = item.querySelector('.attachment-link');
		var list = items();
		var index = list.indexOf(item);
		var name = (link.querySelector('.f-attachment__body') || link).firstChild.textContent.trim() || link.textContent.trim();

		var body = document.createElement('div');
		body.className = 'attachment-viewer';
		body.appendChild(media(item));
		var footer = document.createElement('footer');
		footer.className = 'f-dialog__footer';
		footer.innerHTML = '<button type="button" class="f-button attachment-viewer__prev"></button>'
			+'<button type="button" class="f-button attachment-viewer__next"></button>'
			+'<span class="f-toolbar__spacer"></span>'
			+'<a class="f-button f-button--primary attachment-viewer__download" download></a>';
		footer.querySelector('.attachment-viewer__prev').textContent = Lang.get('messages.previous');
		footer.querySelector('.attachment-viewer__next').textContent = Lang.get('messages.next');
		footer.querySelector('.attachment-viewer__download').textContent = Lang.get('messages.download');
		footer.querySelector('.attachment-viewer__download').href = link.getAttribute('href');
		footer.querySelector('.attachment-viewer__prev').hidden = index <= 0;
		footer.querySelector('.attachment-viewer__next').hidden = index == -1 || index >= list.length - 1;

		var wrapper = document.createElement('div');
		wrapper.appendChild(body);
		wrapper.appendChild(footer);
		var dialog = FruitUI.dialog({title: name, html: wrapper.innerHTML, size: 'large'});
		var go = function (step) {
			dialog.close();
			show(list[index + step]);
		};
		dialog.loaded.then(function () {
			var root = dialog.element;
			root.querySelector('.attachment-viewer__prev').addEventListener('click', function () { go(-1); });
			root.querySelector('.attachment-viewer__next').addEventListener('click', function () { go(1); });
			root.addEventListener('keydown', function (e) {
				if (e.key == 'ArrowLeft' && index > 0) {
					go(-1);
				} else if (e.key == 'ArrowRight' && index < list.length - 1) {
					go(1);
				}
			});
		});
	};

	document.addEventListener('click', function (e) {
		var link = e.target.closest('.attachment-link');
		var item = link && link.closest('[data-attachment-id][data-mime]');
		if (!item || !kind(item) || e.ctrlKey || e.metaKey || e.shiftKey) {
			return;
		}
		e.preventDefault();
		show(item);
	});

	// Delete an attachment (the conversation gets a line saying so).
	document.addEventListener('click', function (e) {
		var button = e.target.closest('.attachment-delete');
		if (!button) {
			return;
		}
		e.preventDefault();
		var attachment_id = button.getAttribute('data-attachment-id');
		Tallport.confirm({message: button.getAttribute('data-confirm'), confirm: Lang.get('messages.delete'), tone: 'danger'}).then(function (ok) {
			if (!ok) {
				return;
			}
			Tallport.post(laroute.route('attachments.delete', {id: attachment_id}), {}).then(function (response) {
				if (Tallport.result(response)) {
					document.querySelectorAll('[data-attachment-id="'+attachment_id+'"]').forEach(function (item) {
						item.remove();
					});
				}
			});
		});
	});
})();
