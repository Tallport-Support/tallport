// Workflows: the editor of conditions and actions, the order of the list,
// running a manual workflow from a conversation's menu.

document.addEventListener('alpine:init', function () {
	/**
	 * The workflow form (workflows/edit, workflows/partials/editor): the type's
	 * settings, and the groups of conditions and actions, posted as JSON
	 * ([[{type, operator, value}, ...], ...]) in the conditions and actions fields.
	 */
	window.Alpine.data('tallportWorkflowEditor', function (type) {
		var config = {};
		var last_id = 0;

		return {
			type: String(type),
			editors: {conditions: [], actions: []},

			init: function () {
				var self = this;
				var data = document.getElementById('workflow-data');
				config = JSON.parse(data.getAttribute('data-config'));
				['conditions', 'actions'].forEach(function (mode) {
					var groups = JSON.parse(data.getAttribute('data-' + mode)) || [];
					if (!groups.length) {
						groups = [[{}]];
					}
					groups.forEach(function (rows) {
						self.addGroup(mode, rows);
					});
				});
			},

			// A condition or an action by its type, or {}.
			itemOf: function (mode, row) {
				var found = null;
				Object.keys(config[mode] || {}).forEach(function (group_key) {
					var items = config[mode][group_key].items || {};
					if (!found && row.type && Object.prototype.hasOwnProperty.call(items, row.type)) {
						found = items[row.type];
					}
				});
				return found || {};
			},

			addGroup: function (mode, rows) {
				var self = this;
				var group = {id: ++last_id, rows: []};
				(rows && rows.length ? rows : [{}]).forEach(function (data) {
					group.rows.push(self.makeRow(mode, data));
				});
				this.editors[mode].push(group);
			},

			addRow: function (mode, group) {
				group.rows.push(this.makeRow(mode, {}));
			},

			removeRow: function (mode, group, row) {
				group.rows.splice(group.rows.indexOf(row), 1);
				if (!group.rows.length) {
					this.editors[mode].splice(this.editors[mode].indexOf(group), 1);
				}
			},

			setType: function (mode, row, type) {
				var fresh = this.makeRow(mode, {type: type});
				row.type = fresh.type;
				row.operator = fresh.operator;
				row.value = fresh.value;
				row.number = fresh.number;
				row.metric = fresh.metric;
				row.email = fresh.email;
			},

			// A row's state from its stored data: the choices the editor shows.
			makeRow: function (mode, data) {
				var row = {id: ++last_id, type: data.type || '', operator: '', value: '', number: '', metric: 'd', email: {}};
				var item = this.itemOf(mode, row);
				var value = data.value;

				if (item.operators) {
					var operators = Object.keys(item.operators);
					row.operator = operators.indexOf(String(data.operator)) != -1 ? String(data.operator) : operators[0];
				}

				if (item.values_type == 'date') {
					row.number = value && value.number ? value.number : '';
					row.metric = value && value.metric ? value.metric : 'd';
				} else if (item.values_type == 'email') {
					row.email = this.makeEmail(row.type, value);
				} else if (item.values && item.values.length) {
					var choices = item.values.map(function (pair) {
						return pair[0];
					});
					if (item.multiple) {
						row.value = [].concat(value === undefined || value === null ? [] : value).map(String).filter(function (choice) {
							return choices.indexOf(choice) != -1;
						});
					} else {
						row.value = value !== undefined && choices.indexOf(String(value)) != -1 ? String(value) : choices[0];
					}
				} else if (!item.values) {
					row.value = typeof(value) == 'string' ? value : '';
				}
				return row;
			},

			// Reply, email to the customer, forward, note: the email's fields, with their defaults.
			makeEmail: function (type, value) {
				var email = {};
				try {
					email = typeof(value) == 'string' ? JSON.parse(value) : (value || {});
				} catch (e) {
					email = {};
				}
				email = Object.assign({to: '', cc: '', bcc: '', subject: '', body: ''}, email);
				email.no_signature = !!email.no_signature;

				var histories = type == 'forward' ? ['last', 'full'] : ['', 'none', 'last', 'full'];
				if (!email.conv_history || histories.indexOf(email.conv_history) == -1) {
					email.conv_history = type == 'email_customer' ? 'none' : (type == 'forward' ? 'full' : '');
				}
				var senders = type == 'reply' ? ['1', '2', '3'] : ['2', '3'];
				email.sender_name = email.sender_name ? String(email.sender_name) : '';
				if (senders.indexOf(email.sender_name) == -1) {
					email.sender_name = type == 'reply' ? '1' : '2';
				}
				return email;
			},

			// FruitUI's editor for an email's body, from the page's template.
			mountEditor: function (container, row) {
				var editor = document.getElementById('wf-editor-template').content.cloneNode(true);
				var textarea = editor.querySelector('textarea');
				textarea.id = 'wf-body-' + row.id;
				textarea.value = row.email.body || '';
				// After the row is in place, so that Alpine starts the editor as an added element.
				this.$nextTick(function () {
					container.appendChild(editor);
				});
			},

			// The groups of an editor as stored: [[{type, operator, value}, ...], ...].
			groups: function (mode) {
				var self = this;
				var groups = [];
				this.editors[mode].forEach(function (group) {
					var rows = [];
					group.rows.forEach(function (row) {
						var item = self.itemOf(mode, row);
						if (!row.type || !item.title) {
							return;
						}
						var data = {type: row.type};
						if (item.operators) {
							data.operator = row.operator;
						}
						if (item.values_type == 'date') {
							data.value = {number: row.number === null || row.number === undefined ? '' : String(row.number), metric: row.metric};
						} else if (item.values_type == 'email') {
							data.value = JSON.stringify(self.emailValue(row));
						} else if (item.values && item.values.length) {
							data.value = item.multiple ? row.value.slice() : row.value;
						} else if (!item.values) {
							data.value = row.value;
						}
						rows.push(data);
					});
					if (rows.length) {
						groups.push(rows);
					}
				});
				return groups;
			},

			// An email action's value: the fields it has, filled in, in the form's order.
			emailValue: function (row) {
				var email = {};
				var type = row.type;
				var fields = [];
				if (type == 'forward') {
					fields.push('to');
				}
				if (type != 'note') {
					fields.push('cc', 'bcc');
				}
				if (type == 'email_customer') {
					fields.push('subject');
				}
				fields.forEach(function (name) {
					if (row.email[name]) {
						email[name] = row.email[name];
					}
				});
				var body = document.getElementById('wf-body-' + row.id);
				email.body = body ? body.value : (row.email.body || '');
				if ((type == 'reply' || type == 'email_customer') && row.email.no_signature) {
					email.no_signature = '1';
				}
				if (type != 'note') {
					['conv_history', 'sender_name'].forEach(function (name) {
						if (row.email[name] !== '') {
							email[name] = row.email[name];
						}
					});
				}
				return email;
			},

			serialize: function () {
				var self = this;
				['conditions', 'actions'].forEach(function (mode) {
					self.$root.querySelector('input[name="' + mode + '"]').value = JSON.stringify(self.groups(mode));
				});
			},

			remove: function (event) {
				var button = event.currentTarget;
				Tallport.confirm({message: button.getAttribute('data-confirm'), confirm: button.textContent.trim(), tone: 'danger'}).then(function (ok) {
					if (!ok) {
						return;
					}
					Tallport.post(laroute.route('workflows.ajax'), {action: 'delete', workflow_id: button.getAttribute('data-workflow-id')}).then(function (response) {
						if (Tallport.isSuccess(response)) {
							window.location.href = button.getAttribute('data-redirect');
						} else {
							Tallport.result(response);
						}
					});
				});
			}
		};
	});
});

