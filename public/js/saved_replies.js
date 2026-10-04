/**
 * Saved replies: the editor's menu (search, categories, insert, save the
 * current reply), the default reply template, and the settings pages.
 */

// The reply editor's Saved replies picker (conversations/partials/editor_pickers).

// The current reply as a new saved reply.
function savedReplySaveFromReply(name, done)
{
	fsAjax({
			action: 'save_from_reply',
			mailbox_id: $('#saved-replies-data').attr('data-mailbox_id'),
			name: name,
			text: getReplyBody()
		},
		laroute.route('saved_replies.ajax'),
		function(response) {
			if (isAjaxSuccess(response)) {
				showFloatingAlert('success', response.msg_success);
				done();
			} else {
				showAjaxError(response);
			}
		}, true
	);
}

// Put a saved reply (variables filled in, its files) where the cursor was.
function savedReplyInsert(saved_reply_id)
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
				editorInsert('body', response.text || '');
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

