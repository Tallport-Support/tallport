// Workflows: the editor of conditions and actions, the order of the list,
// running a manual workflow from a conversation's menu.

var wf_config = null;
var wf_lang = null;
var wf_editor_i = 0;

function workflowEditorInit()
{
	var data = $('#workflow-data');
	wf_config = JSON.parse(data.attr('data-config'));
	wf_lang = JSON.parse(data.attr('data-lang'));
	var form = $('.workflow-form:first');

	$('.workflow-editor').each(function() {
		var editor = $(this);
		var mode = editor.attr('data-mode');
		var groups = JSON.parse(data.attr('data-'+mode)) || [];
		if (!groups.length) {
			groups = [[{}]];
		}
		$.each(groups, function(i, rows) {
			wfAddGroup(editor, mode, rows);
		});
		$('<button type="button" class="btn btn-default btn-sm wf-add-group"></button>')
			.text(mode == 'conditions' ? wf_lang.add_condition : wf_lang.add_action)
			.click(function() {
				wfAddGroup(editor, mode, [{}]);
			})
			.insertAfter(editor);
	});

	var toggleType = function() {
		var automatic = form.find('select[name="type"]').val() == '1';
		form.find('.workflow-automatic-only').toggle(automatic);
		form.find('.workflow-manual-only').toggle(!automatic);
	};
	form.find('select[name="type"]').change(toggleType);
	toggleType();

	form.submit(function() {
		$('.workflow-editor').each(function() {
			form.find('input[name="'+$(this).attr('data-mode')+'"]').val(JSON.stringify(wfSerialize($(this))));
		});
	});

	$('.workflow-delete').click(function(e) {
		e.preventDefault();
		var link = $(this);
		if (!confirm(link.attr('data-confirm'))) {
			return;
		}
		fsAjax({action: 'delete', workflow_id: link.attr('data-workflow-id')}, laroute.route('workflows.ajax'), function(response) {
			if (isAjaxSuccess(response)) {
				window.location.href = link.attr('data-redirect');
			} else {
				showAjaxError(response);
			}
		}, true);
	});
}

// Conditions or actions by key, and the choices of the type select.
function wfItems(mode)
{
	var items = {};
	$.each(wf_config[mode], function(group_key, group) {
		$.each(group.items || {}, function(key, item) {
			items[key] = item;
		});
	});
	return items;
}

function wfAddGroup(editor, mode, rows)
{
	var group = $('<div class="panel panel-default wf-group"><div class="panel-body"></div></div>');
	if (editor.children('.wf-group').length) {
		$('<div class="wf-and text-help"></div>').text(wf_lang.and).appendTo(editor);
	}
	group.appendTo(editor);
	$.each(rows.length ? rows : [{}], function(i, row) {
		wfAddRow(group, mode, row);
	});
	if (mode == 'conditions') {
		$('<button type="button" class="btn btn-link btn-xs wf-add-or"></button>').text(wf_lang.add_or).click(function() {
			wfAddRow(group, mode, {}, $(this));
		}).appendTo(group.children('.panel-body'));
	}
	return group;
}

function wfAddRow(group, mode, data, before)
{
	var body = group.children('.panel-body');
	var row = $('<div class="wf-row form-inline"></div>');
	if (body.children('.wf-row').length) {
		$('<div class="wf-or text-help"></div>').text(wf_lang.or).insertBefore(before || body.children('.wf-add-or'));
	}
	if (before) {
		row.insertBefore(before);
	} else if (body.children('.wf-add-or').length) {
		row.insertBefore(body.children('.wf-add-or'));
	} else {
		row.appendTo(body);
	}

	var type = $('<select class="form-control input-sm wf-type"></select>');
	type.append($('<option value=""></option>').text('-- '+(mode == 'conditions' ? wf_lang.select_condition : wf_lang.select_action)+' --'));
	$.each(wf_config[mode], function(group_key, config_group) {
		var parent = type;
		if (config_group.title) {
			parent = $('<optgroup></optgroup>').attr('label', config_group.title).appendTo(type);
		}
		$.each(config_group.items || {}, function(key, item) {
			parent.append($('<option></option>').attr('value', key).text(item.title));
		});
	});
	type.val(data.type || '');
	row.append(type, '<span class="wf-operator"></span>', '<span class="wf-value"></span>');
	$('<a href="#" class="wf-remove text-help">&times;</a>').attr('title', wf_lang.remove).click(function(e) {
		e.preventDefault();
		var group_body = row.parent();
		row.prev('.wf-or').remove();
		row.remove();
		group_body.children('.wf-or:first-child').remove();
		if (!group_body.children('.wf-row').length) {
			var panel = group_body.parent();
			panel.prev('.wf-and').remove();
			panel.remove();
		}
	}).appendTo(row);

	type.change(function() {
		wfRenderValue(row, mode, {type: type.val()});
	});
	wfRenderValue(row, mode, data);
}