// Mailbox Settings » Workflows: drag to change the order.
function workflowsListInit()
{
	if (typeof(sortable) == "undefined") {
		return;
	}
	document.querySelectorAll('.workflows-list').forEach(function (list) {
		sortable(list, {
			items: 'li',
			handle: '.workflow-handle',
			forcePlaceholderSize: true
		});
		list.addEventListener('sortupdate', function (e) {
			var ids = Array.from(document.querySelectorAll('.workflow-item')).map(function (item) {
				return item.getAttribute('data-workflow-id');
			});
			Tallport.post(laroute.route('workflows.ajax'), {
				action: 'sort',
				mailbox_id: e.target.getAttribute('data-mailbox_id'),
				workflows: ids
			}).then(function (response) {
				if (!Tallport.isSuccess(response)) {
					Tallport.result(response);
				}
			});
		});
	});
}

// A conversation's menu: run a manual workflow (App\Workflows\Runner adds the links).
document.addEventListener('click', function (e) {
	var link = e.target.closest ? e.target.closest('.workflow-run') : null;
	if (!link) {
		return;
	}
	e.preventDefault();
	Tallport.post(laroute.route('workflows.ajax'), {
		action: 'run',
		workflow_id: link.getAttribute('data-workflow-id'),
		conversation_id: document.body.getAttribute('data-conversation_id'),
		mailbox_id: document.body.getAttribute('data-mailbox_id')
	}).then(function (response) {
		if (Tallport.isSuccess(response)) {
			if (response.redirect_url) {
				window.location.href = response.redirect_url;
			} else {
				window.location.reload();
			}
		} else {
			Tallport.result(response);
		}
	});
});
