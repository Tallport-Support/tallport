/**
 * Knowledge base: the article editor, and the reply editor's menu that
 * inserts an article (#kb-data).
 */

function kbEditorInit()
{
	$('.kb-delete-form').submit(function() {
		return confirm($(this).attr('data-confirm'));
	});
}

// The reply editor's Knowledge Base picker (conversations/partials/editor_pickers):
// the article's text where the cursor was.
function kbInsert(article_id)
{
	fsAjax({action: 'get', article_id: article_id}, laroute.route('kb.ajax'), function(response) {
		if (isAjaxSuccess(response)) {
			editorInsert('body', response.body || '');
		} else {
			showAjaxError(response);
		}
	}, true);
}