function wfRenderValue(row, mode, data)
{
	var item = wfItems(mode)[data.type];
	var operator = row.children('.wf-operator').empty();
	var value = row.children('.wf-value').empty();
	row.children('.wf-email').remove();
	if (!item) {
		return;
	}

	if (item.operators) {
		var select = $('<select class="form-control input-sm wf-operator-select"></select>');
		$.each(item.operators, function(key, title) {
			select.append($('<option></option>').attr('value', key).text(title));
		});
		if (data.operator) {
			select.val(data.operator);
		}
		operator.append(select);
	}

	if (item.values_type == 'date') {
		var number = $('<input type="number" min="1" class="form-control input-sm wf-number">').val(data.value ? data.value.number : '');
		var metric = $('<select class="form-control input-sm wf-metric"></select>');
		$.each({i: wf_lang.minutes, h: wf_lang.hours, d: wf_lang.days}, function(key, title) {
			metric.append($('<option></option>').attr('value', key).text(title));
		});
		metric.val(data.value && data.value.metric ? data.value.metric : 'd');
		value.append(number, ' ', metric);
	} else if (item.values_type == 'email') {
		wfEmailEditor(row, data.type, data.value);
	} else if (item.values && item.values.length) {
		var choice = $('<select class="form-control input-sm wf-value-select"></select>');
		if (item.multiple) {
			choice.attr('multiple', 'multiple');
		}
		$.each(item.values, function(i, pair) {
			choice.append($('<option></option>').attr('value', pair[0]).text(pair[1]));
		});
		if (typeof(data.value) != "undefined") {
			choice.val(item.multiple ? $.map($.makeArray(data.value), String) : String(data.value));
		}
		value.append(choice);
		if (item.multiple) {
			choice.select2({width: '300px'});
		}
	} else if (!item.values) {
		$('<input type="text" class="form-control input-sm wf-text">')
			.attr('placeholder', item.placeholder || '')
			.val(typeof(data.value) == "string" ? data.value : '')
			.appendTo(value);
	}
}

// Reply, email to the customer, forward, note: the email's fields.
function wfEmailEditor(row, type, value)
{
	var email = {};
	try {
		email = typeof(value) == "string" ? JSON.parse(value) : (value || {});
	} catch (e) {
		email = {};
	}
	var box = $('<div class="wf-email"></div>').appendTo(row);
	var field = function(name, label) {
		$('<div class="form-group-sm wf-email-field"></div>')
			.append($('<label></label>').text(label))
			.append($('<input type="text" class="form-control input-sm">').attr('data-field', name).val(email[name] || ''))
			.appendTo(box);
	};
	if (type == 'forward') {
		field('to', wf_lang.to);
	}
	if (type != 'note') {
		field('cc', wf_lang.cc);
		field('bcc', wf_lang.bcc);
	}
	if (type == 'email_customer') {
		field('subject', wf_lang.subject);
	}
	wf_editor_i++;
	var textarea = $('<textarea class="form-control" data-field="body"></textarea>').attr('id', 'wf-body-'+wf_editor_i).val(email.body || '');
	box.append(textarea);
	summernoteInit('#wf-body-'+wf_editor_i, {disableDragAndDrop: true});

	if (type == 'reply' || type == 'email_customer') {
		$('<label class="checkbox inline plain"></label>')
			.append($('<input type="checkbox" data-field="no_signature" value="1">').prop('checked', !!email.no_signature), ' ', document.createTextNode(wf_lang.no_signature))
			.appendTo(box);
	}
	if (type != 'note') {
		var history = $('<select class="form-control input-sm" data-field="conv_history"></select>');
		if (type != 'forward') {
			history.append($('<option value=""></option>').text(wf_lang.history_default));
		}
		$.each(wf_lang.history, function(key, title) {
			if (type != 'forward' || key != 'none') {
				history.append($('<option></option>').attr('value', key).text(title));
			}
		});
		history.val(email.conv_history || (type == 'email_customer' ? 'none' : (type == 'forward' ? 'full' : '')));
		var sender = $('<select class="form-control input-sm" data-field="sender_name"></select>');
		$.each(wf_lang.senders, function(key, title) {
			if (key != '1' || type == 'reply') {
				sender.append($('<option></option>').attr('value', key).text(title));
			}
		});
		sender.val(email.sender_name || (type == 'reply' ? '1' : '2'));
		box.append(
			$('<div class="form-group-sm wf-email-field"></div>').append($('<label></label>').text(wf_lang.conv_history), history),
			$('<div class="form-group-sm wf-email-field"></div>').append($('<label></label>').text(wf_lang.sender_name), sender)
		);
	}
}

