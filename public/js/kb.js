/**
 * Knowledge base: the reply editor's menu that inserts an article (#kb-data).
 */

// The reply editor's Knowledge Base picker (conversations/partials/editor_pickers):
// the article's text where the cursor was.
function kbInsert(article_id)
{
	Tallport.post(laroute.route('kb.ajax'), {action: 'get', article_id: article_id}).then(function (response) {
		if (Tallport.isSuccess(response)) {
			editorInsert('body', response.body || '');
		} else {
			Tallport.result(response);
		}
	});
}
