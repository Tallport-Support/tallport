/**
 * Attachments in conversations: a viewer (images, PDFs, text, audio, video,
 * attached emails) in a FruitUI dialog, and deleting. Any element with
 * data-attachment-id, data-mime (and data-email-url) around an .attachment-link
 * opens in it: the messages' files and the conversation's Attachments list.
 * HEIC photos (data-heic) show as JPEG: converted by the server (data-converted-url),
 * or else decoded by the browser, natively (Safari) or with heic2any, loaded when needed.
 */
(function () {
	// A tiny HEIC picture: browsers that decode it show HEIC themselves.
	var HEIC_SAMPLE = 'data:image/heic;base64,AAAAHGZ0eXBoZWljAAAAAG1pZjFoZWljbWlhZgAAAXxtZXRhAAAAAAAAACFoZGxyAAAAAAAAAABwaWN0AAAAAAAAAAAAAAAAAAAAACJpbG9jAAAAAERAAAEAAQAAAAABoAABAAAAAAAAACoAAAAjaWluZgAAAAAAAQAAABVpbmZlAgAAAAABAABodmMxAAAAAA5waXRtAAAAAAABAAAA/GlwcnAAAADcaXBjbwAAAHVodmNDAQNwAAAAAAAAAAAAHvAA/P34+AAADwNgAAEAGEABDAH//wNwAAADAJAAAAMAAAMAHroCQGEAAQApQgEBA3AAAAMAkAAAAwAAAwAeoCCBBZbqrprm4CGgwIAAAAyAAAADAIRiAAEABkQBwXPBiQAAABNjb2xybmNseAABAA0ABoAAAAAUaXNwZQAAAAAAAABAAAAAQAAAAChjbGFwAAAACAAAAAEAAAAIAAAAAf///8gAAAAC////yAAAAAIAAAAQcGl4aQAAAAADCAgIAAAAGGlwbWEAAAAAAAAAAQABBYECAwWEAAAAMm1kYXQAAAAmKAGvGSFeQkDvXaW//3Hw/2q1/klbfKZKppcE14iZCwOBgI2RnTg=';
	var native_heic = null;
	var heic_library = null;
	var decoded = {};

	// Whether this browser shows HEIC itself (checked once).
	var nativeHeic = function () {
		if (!native_heic) {
			native_heic = new Promise(function (resolve) {
				var image = new Image();
				image.onload = function () { resolve(image.naturalWidth > 0); };
				image.onerror = function () { resolve(false); };
				image.src = HEIC_SAMPLE;
			});
		}
		return native_heic;
	};

	// heic2any (public/js/heic2any), loaded the first time a HEIC photo needs it.
	var heicLibrary = function () {
		if (!heic_library) {
			heic_library = new Promise(function (resolve, reject) {
				var script = document.createElement('script');
				script.src = Vars.public_url+'/js/heic2any/heic2any.min.js';
				script.onload = function () { resolve(window.heic2any); };
				script.onerror = function () { heic_library = null; reject(new Error('heic2any')); };
				document.head.appendChild(script);
			});
		}
		return heic_library;
	};

	// A HEIC attachment decoded to a JPEG blob (once per attachment), or null when the file
	// is already a picture browsers show.
	var heicBlob = function (item) {
		var id = item.getAttribute('data-attachment-id');
		if (!decoded[id]) {
			var url = item.querySelector('.attachment-link').getAttribute('href');
			decoded[id] = Promise.all([fetch(url, {credentials: 'same-origin'}).then(function (response) {
				if (!response.ok) {
					throw new Error(response.status);
				}
				return response.blob();
			}), heicLibrary()]).then(function (results) {
				return results[1]({blob: results[0], toType: 'image/jpeg', quality: 0.85}).catch(function (error) {
					if (String(error && error.message).indexOf('already browser readable') != -1) {
						return null;
					}
					throw error;
				});
			});
			decoded[id].catch(function () {
				delete decoded[id];
			});
		}
		return decoded[id];
	};

	// The address to show a HEIC attachment at: data: URLs, as the pages' CSP allows no blob: images.
	var heicSource = function (item) {
		var url = item.querySelector('.attachment-link').getAttribute('href');
		if (item.getAttribute('data-converted-url')) {
			return Promise.resolve(item.getAttribute('data-converted-url'));
		}
		return nativeHeic().then(function (native) {
			return native ? url : heicBlob(item).then(function (blob) {
				return blob ? dataUrl(blob) : url;
			});
		});
	};

	var dataUrl = function (blob) {
		return new Promise(function (resolve, reject) {
			var reader = new FileReader();
			reader.onload = function () { resolve(reader.result); };
			reader.onerror = reject;
			reader.readAsDataURL(blob);
		});
	};

	// A HEIC photo's thumbnail when the server can't make it: drawn by the browser.
	var heicThumbnail = function (item, img) {
		img.setAttribute('data-heic-pending', '');
		nativeHeic().then(function (native) {
			if (native) {
				img.src = item.querySelector('.attachment-link').getAttribute('href');
				return;
			}
			return heicBlob(item).then(function (blob) {
				if (!blob) {
					img.src = item.querySelector('.attachment-link').getAttribute('href');
					return;
				}
				return createImageBitmap(blob).then(function (bitmap) {
					var canvas = document.createElement('canvas');
					canvas.height = Math.min(320, bitmap.height);
					canvas.width = Math.max(1, Math.round(bitmap.width * canvas.height / bitmap.height));
					canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
					img.src = canvas.toDataURL('image/jpeg', 0.85);
				});
			});
		}).catch(function () {
			thumbnailFailed(img);
		});
	};

	// A thumbnail that can't be shown: the plain row instead.
	var thumbnailFailed = function (img) {
		var item = img.closest('.conv-attachment--thumbnail');
		if (item) {
			item.classList.remove('conv-attachment--thumbnail');
		}
	};

	var thumbnails = function () {
		document.querySelectorAll('.conv-attachment--thumbnail .attachment-thumbnail img').forEach(function (img) {
			var item = img.closest('[data-attachment-id]');
			if (!img.getAttribute('src') && item.hasAttribute('data-heic') && !img.hasAttribute('data-heic-pending')) {
				heicThumbnail(item, img);
			} else if (img.getAttribute('src') && img.complete && !img.naturalWidth) {
				thumbnailFailed(img);
			}
		});
	};
	document.addEventListener('error', function (e) {
		if (e.target.tagName == 'IMG' && e.target.closest('.attachment-thumbnail')) {
			thumbnailFailed(e.target);
		}
	}, true);
	// Messages come and go (opening conversations in place, new replies): checked once a frame.
	var thumbnails_queued = false;
	new MutationObserver(function () {
		if (!thumbnails_queued) {
			thumbnails_queued = true;
			requestAnimationFrame(function () {
				thumbnails_queued = false;
				thumbnails();
			});
		}
	}).observe(document.documentElement, {childList: true, subtree: true});
	document.addEventListener('DOMContentLoaded', thumbnails);
	thumbnails();

	// Each attachment once: a file is under its message and in the Attachments list too.
	var items = function () {
		var seen = {};
		return Array.prototype.slice.call(document.querySelectorAll('[data-attachment-id][data-mime]')).filter(function (item) {
			var id = item.getAttribute('data-attachment-id');
			if (seen[id] || kind(item) === '') {
				return false;
			}
			seen[id] = true;
			return true;
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
		if (item.hasAttribute('data-heic')) {
			return 'image';
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

	var media = function (item, name) {
		var url = item.querySelector('.attachment-link').getAttribute('href');
		var element;
		switch (kind(item)) {
			case 'image':
				element = document.createElement('img');
				element.alt = name;
				// Shown when decoded (once the dialog is open).
				if (item.hasAttribute('data-heic')) {
					element.setAttribute('data-heic', '');
					return element;
				}
				break;
			case 'frame':
				element = document.createElement('iframe');
				element.title = name;
				break;
			case 'email':
				element = document.createElement('iframe');
				element.title = name;
				element.setAttribute('sandbox', 'allow-popups allow-downloads');
				url = item.getAttribute('data-email-url');
				break;
			default:
				element = document.createElement(kind(item));
				element.setAttribute('aria-label', name);
				element.controls = true;
		}
		element.src = url;
		return element;
	};

	var show = function (item) {
		var link = item.querySelector('.attachment-link');
		var list = items();
		var index = list.findIndex(function (other) {
			return other.getAttribute('data-attachment-id') == item.getAttribute('data-attachment-id');
		});
		var name = item.getAttribute('data-file-name') || (link.querySelector('.f-attachment__body') || link).firstChild.textContent.trim() || link.textContent.trim();

		var body = document.createElement('div');
		body.className = 'attachment-viewer';
		body.appendChild(media(item, name));
		var footer = document.createElement('footer');
		footer.className = 'f-dialog__footer attachment-viewer__footer';
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
			var heic = root.querySelector('.attachment-viewer img[data-heic]');
			if (heic) {
				heicSource(item).then(function (src) {
					heic.src = src;
				}, function () {
					heic.replaceWith(Object.assign(document.createElement('p'), {className: 'f-help', textContent: Lang.get('messages.error_occurred')}));
				});
			}
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
