/**
 * Saved replies: the editor's menu (search, categories, insert, save the
 * current reply), the default reply template, and the settings pages.
 */

// The editor button: a menu of the saved replies (#saved-replies-data).
var SavedRepliesButton = function (context) {
	var ui = $.summernote.ui;
	var data = $('#saved-replies-data');
	var items = JSON.parse(data.attr('data-items') || '[]');

	var html = '<div class="sr-search"><input type="text" class="form-control input-sm" placeholder="'+htmlEscape(data.attr('data-search'))+'" /></div><div class="sr-list">';
	$.each(items, function(i, item) {
		html += '<a href="#" class="sr-item'+(item.category ? ' sr-category' : '')+'" data-id="'+item.id+'" data-depth="'+item.depth+'"'
			+(item.depth ? ' style="display:none"' : '')+'><span style="padding-left:'+(item.depth * 14)+'px">'
			+(item.category ? '<i class="glyphicon glyphicon-triangle-right"></i> ' : '')+htmlEscape(item.name)+'</span></a>';
	});
	if (!items.length) {
		html += '<div class="sr-empty text-help">'+htmlEscape(data.attr('data-empty'))+'</div>';
	}
	html += '</div>';
	if (data.attr('data-can-save') == '1') {
		html += '<div class="sr-divider"></div><a href="#" class="sr-save-toggle">'+htmlEscape(data.attr('data-save'))+'…</a>'
			+'<div class="sr-save-form hidden"><input type="text" class="form-control input-sm" maxlength="75" placeholder="'+htmlEscape(data.attr('data-name'))+'" />'
			+'<button type="button" class="btn btn-primary btn-xs">'+htmlEscape(data.attr('data-save-button'))+'</button></div>';
	}

	var button = ui.buttonGroup([
		ui.button({
			className: 'dropdown-toggle',
			contents: '<i class="glyphicon glyphicon-comment"></i>',
			tooltip: data.attr('data-title'),
			container: 'body',
			data: {
				toggle: 'dropdown'
			},
			click: function() {
				context.invoke('editor.saveRange');
				var dropdown = $(this).parent().find('.dropdown-saved-replies');
				setTimeout(function() {
					dropdown.find('.sr-search input').val('').trigger('input').focus();
				}, 50);
			}
		}),
		ui.dropdown({
			className: 'dropdown-saved-replies',
			items: html,
			callback: function(dropdown) {
				savedRepliesMenuInit(dropdown, context);
			}
		})
	]);

	return button.render();
};

function savedRepliesMenuInit(dropdown, context)
{
	var children = function(item) {
		var depth = parseInt(item.attr('data-depth'));
		var result = [];
		item.nextAll('.sr-item').each(function() {
			if (parseInt($(this).attr('data-depth')) <= depth) {
				return false;
			}
			result.push(this);
		});
		return $(result);
	};

	// Typing in the menu doesn't close it.
	dropdown.on('click', '.sr-search, .sr-save-form', function(e) {
		e.stopPropagation();
	});

	// A category shows (or hides) what's under it.
	dropdown.on('click', '.sr-category', function(e) {
		e.preventDefault();
		e.stopPropagation();
		var item = $(this);
		var open = !item.hasClass('open');
		item.toggleClass('open', open);
		var depth = parseInt(item.attr('data-depth'));
		children(item).each(function() {
			var child = $(this);
			if (!open) {
				child.hide().removeClass('open');
			} else if (parseInt(child.attr('data-depth')) == depth + 1) {
				child.show();
			}
		});
	});

	dropdown.on('click', '.sr-item:not(.sr-category)', function(e) {
		e.preventDefault();
		savedReplyInsert($(this).attr('data-id'), context);
	});

	// Search: matching saved replies (and their categories) in every category.
	dropdown.on('input', '.sr-search input', function() {
		var query = $(this).val().toLowerCase().trim();
		var items = dropdown.find('.sr-item');
		if (!query) {
			items.removeClass('open').each(function() {
				$(this).toggle($(this).attr('data-depth') == '0');
			});
			return;
		}
		items.hide();
		var shown = [];
		items.not('.sr-category').each(function() {
			if ($(this).text().toLowerCase().indexOf(query) != -1) {
				shown.push(this);
				// Its categories.
				var depth = parseInt($(this).attr('data-depth'));
				$(this).prevAll('.sr-item').each(function() {
					var parent_depth = parseInt($(this).attr('data-depth'));
					if (parent_depth < depth) {
						shown.push(this);
						depth = parent_depth;
					}
					if (depth == 0) {
						return false;
					}
				});
			}
		});
		$(shown).show();
	});
	dropdown.on('keydown', '.sr-search input', function(e) {
		if (e.which == 13) {
			e.preventDefault();
			var first = dropdown.find('.sr-item:not(.sr-category):visible:first');
			if (first.length) {
				first.click();
				dropdown.parent().removeClass('open');
			}
		}
	});

	// The current reply as a new saved reply.
	dropdown.on('click', '.sr-save-toggle', function(e) {
		e.preventDefault();
		e.stopPropagation();
		dropdown.find('.sr-save-form').toggleClass('hidden').find('input').focus();
	});
	dropdown.on('click', '.sr-save-form button', function(e) {
		var button = $(this);
		var name = dropdown.find('.sr-save-form input').val();
		fsAjax({
				action: 'save_from_reply',
				mailbox_id: $('#saved-replies-data').attr('data-mailbox_id'),
				name: name,
				text: getReplyBody()
			},
			laroute.route('saved_replies.ajax'),
			function(response) {
				if (isAjaxSuccess(response)) {
					dropdown.find('.sr-list .sr-empty').remove();
					dropdown.find('.sr-list').append('<a href="#" class="sr-item" data-id="'+response.id+'" data-depth="0"><span>'+htmlEscape(response.name)+'</span></a>');
					dropdown.find('.sr-save-form').addClass('hidden').find('input').val('');
					showFloatingAlert('success', response.msg_success);
				} else {
					showAjaxError(response);
				}
			}, true
		);
	});
}

