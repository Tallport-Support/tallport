/**
 * Knowledge base: the article editor, and the reply editor's menu that
 * inserts an article (#kb-data).
 */

function kbEditorInit()
{
	summernoteInit('#kb_body', {insertVar: false, disableDragAndDrop: true});
	$('.kb-delete-form').submit(function() {
		return confirm($(this).attr('data-confirm'));
	});
}

var KnowledgeBaseButton = function (context) {
	var ui = $.summernote.ui;
	var data = $('#kb-data');
	var items = JSON.parse(data.attr('data-items') || '[]');

	var html = '<div class="sr-search"><input type="text" class="form-control input-sm" placeholder="'+htmlEscape(data.attr('data-search'))+'" /></div><div class="sr-list">';
	var category = false;
	$.each(items, function(i, item) {
		if (item.category !== category) {
			category = item.category;
			if (category) {
				html += '<div class="kb-menu-category">'+htmlEscape(category)+'</div>';
			}
		}
		html += '<a href="#" class="sr-item kb-menu-item" data-id="'+item.id+'">'+htmlEscape(item.title)+'</a>';
	});
	if (!items.length) {
		html += '<div class="sr-empty text-help">'+htmlEscape(data.attr('data-empty'))+'</div>';
	}
	html += '</div>';

	var button = ui.buttonGroup([
		ui.button({
			className: 'dropdown-toggle',
			contents: '<i class="glyphicon glyphicon-book"></i>',
			tooltip: data.attr('data-title'),
			container: 'body',
			data: {
				toggle: 'dropdown'
			},
			click: function() {
				context.invoke('editor.saveRange');
				var dropdown = $(this).parent().find('.dropdown-kb');
				setTimeout(function() {
					dropdown.find('.sr-search input').val('').trigger('input').focus();
				}, 50);
			}
		}),
		ui.dropdown({
			className: 'dropdown-saved-replies dropdown-kb',
			items: html,
			callback: function(dropdown) {
				kbMenuInit(dropdown, context);
			}
		})
	]);

	return button.render();
};

function kbMenuInit(dropdown, context)
{
	dropdown.on('click', '.sr-search', function(e) {
		e.stopPropagation();
	});
	dropdown.on('click', '.kb-menu-item', function(e) {
		e.preventDefault();
		fsAjax({action: 'get', article_id: $(this).attr('data-id')}, laroute.route('kb.ajax'), function(response) {
			if (isAjaxSuccess(response)) {
				context.invoke('editor.restoreRange');
				context.invoke('editor.pasteHTML', response.body || '');
			} else {
				showAjaxError(response);
			}
		}, true);
	});
	dropdown.on('input', '.sr-search input', function() {
		var query = $(this).val().toLowerCase().trim();
		dropdown.find('.kb-menu-item').each(function() {
			$(this).toggle(!query || $(this).text().toLowerCase().indexOf(query) != -1);
		});
		dropdown.find('.kb-menu-category').toggle(!query);
	});
	dropdown.on('keydown', '.sr-search input', function(e) {
		if (e.which == 13) {
			e.preventDefault();
			var first = dropdown.find('.kb-menu-item:visible:first');
			if (first.length) {
				first.click();
				dropdown.parent().removeClass('open');
			}
		}
	});
}

// The button in the editor's toolbar (where #kb-data is).
fsAddFilter('conversation.editor_toolbar', function(toolbar) {
	if (!$('#kb-data').length) {
		return toolbar;
	}
	fs_conv_editor_buttons.knowledgebase = KnowledgeBaseButton;
	toolbar = $.extend(true, [], toolbar);
	if (toolbar[0] && toolbar[0][1] && $.inArray('knowledgebase', toolbar[0][1]) == -1) {
		toolbar[0][1].push('knowledgebase');
	}
	return toolbar;
});
