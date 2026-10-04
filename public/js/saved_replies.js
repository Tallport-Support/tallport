/**
 * Saved replies: the editor's menu (search, categories, insert, save the
 * current reply), the default reply template, and the settings pages.
 */

// The reply editor's Saved replies picker (conversations/partials/editor_pickers).

// The current reply as a new saved reply.
function savedReplySaveFromReply(name, done)
{
	Tallport.post(laroute.route('saved_replies.ajax'), {
		action: 'save_from_reply',
		mailbox_id: document.getElementById('saved-replies-data').getAttribute('data-mailbox_id'),
		name: name,
		text: getReplyBody()
	}).then(function (response) {
		if (Tallport.result(response)) {
			done();
		}
	});
}

// Put a saved reply (variables filled in, its files) where the cursor was.
function savedReplyInsert(saved_reply_id)
{
	Tallport.post(laroute.route('saved_replies.ajax'), {
		action: 'get',
		saved_reply_id: saved_reply_id,
		mailbox_id: document.getElementById('saved-replies-data').getAttribute('data-mailbox_id'),
		conversation_id: getGlobalAttr('conversation_id')
	}).then(function (response) {
		if (Tallport.result(response)) {
			editorInsert('body', response.text || '');
			savedReplyFiles(response, response.id);
		}
	});
}

// The default reply template goes in an empty reply.
function savedReplyTemplateLoad()
{
	var data = document.getElementById('saved-replies-data');
	if (!data || data.getAttribute('data-template') != '1') {
		return;
	}
	if (stripTags(getReplyBody() || '').trim()) {
		return;
	}
	Tallport.post(laroute.route('saved_replies.ajax'), {
		action: 'template',
		mailbox_id: data.getAttribute('data-mailbox_id'),
		conversation_id: getGlobalAttr('conversation_id')
	}).then(function (response) {
		if (Tallport.isSuccess(response) && response.text) {
			setReplyBody(response.text);
			savedReplyFiles(response);
		}
	});
}

// A saved reply's files (and which saved reply it was) for the composer
// (App\Livewire\ConversationComposer, App\Livewire\NewConversation).
function savedReplyFiles(response, saved_reply_id)
{
	if (saved_reply_id) {
		Livewire.dispatch('composer-saved-reply', {id: saved_reply_id, attachments: response.attachments || []});
	} else {
		Livewire.dispatch('composer-attach', {attachments: response.attachments || []});
	}
}

// The default reply template: in a reply when it opens, in a new
// conversation when the page opens.
document.addEventListener('livewire:init', function() {
	Livewire.on('composer-opened', function(event) {
		if (event.mode == 'reply') {
			setTimeout(savedReplyTemplateLoad, 0);
		}
	});
});
document.addEventListener('DOMContentLoaded', function() {
	var data = document.getElementById('saved-replies-data');
	if (data && data.getAttribute('data-new') == '1') {
		// After the editor is there.
		setTimeout(savedReplyTemplateLoad, 0);
	}
});

// Settings » Saved Replies: drag to change the order of a level.
document.addEventListener('alpine:init', function() {
	window.Alpine.data('tallportSavedRepliesOrder', function(mailbox_id) {
		return {
			init: function() {
				var list = this.$el;
				if (typeof(sortable) == "undefined") {
					return;
				}
				sortable(list, {
					items: 'li',
					handle: '.saved-reply-handle',
					forcePlaceholderSize: true
				});
				list.addEventListener('sortupdate', function(e) {
					var parent_id = e.detail.item.getAttribute('data-parent-id');
					var ids = [];
					list.querySelectorAll('.saved-reply-item[data-parent-id="'+parent_id+'"]').forEach(function(item) {
						ids.push(item.getAttribute('data-saved-reply-id'));
					});
					Tallport.post(laroute.route('saved_replies.ajax'), {
						action: 'sort',
						mailbox_id: mailbox_id,
						saved_replies: ids
					}).then(function() {
						// Children follow their category.
						window.location.reload();
					});
				});
			}
		};
	});
});