// Put a saved reply (variables filled in, its files) where the cursor was.
function savedReplyInsert(saved_reply_id, context)
{
	fsAjax({
			action: 'get',
			saved_reply_id: saved_reply_id,
			mailbox_id: $('#saved-replies-data').attr('data-mailbox_id'),
			conversation_id: getGlobalAttr('conversation_id')
		},
		laroute.route('saved_replies.ajax'),
		function(response) {
			if (isAjaxSuccess(response)) {
				context.invoke('editor.restoreRange');
				context.invoke('editor.pasteHTML', response.text || '');
				showAttachments(response);
				$('.form-reply:first input[name="saved_reply_id"]').val(response.id);
			} else {
				showAjaxError(response);
			}
		}, true
	);
}

// The default reply template goes in an empty reply.
function savedReplyTemplateLoad()
{
	var data = $('#saved-replies-data');
	if (!data.length || data.attr('data-template') != '1') {
		return;
	}
	var body = getReplyBody();
	if (body && body != fs_body_default && $.trim($('<div>'+body+'</div>').text())) {
		return;
	}
	fsAjax({
			action: 'template',
			mailbox_id: data.attr('data-mailbox_id'),
			conversation_id: getGlobalAttr('conversation_id')
		},
		laroute.route('saved_replies.ajax'),
		function(response) {
			if (isAjaxSuccess(response) && response.text) {
				setReplyBody(response.text);
				showAttachments(response);
			}
		}, true, function() {}
	);
}

// The button in the editor's toolbar (where #saved-replies-data is).
fsAddFilter('conversation.editor_toolbar', function(toolbar) {
	if (!$('#saved-replies-data').length) {
		return toolbar;
	}
	fs_conv_editor_buttons.savedreplies = SavedRepliesButton;
	toolbar = $.extend(true, [], toolbar);
	if (toolbar[0] && toolbar[0][1] && $.inArray('savedreplies', toolbar[0][1]) == -1) {
		toolbar[0][1].push('savedreplies');
	}
	return toolbar;
});

// The default reply template: in a reply when it opens, in a new
// conversation when the page opens.
fsAddAction('conversation.show_reply_form', function() {
	savedReplyTemplateLoad();
});
$(document).ready(function() {
	if ($('#saved-replies-data').attr('data-new') == '1') {
		// After the editor is there.
		setTimeout(savedReplyTemplateLoad, 0);
	}
});

// Settings » Saved Replies: drag to change the order of a level.
function savedRepliesListInit()
{
	var list = document.querySelector('.saved-replies-list');
	if (!list || typeof(sortable) == "undefined") {
		return;
	}
	sortable(list, {
		items: 'li',
		handle: '.saved-reply-handle',
		forcePlaceholderSize: true
	});
	list.addEventListener('sortupdate', function(e) {
		var parent_id = $(e.detail.item).attr('data-parent-id');
		var ids = [];
		$('.saved-reply-item[data-parent-id="'+parent_id+'"]').each(function() {
			ids.push($(this).attr('data-saved-reply-id'));
		});
		fsAjax({
				action: 'sort',
				mailbox_id: $(list).attr('data-mailbox_id'),
				saved_replies: ids
			},
			laroute.route('saved_replies.ajax'),
			function(response) {
				// Children follow their category.
				window.location.reload();
			}, true
		);
	});
}

// Settings » Saved Replies » a saved reply: the editor with variables.
function savedReplyFormInit()
{
	var selector = '#saved_reply_text';
	summernoteInit(selector, {
		insertVar: true,
		disableDragAndDrop: false,
		callbacks: {
			onInit: function() {
				$(selector).parent().children().find('.note-statusbar').remove();
				editorProcessInsertVar($(selector));
			},
			onImageUpload: function(files) {
				for (var i = 0; files && i < files.length; i++) {
					editorSendFile(files[i], undefined, false, selector);
				}
			}
		}
	});
}