// The groups of an editor as stored: [[{type, operator, value}, ...], ...].
function wfSerialize(editor)
{
	var mode = editor.attr('data-mode');
	var items = wfItems(mode);
	var groups = [];
	editor.children('.wf-group').each(function() {
		var rows = [];
		$(this).find('.wf-row').each(function() {
			var row = $(this);
			var type = row.children('.wf-type').val();
			var item = items[type];
			if (!type || !item) {
				return;
			}
			var data = {type: type};
			var operator = row.find('.wf-operator-select');
			if (operator.length) {
				data.operator = operator.val();
			}
			if (item.values_type == 'date') {
				data.value = {number: row.find('.wf-number').val(), metric: row.find('.wf-metric').val()};
			} else if (item.values_type == 'email') {
				var email = {};
				row.find('.wf-email [data-field]').each(function() {
					var input = $(this);
					var name = input.attr('data-field');
					if (name == 'body') {
						email.body = input.summernote('code');
					} else if (input.is(':checkbox')) {
						if (input.is(':checked')) {
							email[name] = '1';
						}
					} else if (input.val() !== '') {
						email[name] = input.val();
					}
				});
				data.value = JSON.stringify(email);
			} else if (row.find('.wf-value-select').length) {
				data.value = row.find('.wf-value-select').val();
			} else if (row.find('.wf-text').length) {
				data.value = row.find('.wf-text').val();
			}
			rows.push(data);
		});
		if (rows.length) {
			groups.push(rows);
		}
	});
	return groups;
}

// Mailbox Settings » Workflows: drag to change the order.
function workflowsListInit()
{
	if (typeof(sortable) == "undefined") {
		return;
	}
	$('.workflows-list').each(function() {
		sortable(this, {
			items: 'li',
			handle: '.workflow-handle',
			forcePlaceholderSize: true
		});
		this.addEventListener('sortupdate', function(e) {
			var ids = [];
			$('.workflow-item').each(function() {
				ids.push($(this).attr('data-workflow-id'));
			});
			fsAjax({
					action: 'sort',
					mailbox_id: $(e.target).attr('data-mailbox_id'),
					workflows: ids
				},
				laroute.route('workflows.ajax'),
				function(response) {
					if (!isAjaxSuccess(response)) {
						showAjaxError(response);
					}
				}, true
			);
		});
	});
}

// A conversation's menu: run a manual workflow.
$(document).on('click', '.workflow-run', function(e) {
	e.preventDefault();
	fsAjax({
			action: 'run',
			workflow_id: $(this).attr('data-workflow-id'),
			conversation_id: getGlobalAttr('conversation_id'),
			mailbox_id: getGlobalAttr('mailbox_id')
		},
		laroute.route('workflows.ajax'),
		function(response) {
			if (isAjaxSuccess(response)) {
				if (response.redirect_url) {
					window.location.href = response.redirect_url;
				} else {
					window.location.reload();
				}
			} else {
				showAjaxError(response);
			}
		}, true
	);
});
