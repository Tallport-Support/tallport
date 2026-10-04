/**
 * Attachments in conversations: a viewer (images, PDFs, text, audio, video,
 * attached emails), deleting, and a reminder when a reply mentions an
 * attachment but has none.
 */

// What the viewer shows for an attachment (li[data-attachment-id]), or ''.
function attachmentViewKind(li)
{
	var mime = (li.attr('data-mime') || '').toLowerCase();
	var url = li.find('.attachment-link:first').attr('href') || '';
	var ext = (url.split('?')[0].split('.').pop() || '').toLowerCase();
	if (li.attr('data-email-url')) {
		return 'email';
	}
	if (mime.indexOf('image/') == 0 && mime.indexOf('svg') == -1) {
		return 'image';
	}
	if (mime == 'application/pdf') {
		return 'frame';
	}
	if ($.inArray(mime, ['text/plain', 'text/x-diff', 'application/json']) != -1 && $.inArray(ext, ['txt', 'diff', 'patch', 'json', 'log']) != -1) {
		return 'frame';
	}
	if (mime.indexOf('audio/') == 0) {
		return 'audio';
	}
	if (mime.indexOf('video/') == 0) {
		return 'video';
	}
	return '';
}

function attachmentViewerShow(li)
{
	var viewer = $('#attachment-viewer');
	if (!viewer.length) {
		viewer = $('<div id="attachment-viewer"><div class="av-header"><span class="av-name"></span>'
			+'<a class="f-button f-button--small av-download" download><i class="glyphicon glyphicon-download-alt"></i></a>'
			+'<a href="#" class="av-close">&times;</a></div>'
			+'<a href="#" class="av-nav av-prev"><i class="glyphicon glyphicon-chevron-left"></i></a>'
			+'<div class="av-content"></div>'
			+'<a href="#" class="av-nav av-next"><i class="glyphicon glyphicon-chevron-right"></i></a></div>');
		$('body').append(viewer);
		viewer.on('click', function(e) {
			if (e.target === this || $(e.target).hasClass('av-content')) {
				attachmentViewerClose();
			}
		});
		viewer.on('click', '.av-close', function(e) {
			e.preventDefault();
			attachmentViewerClose();
		});
		viewer.on('click', '.av-prev, .av-next', function(e) {
			e.preventDefault();
			var target = viewer.data('li')[$(this).hasClass('av-prev') ? 'prevAll' : 'nextAll']('li[data-attachment-id]').filter(function() {
				return attachmentViewKind($(this)) != '';
			}).first();
			if (target.length) {
				attachmentViewerShow(target);
			}
		});
		$(document).on('keydown.attachment-viewer', function(e) {
			if (!$('#attachment-viewer').is(':visible')) {
				return;
			}
			if (e.key == 'Escape') {
				attachmentViewerClose();
			} else if (e.key == 'ArrowLeft') {
				viewer.find('.av-prev:visible').click();
			} else if (e.key == 'ArrowRight') {
				viewer.find('.av-next:visible').click();
			}
		});
	}

	var link = li.find('.attachment-link:first');
	var url = link.attr('href');
	var kind = attachmentViewKind(li);
	var content = '';
	switch (kind) {
		case 'image':
			content = '<img src="'+htmlEscape(url)+'" />';
			break;
		case 'frame':
			content = '<iframe src="'+htmlEscape(url)+'"></iframe>';
			break;
		case 'email':
			content = '<iframe src="'+htmlEscape(li.attr('data-email-url'))+'" sandbox="allow-popups allow-downloads"></iframe>';
			break;
		case 'audio':
			content = '<audio controls src="'+htmlEscape(url)+'"></audio>';
			break;
		case 'video':
			content = '<video controls src="'+htmlEscape(url)+'"></video>';
			break;
	}
	viewer.data('li', li);
	viewer.find('.av-name').text(link.text());
	viewer.find('.av-download').attr('href', url);
	viewer.find('.av-content').html(content);
	var others = function(direction) {
		return li[direction]('li[data-attachment-id]').filter(function() {
			return attachmentViewKind($(this)) != '';
		}).length > 0;
	};
	viewer.find('.av-prev').toggle(others('prevAll'));
	viewer.find('.av-next').toggle(others('nextAll'));
	$('html').addClass('av-open');
	viewer.show();
}

function attachmentViewerClose()
{
	$('#attachment-viewer').hide().find('.av-content').html('');
	$('html').removeClass('av-open');
}

$(document).on('click', '.thread-attachments .attachment-link, .attachments-list .attachment-link', function(e) {
	var li = $(this).closest('li[data-attachment-id]');
	if (!attachmentViewKind(li) || e.ctrlKey || e.metaKey || e.shiftKey) {
		return;
	}
	e.preventDefault();
	attachmentViewerShow(li);
});

// Delete an attachment (the conversation gets a line saying so).
$(document).on('click', '.attachment-delete', function(e) {
	e.preventDefault();
	var attachment_id = $(this).attr('data-attachment-id');
	showModalConfirm(htmlEscape($(this).attr('data-confirm')), 'attachment-delete-ok', {
		on_show: function(modal) {
			modal.children().find('.attachment-delete-ok:first').click(function() {
				fsAjax({}, laroute.route('attachments.delete', {id: attachment_id}), function(response) {
					modal.modal('hide');
					if (isAjaxSuccess(response)) {
						$('li[data-attachment-id="'+attachment_id+'"]').remove();
					} else {
						showAjaxError(response);
					}
				});
			});
		}
	}, Lang.get("messages.delete"));
});

// "I've attached..." without an attachment: ask before sending.
fsAddFilter('conversation.can_submit', function(can_submit, params) {
	var info = $('#attachment-reminder');
	if (!can_submit || !info.length || getGlobalAttr('attachment-reminder-ok') == '1') {
		return can_submit;
	}
	if ($('.attachments-upload .attachment-loaded').length) {
		return can_submit;
	}
	var text = $('<div>'+getReplyBody()+'</div>').text().toLowerCase();
	var phrases = JSON.parse(info.attr('data-phrases') || '[]');
	for (var i = 0; i < phrases.length; i++) {
		var phrase = $.trim(phrases[i]).toLowerCase();
		if (phrase && text.indexOf(phrase) != -1) {
			showModalConfirm(htmlEscape(info.attr('data-message')).replace(':phrase', '<strong>“'+htmlEscape(phrases[i])+'”</strong>'), 'attachment-reminder-send', {
				on_show: function(modal) {
					modal.children().find('.attachment-reminder-send:first').click(function() {
						setGlobalAttr('attachment-reminder-ok', '1');
						modal.modal('hide');
						$('.btn-reply-submit:visible:first').click();
					});
				}
			}, info.attr('data-send'));
			return false;
		}
	}
	return can_submit;
});
