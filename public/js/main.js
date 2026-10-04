var fs_loader_timeout;
var fs_processing_send_reply = false;
var fs_processing_save_draft = false;
var fs_send_reply_allowed = true;
var fs_send_reply_after_draft = false;
var fs_autosave_note = true;
var fs_connection_errors = 0;
var fs_editor_change_timeout = -1;
// For how long to remember conversation note drafts
var fs_keep_conversation_notes = 30; // days
var fs_draft_autosave_period = 12; // seconds
var fs_reply_changed = false;
var fs_in_app_data = {};
var fs_actions = {};
var fs_filters = {};
var fs_body_default = '<div><br></div>';
var fs_prev_focus = true;
var upload_in_progress = false;
var audio_chat;
var autoplay_msg_shown = false;

var FS_STATUS_CLOSED = 3;

// Ajax based notifications;
var poly;
// List of funcions preparin data for polycast receive request
var poly_data_closures = [];

var fs_select2_config = {
	//containerCssClass: "select2-multi-container", // select2-with-loader
	dropdownCssClass: "select2-multi-dropdown",
	//dropdownParent: $('.modal-dialog:visible:first'),
	multiple: true,
	//maximumSelectionLength: 1,
	//placeholder: input.attr('placeholder'),
	minimumInputLength: 1,
	tags: true,
	createTag: function (params) {
	    return {
			id: params.term,
			text: params.term,
			newOption: true
	    }
	},
	templateResult: function (data) {
	    var $result = $("<span></span>");

	    $result.text(data.text);

	    if (data.newOption) {
	     	$result.append(" <em>("+Lang.get("messages.add_lower")+")</em>");
	    }

	    return $result;
	}
};

// Default validation options
// https://devhints.io/parsley
window.ParsleyConfig = window.ParsleyConfig || {};
$.extend(window.ParsleyConfig, {
	// select2-search__field is the additional input created by select2
	excluded: '.note-codable, .parsley-exclude, .select2-search__field',
    errorClass: 'has-error',
    //successClass: 'has-success',
    // Return the $element that will receive these above
	// success or error classes. Could also be (and given
	// directly from DOM) a valid selector like '#div'
    classHandler: function(ParsleyField) {
        return ParsleyField.$element.parents('.form-group:first');
    },
    errorsContainer: function(ParsleyField) {
    	var element = ParsleyField.$element;
    	var help_block = element.parent().children('.help-block:first');

    	if (!help_block.length) {
    		// Show error after select2 field
    		if (element.hasClass('select2-hidden-accessible')) {
    			return element.parent();
    		}
    	}

    	return help_block;
    },
    errorsWrapper: '<div class="help-block"></div>',
    errorTemplate: '<div></div>'
});

// Push notifications
/*Push.config({
    serviceWorker: './customServiceWorker.js', // Sets a custom service worker script
    fallback: function(payload) {
        // Code that executes on browsers with no notification support
        // "payload" is an object containing the
        // title, body, tag, and icon of the notification
    }
});*/

$(document).ready(function(){

	triggersInit();
	shellInit();
	polycastInit();

});

// What the shared scripts set up on the page's body. Run again when
// wire:navigate swaps the body; the scripts themselves load once.
function shellInit()
{
	initNoreplyWarnings();
	webNotificationsInit();
	initAccordionHeading();
}

var fs_navigating = false;
document.addEventListener('livewire:navigating', function() {
	fs_navigating = true;
});
document.addEventListener('livewire:navigated', function() {
	// Also fired on the first page load, which document ready covers.
	if (!fs_navigating) {
		return;
	}
	fs_navigating = false;
	initTooltips();
	initPopovers();
	initModals();
	shellInit();
});

/*function applyVoidLinks()
{
	$('a.void-link').click(function(e) {
		e.preventDefault();
	});
}*/


// Initialize bootstrap tooltip for the element
function initTooltip(selector)
{
	$(selector).tooltip({container: 'body'});
}

function initTooltips()
{
	initTooltip('[data-toggle="tooltip"]');
}

function triggersInit()
{
	// Tooltips
    initTooltips();
    
    var handler = function() {
	  return $('body [data-toggle="tooltip"]').tooltip('hide');
	};
	$(document).on('mouseenter', '.dropdown-menu', handler);
	$(document).on('hidden.bs.dropdown', handler);
	$(document).on('shown.bs.dropdown', handler);

    initPopovers();

	// Modal windows
	initModals();
}

function initPopovers()
{
	$('[data-toggle="popover"]').popover({
	    container: 'body'
	});
}

function initModals(html_tag)
{
	if (typeof(html_tag) == "undefined") {
		html_tag = 'a';
	}
	$(html_tag+'[data-trigger="modal"][data-modal-applied!="1"],.fs-trigger-modal[data-modal-applied!="1"]').attr('data-modal-applied', '1').click(function(e) {
    	triggerModal($(this));
    	e.preventDefault();
	});
}

function fsAjax(data, url, success_callback, no_loader, error_callback, custom_options)
{
	if (!url) {
		console.log('Empty URL');
		return false;
	}
    // Setup AJAX
	ajaxSetup();

	// Show loader
	if (typeof(no_loader) == "undefined" || !no_loader) {
		loaderShow(true);
	}

	if (typeof(error_callback) == "undefined" || !error_callback) {
		error_callback = function() {
			showFloatingAlert('error', Lang.get("messages.ajax_error"));
			ajaxFinish();
		};
	}

	// If this is conversation ajax request, add folder_id to the URL
    if (url.indexOf('/conversation/') != -1) {
        var folder_id = getQueryParam('folder_id');
        if (folder_id) {
        	url = addQueryParam('folder_id', folder_id, url);
        }
    }

    var preserve_xembed = false;
    if (window.location.href.indexOf('x_embed=1') != -1) {
    	url = addQueryParam('x_embed', 1, url);
        preserve_xembed = true;
	}

    var override_success_callback = function(response) {
        if (typeof(response.redirect_url) != "undefined") {
            if (preserve_xembed && response.redirect_url.indexOf('x_embed=1') == -1) {
            	response.redirect_url = addQueryParam('x_embed', 1, response.redirect_url);
            }
        }
        return success_callback(response);
    };

    var options = {
        url: url,
        method: 'post',
        dataType: 'json',
        data: data,
        success: override_success_callback,
        error: error_callback
    };

    if (typeof(custom_options) == "object") {
    	options = {...options, ...custom_options};
    }

	$.ajax(options);
}

// Show loader
function loaderShow(delay)
{
	if (typeof(delay) != "undefined" && delay) {
		fs_loader_timeout = setTimeout(function() {
			$("#loader-main").fadeIn();
	    }, 1000);
	} else {
		$("#loader-main").fadeIn();
	}
}

function loaderHide()
{
	$("#loader-main").hide();
	clearTimeout(fs_loader_timeout);
}


// A toast (FruitUI's toaster); type is success or error. Kept for modules.
function showFloatingAlert(type, msg, no_autohide)
{
	Tallport.toast(msg, type == 'error' ? 'danger' : 'success');
}

function initConversation()
{
	$(document).ready(function(){

	    // Reply
	    jQuery(".conv-reply").click(function(e){
	    	// We don't allow to switch between reply and note, as it creates multiple drafts
	    	if ($(".conv-reply-block").hasClass('hidden') /* || $(this).hasClass('inactive')*/) {
	    		// Show
	    		prepareReplyForm();
				showReplyForm();
				fsDoAction('conversation.show_reply_form');
			} /*else {
				// Hide
				$(".conv-action-block").addClass('hidden');
				$(".conv-action").removeClass('inactive');
			}*/
			e.preventDefault();
		});

		// Add note
	    jQuery(".conv-add-note").click(function(e) {
	    	var reply_block = $(".conv-reply-block");
	    	if (reply_block.hasClass('hidden')  /*|| $(this).hasClass('inactive')*/) {
    			// To prevent browser autocomplete, clean body
				// We have to insert this code to allow proper UL/OL
				setReplyBody('<div><br></div>');
				showNoteForm();
			} /*else {
				// Hide
				$(".conv-action-block").addClass('hidden');
				$(".conv-action").removeClass('inactive');
			}*/
			e.preventDefault();
		});

		// Forward
	    jQuery(".conv-forward").click(function(e){
	    	forwardConversation(e);
			e.preventDefault();
		});

		// View Send Log
	    /*jQuery(".thread-send-log-trigger").click(function(e){
	    	var thread_id = $(this).parents('.thread:first').attr('data-thread_id');
	    	if (!thread_id) {
	    		return;
	    	}
			e.preventDefault();
		});*/

		// Edit draft
		jQuery(".edit-draft-trigger").click(function(e){
			editDraft($(this));
			e.preventDefault();
		});

		// Discard draft
		jQuery(".discard-draft-trigger").click(function(e){
			discardDraft($(this).parents('.thread:first').attr('data-thread_id'));
			e.preventDefault();
		});

		// Chat mode
		// Show details in chat mode
		var conv_top_blocks = $('#conv-top-blocks');
		var is_chat_mode = false;
		if (conv_top_blocks.length) {
			if (!conv_top_blocks.children('.conv-top-block:first').length) {
				conv_top_blocks.prev().hide();
			}
			is_chat_mode = true;
		}

		// Print
		if (getQueryParam('print')) {
			window.print();
		}

		maybeShowStoredNote();
		maybeShowDraft();
		processLinks();
		initConvSettings();

		// Show reply form in chat mode
		if (is_chat_mode && !$('.conv-action.inactive:first').length) {
			$(".conv-reply").click();
		}
		// Send reply on ENTER press in chat mode
		if (is_chat_mode) {

			// Automatically refresh chat list
			$(document).on('keydown', function(e) {
				// Skip inputs and editable areas.
				if (!e.target
					|| e.which != 13
					|| $(e.target).is(':input')
					|| e.altKey
					|| e.shiftKey
					|| e.metaKey
					|| $('.modal:visible').length
					//|| $('#conv-status.open:first').length
				) {
					return;
				}
				
				if (e.which == 13
					&& !e.shiftKey
					&& $(e.target).attr('contentEditable') == 'true'

				) {
					if (!$(':focus').closest('.f-editor').length) {
						return;
					}
					var body = $('#body').val();
					if (!body || body == '<div><br></div>') {
						return;
					}
					var button = $('div.conv-block:not(.conv-note-block) .form-reply:visible .btn-reply-submit:first');
					if (button.length) {
						button.click();
						// Does not work
						e.preventDefault();
						e.stopPropagation();

						// If .conv-top-blocks contain invalid forms, exand it
						$('#conv-top-blocks form').each(function() {
							if (!$(this)[0].checkValidity()) {
								$('#conv-top-blocks').collapse('show');
							}
						});
					}
				}
			});
		}
	});
}

// Create new email conversation
function switchToNewEmailConversation()
{
    $('.conv-switch-button').removeClass('active');
	$('#email-conv-switch').addClass('active');
	$('.email-conv-fields').show();
	$('.phone-conv-fields').hide();
    $('.custom-conv-fields').hide();
	$('#field-to').show();
	$('#name').addClass('parsley-exclude');
	$('#to').removeClass('parsley-exclude');

	$('.conv-block:first').removeClass('conv-note-block').removeClass('conv-phone-block');
	$('#form-create :input[name="is_note"]:first').val(0);
	$('#form-create :input[name="is_phone"]:first').val(0);
	$('#form-create :input[name="type"]:first').val(1);
}

// Create new phone conversation
function switchToNewPhoneConversation()
{
    $('.conv-switch-button').removeClass('active');
	$('#phone-conv-switch').addClass('active');
    $('.custom-conv-fields').hide();
	$('.email-conv-fields').hide();
	$('.phone-conv-fields').show();

	if ($('#to_email').val().length) {
		// Show Email
		$('#field-to_email').show();
		$('#toggle-email').hide();
		$('#field-to').hide();
	} else {
		// Hide Email
		$('#field-to_email').hide();
		$('#toggle-email').show();
		$('#field-to').show();
	}
	$('#field-to').hide();
	$('#name').removeClass('parsley-exclude');
	$('#to').addClass('parsley-exclude');

	$('.conv-block:first').addClass('conv-note-block').addClass('conv-phone-block');

	$('#form-create :input[name="is_note"]:first').val(1);
	$('#form-create :input[name="is_phone"]:first').val(1);
	$('#form-create :input[name="type"]:first').val(Vars.conv_type_phone);

	// Customer name
	initRecipientSelector({
		maximumSelectionLength: 1,
		allow_non_emails: true,
		use_id: true
	}, $('#name:not(.select2-hidden-accessible)')).on('select2:select select2:unselect', function(e) {
		// If customer selects a customer with email, hide Email field.
		var data = e.params.data;
		if (typeof(data.newOption) == "undefined" && data.selected) {
			// User added custom name, so hide Email
			$('#conv-to-email-group').hide();
		} else {
			// User selected existing customer or unselected, so show Email
			$('#conv-to-email-group').show();

			// Reset customer_id on unselect
			if (!data.selected) {
				$('#form-create :input[name="customer_id"]:first').val('');
			}
		}
	});

	// Email
	initRecipientSelector({
		maximumSelectionLength: 1,
		search_by: 'email'
	}, $('#to_email:not(.select2-hidden-accessible)'));

	// Phone
	initRecipientSelector({
		maximumSelectionLength: 1,
		allow_non_emails: true,
		search_by: 'phone',
		show_fields: 'phone',
	}, $('#phone:not(.select2-hidden-accessible)'));
}

// Add target blank to all links in threads.
function processLinks()
{
	$('.thread-content a').attr('target', '_blank');
}

// Get current conversation assignee
function getConvData(field)
{
	if (field == 'user_id') {
		return $('#conv-assignee [data-user_id].active:first').attr('data-user_id');
	}
	return null;
}

function showNoteForm()
{
	var reply_block = $(".conv-reply-block");
	if (reply_block.hasClass('hidden')  /*|| $(this).hasClass('inactive')*/) {
		// Show
		hideActionBlocks();
		reply_block.removeClass('hidden')
			.addClass('conv-note-block')
			.removeClass('conv-forward-block')
			.children().find(":input[name='is_note']:first").val(1);
		$('#conv-subject').addClass('action-visible');
		reply_block.children().find(":input[name='thread_id']:first").val('');
		reply_block.children().find(":input[name='subtype']:first").val('');
		//$(".conv-reply-block").children().find(":input[name='body']:first").val('');

		// Note never changes Assignee by default
		reply_block.children().find(":input[name='user_id']:first").val(getConvData('user_id'));

		// Show default status
		var input_status = reply_block.children().find(":input[name='status']:first");
		input_status.val(input_status.attr('data-note-status'));
		updateSendButtonLabel();

		$(".attachments-upload:first :input, .attachments-upload:first li").remove();

		$(".conv-action").addClass('inactive');
		$(this).removeClass('inactive');
		editorFocus('body');

		maybeScrollToReplyBlock();
	}
}

// Prepare reply/forward form for display
function prepareReplyForm()
{
	// To prevent browser autocomplete, clean body
	if (!$('.conv-action.inactive:first').length) {
		// We have to insert this code to allow proper UL/OL
		setReplyBody('<div><br></div>');
	}

	// Set assignee in case it has been changed in the Note editor
	var default_assignee = $(".conv-reply-block").children().find(":input[name='user_id']:first option[data-default='true']").attr('value');
	if (default_assignee) {
		$(".conv-reply-block").children().find(":input[name='user_id']:first").val(default_assignee);
	}

	// Show default status
	var input_status = $(".conv-reply-block").children().find(":input[name='status']:first");
	input_status.val(input_status.attr('data-reply-status'));
	updateSendButtonLabel();

	// Clean attachments
	$(".attachments-upload:first :input, .attachments-upload:first li").remove();
}

function showReplyForm(data, scroll_offset)
{
	hideActionBlocks();
	$(".conv-reply-block").removeClass('hidden')
		.removeClass('conv-note-block')
		.removeClass('conv-forward-block')
		.children().find(":input[name='is_note']:first").val('');
	$('#conv-subject').addClass('action-visible');
	$(".conv-reply-block :input[name='thread_id']:first").val('');
	$(".conv-reply-block :input[name='subtype']:first").val('');

	// When switching from note to reply, body has to be preserved
	//$(".conv-reply-block").children().find(":input[name='body']:first").val(body_val);
	$(".conv-action").addClass('inactive');
	$(".conv-reply:first").removeClass('inactive');

	if (typeof(data) != "undefined" && data) {
		for (field in data) {
			$(".conv-reply-block form:first :input[name='"+field+"']").val(data[field]);
			if (field == 'body') {
				// Display body value in editor
				editorSetContent('body', data[field]);
			}
			// Happens when opening draft or after Undo
			if (field == 'to_email' || field == 'cc' || field == 'bcc') {
				if (data && typeof(data.to) != "undefined") {
					// Clean previous values.
					// Also allows to avoid duplicating emails - for example
					// when restoring  a draft in a conversation with CC.
					cleanSelect2($("#"+field));
					for (var i in data[field]) {
						var email = data[field][i];
						addSelect2Option($("#"+field), {
							id: email, text: email
						});
					}
				} else {
					// It's not clear when this is supposed to happen.
					$("#"+field).children('option:first').removeAttr('selected');
				}
			}
		}

		// Show attachments
		showAttachments(data);

		// Show Cc/Bcc
		if (data.cc || data.bcc ) {
	    	$('#toggle-cc').click();
		}
	}
	$("#to").removeClass('hidden');
	$("#to_email").addClass('hidden').addClass('parsley-exclude').next('.select2:first').hide();

	// Focus reply area. Do not focus when creating a new conversation.
	//if (!$('#to').length) {
	if (!$('#subject').length) {
		editorFocus('body');
	}

	if (!isChatMode()) {
		// Select2 for CC/BCC
		initRecipientSelector();
	} else {
		$('form.form-reply:first .field-cc').addClass('hidden');
	}

	if (typeof(scroll_offset) == "undefined") {
		scroll_offset = 0;
	}
	maybeScrollToReplyBlock(scroll_offset);
}

function cleanSelect2(select)
{
	select.children('option').remove();
	select.val('').trigger('change');
}

// Add an option to select2
// 	var data = {
//	    id: 1,
//	    text: 'Barn owl'
//	};
function addSelect2Option(select, data)
{
	if (!data.id || !data.text) {
		return;
	}

	var new_option = new Option(data.text, data.id, true, true);
	select.append(new_option).trigger('change');
}

// Show attachments after loading via ajax.
function showAttachments(data)
{
	if (data && data.attachments && data.attachments.length) {
		var attachments_container = $(".attachments-upload:first");
		for (var i = 0; i < data.attachments.length; i++) {
			var attachment = data.attachments[i];

			// Inputs
			var input_html = '<input type="hidden" name="attachments_all[]" value="'+attachment.id+'" />';
			input_html += '<input type="hidden" name="attachments[]" value="'+attachment.id+'" class="atachment-upload-'+attachment.id+'" />';
			attachments_container.prepend(input_html);

			// Links
			var attachment_html = '<li class="atachment-upload-'+attachment.id+' attachment-loaded"><a href="'+attachment.url+'" class="break-words" target="_blank">'+attachment.name+'<span class="ellipsis">…</span> </a> <span class="text-help">('+formatBytes(attachment.size)+')</span> <i class="glyphicon glyphicon-remove" data-attachment-id="'+attachment.id+'"></i></li>';
			attachments_container.find('ul:first').append(attachment_html);

			// Delete attachment
			$('li.attachment-loaded .glyphicon-remove').click(function(e) {
				removeAttachment($(this).attr('data-attachment-id'));
			});

			attachments_container.show();
        }
	}
}

function getGlobalAttr(attr)
{
	return $("body:first").attr('data-'+attr);
}

function setGlobalAttr(attr, value)
{
	return $("body:first").attr('data-'+attr, value);
}

// Initialize conversation body editor
function convEditorInit()
{
	// The reply editor (FruitUI's x-fruit::editor on #body) mirrors its HTML
	// to the textarea: input while typing, change on blur.
	$('#body').on('input', function() {
		// Not when the reply is empty and never changed
		if (!$(this).val() && !fs_reply_changed) {
			return;
		}
		onReplyChange();
	}).on('change', function() {
		onReplyBlur();
	});

	// Images pasted or dropped are embedded in the reply (FruitUI's upload hook).
	$('#body').on('fruit-editor-upload', function(e) {
		var detail = e.originalEvent.detail;
		e.stopPropagation();
		for (var i = 0; i < detail.files.length; i++) {
			editorSendFile(detail.files[i], false, true, '#body', undefined, detail.insert);
		}
	});

	// Track changes to save draft
	$("#to, #to_email, #cc, #bcc, #subject, #name, #phone").on('keyup keypress', function(event) {
		onReplyChange();
	}).blur(function(event) {
	    onReplyBlur();
	});

	// New conversation: load customer info
	$("#to").on('change', function(event) {
		// Autosave to be able to populate customer placeholders in the body
		autosaveDraft();

		var to = $('#to').val();
		//var clean_customer = true;
		// Do not clean customer info if customer has not changed
		/*if (Array.isArray(to) && to.length == 1 && typeof(to[0]) != "undefined") {
			if (to[0] == $('#conv-layout-customer li.customer-email:first').text()) {
				clean_customer = false;
			}
		}
		if (clean_customer) {*/
		$('#conv-layout-customer').html('');
		// Load customer info
		if (Array.isArray(to) && to.length == 1 && typeof(to[0]) != "undefined") {
			fsAjax({
				action: 'load_customer_info',
				customer_email: to[0],
				mailbox_id: getGlobalAttr('mailbox_id'),
				conversation_id: getGlobalAttr('conversation_id')
			}, laroute.route('conversations.ajax'), function(response) {
				if (isAjaxSuccess(response) && typeof(response.html) != "undefined") {
					$('#conv-layout-customer').html(response.html);
				}
			}, true, function() {
				// Do nothing
			});
		}
	});
	
	// select2 does not react on keyup or keypress
	$(".recipient-select, .draft-changer").on('change', function(event) {
		onReplyChange();
		onReplyBlur();
	});

	fsDoAction('conv_editor_init');

	// Autosave draft periodically
	autosaveDraft();
}

// Automatically save draft
function autosaveDraft()
{
	if (!isNote() || isPhone()) {
		saveDraft(false, true, true);
	}
	setTimeout(function(){ autosaveDraft() }, fs_draft_autosave_period*1000);
}

function ajaxSetup()
{
	$.ajaxSetup({
		headers: {
	    	'X-CSRF-TOKEN': getCsrfToken()
		}
	});
}

function onReplyChange()
{
	// Mark draft as unsaved
	if (fs_editor_change_timeout && fs_editor_change_timeout != -1) {
		return;
	}

	fs_editor_change_timeout = setTimeout(function(){
		// Do not save note
		/*if ($(".form-reply:first :input[name='is_note']:first").val()) {
			return;
		}*/

		$('.form-reply:first .note-btn-save-draft:first').removeClass('text-success');
		fs_editor_change_timeout = null;
		fs_reply_changed = true;
	}, 100);
}

// Save reply draft or note on form focus out
function onReplyBlur()
{
	// If start saving draft immediately, then when Send Reply is clicked
	// two ajax requests will be sent at the same time.
	setTimeout(function() {
		// Do not save if user clicked Send Reply button
		if (fs_processing_send_reply) {
			return;
		}

		// Save only after changing
		//if (!fs_editor_change_timeout || fs_editor_change_timeout == null) {
		if (isNote()) {
			// Save note
			rememberNote();
		} else {
  			saveDraft(false, true, true);
  		}
	  	//}
	  }, 500);
}

// Are we editing a note
function isNote()
{
	return $(".form-reply:first :input[name='is_note']:first").val();
}

// Is it a new phone conversation draft
function isPhone()
{
	return $("#form-create :input[name='is_phone']:first").val();
}

// Generate random unique ID
function generateDummyId()
{
	// Math.random should be unique because of its seeding algorithm.
	// Convert it to base 36 (numbers + letters), and grab the first 9 characters
	// after the decimal.
	return '_' + Math.random().toString(36).substr(2, 9);
}

// Save file uploaded in editor
function editorSendFile(file, attach, is_conv, editor_id, container, insert)
{
	if (!file || typeof(file.type) == "undefined") {
		return false;
	}
	if (typeof(container) == "undefined") {
		container = $(".attachments-upload:first");
	}

	var attachments_container = container;
	var attachment_dummy_id = generateDummyId();
	var route = '';

	if (is_conv) {
		route = 'conversations.upload';
		editor_id = '#body';
	} else {
		route = 'uploads.upload';
		if (typeof(editor_id) == "undefined" || !editor_id) {
			editor_id = '#signature';
		}
	}

	ajaxSetup();

 	if (typeof(attach) == "undefined") {
		attach = false;
	}

	// Images are embedded by default, other files attached
	if (file.type.indexOf('image/') == -1) {
		attach = true;
	}

	// Show loader
	if (attach) {
		var attachment_html = '<li class="atachment-upload-'+attachment_dummy_id+'"><img src="'+Vars.public_url+'/img/loader-tiny.gif" width="16" height="16"/> <a href="#" class="break-words disabled" target="_blank">'+file.name+'<span class="ellipsis">…</span> </a> <span class="text-help">('+formatBytes(file.size)+')</span> <i class="glyphicon glyphicon-remove" data-attachment-id="'+attachment_dummy_id+'"></i></li>';
		attachments_container.children('ul:first').append(attachment_html);

		// Delete attachment
		$('li.atachment-upload-'+attachment_dummy_id+' .glyphicon-remove:first').click(function(e) {
			removeAttachment($(this).attr('data-attachment-id'));
		});

		attachments_container.show();
	} else {
		loaderShow();
	}

	data = new FormData();
	data.append("file", file);
	if (attach) {
		data.append("attach", 1);
	} else {
		data.append("attach", 0);
	}
	upload_in_progress = true;
	$.ajax({
		url: laroute.route(route),
		data: data,
		cache: false,
		contentType: false,
		processData: false,
		type: 'POST',
		success: function(response){
			if (typeof(response.url) == "undefined" || !response.url) {
				msg = Lang.get("messages.error_occurred");
				if (typeof(response.msg) != "undefined" && response.msg) {
					msg = response.msg;
				}
				showFloatingAlert('error', msg);
				loaderHide();
				removeAttachment(attachment_dummy_id);
				upload_in_progress = false;
				return;
			}
			// Finish loading
			if (attach) {
				$('li.atachment-upload-'+attachment_dummy_id+':first').addClass('attachment-loaded');
				$('li.atachment-upload-'+attachment_dummy_id+':first a').removeClass('disabled').attr('href', response.url);
			} else {
				loaderHide();
			}
			if (typeof(response.status) == "undefined" || response.status != "success") {
				showAjaxError(response);
				removeAttachment(attachment_dummy_id);
				upload_in_progress = false;
				return;
			}
			if (attach) {
				fs_reply_changed = true;

				if (typeof(response.attachment_id) == "undefined" && typeof(response.url) != "undefined" && response.url) {
					// Insert link to uploaded file into the editor
					editorInsert(editor_id, '<a href="'+response.url+'">'+htmlEscape(file.name)+'</a>');
				}
			} else {
				// Embed image
				if (typeof(insert) == "function") {
					insert(response.url, file.name);
				} else {
					editorInsert(editor_id, '<img src="'+response.url+'" alt="'+htmlEscape(file.name)+'">');
				}
			}
			if (typeof(response.attachment_id) != "undefined" || response.attachment_id) {
				var input_html = '<input type="hidden" name="attachments_all[]" value="'+response.attachment_id+'" />';
				input_html += '<input type="hidden" name="attachments[]" value="'+response.attachment_id+'" class="atachment-upload-'+attachment_dummy_id+'" />';
				if (!attach) {
					input_html += '<input type="hidden" name="embeds[]" value="'+response.attachment_id+'" class="atachment-upload-'+attachment_dummy_id+'" />';
				}
				attachments_container.prepend(input_html);
			}
			upload_in_progress = false;
		},
		error: function(jqXHR, textStatus, errorThrown) {
			if (attach) {
				removeAttachment(attachment_dummy_id);
			} else {
				loaderHide();
			}
			showFloatingAlert('error', Lang.get("messages.error_occurred")+' Error '+jqXHR.status+'. '+errorThrown);
			upload_in_progress = false;
		}
	});
}

function removeAttachment(attachment_id)
{
	$('.atachment-upload-'+$.escapeSelector(attachment_id)).remove();
	//attachment.parent().parent().children(":input[value='"+attachment_id+"']");
}


function formatBytes(size)
{
	precision = 2;
	size = parseInt(size);
    if (!isNaN(size) && size > 0) {
        base = Math.log(size) / Math.log(1024);
        suffixes = [' b', ' KB', ' MB', ' GB', ' TB'];

        return Math.round(Math.pow(1024, base - Math.floor(base)), precision)+''+suffixes[Math.floor(base)];
    } else {
        return size;
    }
}

// New conversation page
function initNewConversation(is_phone)
{
    $(document).ready(function() {
    	if (typeof(is_phone) != "undefined") {
        	switchToNewPhoneConversation();
        }
	    $('#toggle-email').click(function(e) {
			$('#field-to_email').show();
			$(this).hide();
			e.preventDefault();
		});
		$('#email-conv-switch').click(function() {
			switchToNewEmailConversation();
		});
		$('#phone-conv-switch').click(function() {
			switchToNewPhoneConversation();
		});
		// Delete attachments
		$('li.attachment-loaded .glyphicon-remove').click(function(e) {
			removeAttachment($(this).attr('data-attachment-id'));
		});
    });
}

// To, Cc, Bcc selector
function initRecipientSelector(custom_options, selector)
{
	var options = {
		editable: true,
		use_id: false,
		containerCssClass: 'select2-recipient',
		//selectOnClose: true,
		// For hidden inputs
		width: '100%'
	};

	if (typeof(custom_options) == "undefined") {
		custom_options = {};
	}

	$.extend(options, custom_options);

	if (typeof(selector) == "undefined") {
		selector = $('.recipient-select:visible:not(.select2-hidden-accessible)');
	}

	var result = initCustomerSelector(selector, options);

	result = fsApplyFilter('conversation.recipient_selector', result, {selector:selector});

	if (options.editable) {
		result.on('select2:closing', function(e) {
			var params = e.params;
			var select = $(e.target);

			var value = select.next('.select2:first').children().find('.select2-search__field:first').val();
			value = value.trim();
			if (!value) {
				return;
			}

			// Don't allow to create a tag if there is no @ symbol
			if (typeof(custom_options.allow_non_emails) == "undefined") {
			    if (!/^.+@.+$/.test(value)) {
					// Return null to disable tag creation
					return null;
			    }
			}

			// Don't select an item if the close event was triggered from a select or
			// unselect event
		    if (params && params.args && params.args.originalSelect2Event != null) {
				var event = params.args.originalSelect2Event;

				if (event._type === 'select' || event._type === 'unselect') {
					return;
				}
		    }

			var data = select.select2('data');

			// Check if select already has such option
		    for (i in data) {
		    	if (data[i].id == value) {
		    		return;
		    	}
		    }

		    addSelect2Option(select, {
		        id: value,
		        text: value,
		        selected: true
		    });
		});
	}

	fsDoAction('conversation.recipient_selector_initialized', {selector:selector});

	return result;
}

function initReplyForm(load_attachments, init_customer_selector, is_new_conv)
{
	$(document).ready(function() {

		convEditorInit();
		if (typeof(load_attachments) != "undefined") {
			loadAttachments();
		}

		// Customer selector
		if (typeof(init_customer_selector) != "undefined") {
			initRecipientSelector();
		}

		// New conversation
		if (typeof(is_new_conv) != "undefined") {
			$('#to').on('select2:closing', function(e) {
				var select = $(e.target);
				if (select.val().length > 1) {
					$('#multiple-conversations-wrap').removeClass('hidden');
				} else {
					$('#multiple-conversations-wrap').addClass('hidden');
				}
			});
		}

		// Show CC
	    $('#toggle-cc').click(function(e) {
			$('.field-cc').removeClass('hidden');
			$(this).parent().remove();
			initRecipientSelector();
			e.preventDefault();
		});

		// CMD+Enter (Mac) sends the reply — mirrors Ctrl+Enter on non-Mac.
		// metaKey is explicitly skipped in the chat-mode Enter handler, so this
		// separate handler is needed for regular reply/note forms.
		// https://github.com/freescout-help-desk/freescout/issues/4425
		$(document).on('keydown.cmd-enter-send', function(e) {
			if (!(e.metaKey || e.ctrlKey) || e.which != 13 || e.altKey || e.shiftKey) {
				return;
			}
			if (isChatMode() || $('.modal:visible').length) {
				return;
			}
			if (!$(':focus').closest('.f-editor').length) {
				return;
			}
			var button = $('.form-reply:visible .btn-reply-submit:first');
			if (button.length) {
				e.preventDefault();
				button.click();
			}
		});

		// Send reply, new conversation or note
	    // Send with a status from the menu: that status, then send.
	    $(".dropdown-send-status [data-send-status]").click(function(e) {
	    	e.preventDefault();
	    	$(this).closest('#editor_bottom_toolbar').find('select[name="status"]:first').val($(this).attr('data-send-status'));
	    	updateSendButtonLabel();
	    	$(this).closest('.btn-group-send').find('.btn-reply-submit:visible:first').click();
	    });
	    $('#editor_bottom_toolbar select[name="status"]').change(function(e) {
	    	updateSendButtonLabel();
	    });
	    updateSendButtonLabel();

	    $(".btn-reply-submit").click(function(e) {

			// Wait till all files uploaded.
			if (upload_in_progress) {
				return;
			}

	    	// This is extra protection from double click on Send button
	    	// DOM operation are slow sometimes
	    	if (fs_processing_send_reply) {
	    		return;
	    	}

	    	fs_processing_send_reply = true;

	    	var button = $(this);
			var editor = $('#body');

	    	// Validate before sending
	    	form = $(".form-reply:first");

			// Visually empty content (e.g. <p></p>) counts as empty (issue #4590).
			if (editor.length && !$.trim(editor.val().replace(/<(?!img\b)[^>]+>/gi, '').replace(/&nbsp;/gi, ''))) {
				editor.val('');
			}

	    	if (!form.parsley().validate()) {
	    		fs_processing_send_reply = false;
	    		return;
	    	}

	    	// If draft is being sent, we need to wait and send reply after draft has been saved.
	    	if (fs_processing_save_draft) {
	    		fs_send_reply_after_draft = true;
	    		return;
	    	}

	    	if (!fsApplyFilter('conversation.can_submit', true, {trigger: button, form: form})) {
	    		fs_processing_send_reply = false;
	    		return;
	    	}

	    	// For previous filter
	    	if (!fs_send_reply_allowed) {
	    		fs_processing_send_reply = false;
	    		return;
	    	}

			data = form.serialize();
	    	data += '&action=send_reply';

	    	button.button('loading');
	    	var is_note = isNote();
	    	var is_chat = isChatMode();
	    	var disable_editor = isChatMode() && !is_note;
	    	if (disable_editor) {
	    		editor.prop('readonly', true);
	    	}

			fsAjax(data, laroute.route('conversations.ajax'), function(response) {
					if (typeof(response.status) != "undefined" && response.status == 'success') {
						// Forget note
						if (is_note) {
							fs_autosave_note = false;
							forgetNote(getGlobalAttr('conversation_id'));
						}
						if (typeof(response.redirect_url) != "undefined" && !is_chat) {
							window.location.href = response.redirect_url;
						} else {
							window.location.href = '';
						}
					} else {
						showAjaxError(response);
						button.button('reset');
						if (disable_editor) {
							editor.prop('readonly', false);
						}
					}
					loaderHide();
					fs_processing_send_reply = false;
				},
				true,
				function() {
					showFloatingAlert('error', Lang.get("messages.ajax_error"));
					loaderHide();
					button.button('reset');
					if (disable_editor) {
						$('#body').prop('readonly', false);
					}
					fs_processing_send_reply = false;
				});

			e.preventDefault();
		});

	    $('#conv-subject .switch-to-note').click(function(e) {
			switchToNote();
			e.preventDefault();
		});
	});
}

// AI Assistant reply drafts in a conversation.
function aiDraftsInit()
{
	$(document).ready(function() {
		var panel = $('.ai-draft-panel:first');
		if (!panel.length) {
			return;
		}

		// Simple Markdown (paragraphs, lists, bold, italic) as HTML.
		function markdownToHtml(text)
		{
			var html = '';
			var paragraph = [];
			var list = '';
			var inline = function(line) {
				return htmlEscape(line).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/\*([^*]+)\*/g, '<em>$1</em>');
			};
			var closeParagraph = function() {
				if (paragraph.length) {
					html += '<p>'+paragraph.map(inline).join('<br>')+'</p>';
					paragraph = [];
				}
			};
			var closeList = function() {
				if (list) {
					html += '</'+list+'>';
					list = '';
				}
			};
			$.each(String(text || '').replace(/\r\n?/g, '\n').split('\n'), function(i, line) {
				line = $.trim(line);
				var item = line.match(/^[-*]\s+(.+)$/) || line.match(/^\d+[.)]\s+(.+)$/);
				if (!line) {
					closeParagraph();
					closeList();
				} else if (item) {
					var type = /^[-*]/.test(line) ? 'ul' : 'ol';
					closeParagraph();
					if (list != type) {
						closeList();
						list = type;
						html += '<'+type+'>';
					}
					html += '<li>'+inline(item[1])+'</li>';
				} else {
					closeList();
					paragraph.push(line);
				}
			});
			closeParagraph();
			closeList();

			return html;
		}

		function reset(status)
		{
			window.clearTimeout(panel.data('timer'));
			panel.removeClass('hidden');
			panel.find('.ai-draft-meta').text('');
			panel.find('.ai-draft-status').removeClass('text-danger').text(status || '');
			panel.find('.ai-draft-body, .ai-draft-translation, .ai-draft-actions, .ai-draft-notes, .ai-draft-docs').addClass('hidden');
			panel.find('ul').empty();
		}

		function showError(message, detail)
		{
			reset(message || panel.attr('data-text-failed'));
			panel.find('.ai-draft-status').addClass('text-danger');
			if (detail) {
				panel.find('.ai-draft-body').removeClass('hidden').text(detail);
			}
			panel.find('.ai-draft-actions').removeClass('hidden').find('.ai-draft-insert').addClass('hidden');
		}

		function show(draft)
		{
			reset('');
			panel.data('draft', draft);
			panel.find('.ai-draft-meta').text(draft.language+' · '+draft.confidence);
			panel.find('.ai-draft-body').removeClass('hidden').html(markdownToHtml(draft.draft));
			panel.find('.ai-draft-actions').removeClass('hidden').find('.ai-draft-insert').removeClass('hidden');
			if (draft.translation) {
				panel.find('.ai-draft-translation').removeClass('hidden').find('.ai-draft-translation-body').text(draft.translation);
			}
			$.each(draft.staff_notes || [], function(i, note) {
				panel.find('.ai-draft-notes').removeClass('hidden').find('ul').append($('<li>').text(note));
			});
			$.each(draft.retrieved_documents || [], function(i, doc) {
				var item = $('<li>').text(doc.title+' ');
				if (/^https?:\/\//i.test(doc.url || '')) {
					item.append($('<a target="_blank" rel="noopener noreferrer">').attr('href', doc.url).text(doc.url));
				}
				panel.find('.ai-draft-docs').removeClass('hidden').find('ul').append(item);
			});
		}

		function poll(url, attempt)
		{
			$.get(url, function(response) {
				if (response.status != 'success') {
					showError(response.msg, response.detail);
				} else if (response.draft_status == 'completed') {
					show(response);
				} else if (attempt >= 180) {
					showError(panel.attr('data-text-slow'));
				} else {
					panel.find('.ai-draft-status').text(panel.attr(response.draft_status == 'running' ? 'data-text-drafting' : 'data-text-queued'));
					panel.data('timer', window.setTimeout(function() {
						poll(url, attempt + 1);
					}, 2000));
				}
			}).fail(function(xhr) {
				showError(xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : '');
			});
		}

		$(document).on('click', '.ai-draft-action', function(e) {
			e.preventDefault();
			// The draft is shown below the editor: open it, as Reply does.
			if ($('.conv-reply-block:first').hasClass('hidden')) {
				prepareReplyForm();
				showReplyForm();
				fsDoAction('conversation.show_reply_form');
			}
			reset(panel.attr('data-text-queued'));
			fsAjax({}, panel.attr('data-draft-url'), function(response) {
				if (response.status == 'success') {
					poll(response.poll_url, 1);
				} else {
					showError(response.msg);
				}
			}, true, function(xhr) {
				showError(xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : '');
			});
		});

		$(document).on('click', '.ai-draft-insert', function(e) {
			e.preventDefault();
			var draft = panel.data('draft');
			if (!draft) {
				return;
			}
			if ($('.conv-reply-block:first').hasClass('hidden') || $('.conv-reply-block:first').hasClass('conv-note-block') || $('.conv-reply-block:first').hasClass('conv-forward-block')) {
				prepareReplyForm();
				showReplyForm();
			}
			setReplyBody(markdownToHtml(draft.draft));
			var form = $('.form-reply:first');
			form.find('input[name^="ai_draft_translation"]').remove();
			if (draft.translation) {
				form.append($('<input type="hidden" name="ai_draft_translation">').val(draft.translation));
				form.append($('<input type="hidden" name="ai_draft_translation_language">').val(panel.attr('data-translation-language')));
			}
		});
	});
}

function getQueryParam(name, qs) {
	var arrays_without_indexes = {};

	if (typeof(qs) == "undefined") {
		qs = document.location.search;
	}
    qs = qs.split('+').join(' ');

    var params = {},
        tokens,
        re = /[?&]?([^=]+)=([^&]*)/g;

    while (tokens = re.exec(qs)) {
    	key = decodeURIComponent(tokens[1]);

        // Arrays without indexes - []
        if (/\[\]$/.test(key)) {
        	if (typeof(arrays_without_indexes[key]) == "undefined") {
        		arrays_without_indexes[key] = 0;
        	} else {
        		arrays_without_indexes[key]++;
        	}
        	parsed_key = /(.*)\[\]$/.exec(key);
        	if (typeof(parsed_key[1]) != "undefined") {
        		key = parsed_key[1]+'['+arrays_without_indexes[key]+']';
        	}
        }
        params[key] = decodeURIComponent(tokens[2]);
    }

    // Process arrays
    for (var param in params) {

    	// Skip __proto__
    	// https://github.com/freescout-helpdesk/freescout/security/advisories/GHSA-rx6j-4c33-9h3r
		if (param.match(/__proto__/i)) {
    		continue;
    	}

    	// Two dimentional
    	var m = param.match(/^([^\[]+)\[([^\[]+)\]$/i);

    	if (m && m.length) {
    		if (typeof(params[m[1]]) == "undefined") {
    			params[m[1]] = {};
    		}
    		if (typeof(params[m[1]]) == "object") {
    			params[m[1]][m[2]] = params[param];
    		}
    	}

    	// Three dimentional
    	var m = param.match(/^([^\[]+)\[([^\[]+)\]\[([^\[]+)\]$/i);

    	if (m && m.length) {
    		if (typeof(params[m[1]]) == "undefined") {
    			params[m[1]] = {};
    		}
    		if (typeof(params[m[1]]) == "object") {
    			if (typeof(params[m[1]][m[2]]) == "undefined") {
    				params[m[1]][m[2]] = {};
    			}
    			params[m[1]][m[2]][m[3]] = params[param];
    		}
    	}
    }

    if (typeof(params[name]) != "undefined") {
    	return params[name];
    } else {
    	return '';
    }
}

function addQueryParam(name, value, url)
{
	var search = url.substring(url.indexOf('?') + 1);
	if (search != url) {
		return url + '&' + encodeURIComponent(name)+'=' + encodeURIComponent(value);
	} else {
		return url + '?' + encodeURIComponent(name) + '=' + encodeURIComponent(value);
	}
}

// Show bootstrap modal
function showModal(params)
{
	triggerModal(null, params);
}

// Show bootstrap modal from link
// Use showModal instead of this
function triggerModal(a, params)
{
    if (typeof(params) == "undefined") {
    	params = {};
    }

    if (typeof(params.options) == "undefined") {
    	params.options = {};
    }

    if (typeof(a) == "undefined" || !a) {
    	// Create dummy link
    	a = $(document.createElement('a'));
    }

    // Title
    var title = a.attr('data-modal-title');
    if (typeof(params.title) != "undefined") {
    	title = params.title;
    }
    if (title && title.charAt(0) == '#') {
        title = $(title).html();
    }
    if (!title) {
        title = a.text();
    }

    // Remote
    var remote = a.attr('data-remote');
    if (!remote) {
    	remote = a.attr('href');
    }
    if (typeof(params.remote) != "undefined") {
    	remote = params.remote;
    }

    var body = a.attr('data-modal-body');
    if (typeof(params.body) != "undefined") {
    	body = params.body;
    }
    var footer = a.attr('data-modal-footer');
    if (typeof(params.footer) != "undefined") {
    	footer = params.footer;
    }
    var loader = a.attr('data-modal-loader');
    if (typeof(params.loader) != "undefined") {
    	loader = params.loader;
    }
    if (!loader) {
    	loader = '/img/loader-grey.gif';
    }
    if (typeof(params.no_close_btn) == "undefined") {
    	params.no_close_btn = a.attr('data-no-close-btn');
    }
    if (typeof(params.no_footer) == "undefined") {
    	params.no_footer = a.attr('data-modal-no-footer');
    }
    if (typeof(params.no_header) == "undefined") {
    	params.no_header = a.attr('data-modal-no-header');
    }
    if (typeof(params.no_fade) == "undefined") {
    	params.no_fade = a.attr('data-modal-no-fade');
    }
    var modal_class = a.attr('data-modal-class');
    if (typeof(params.class) != "undefined") {
    	modal_class = params.class;
    }
    if (typeof(modal_class) == "undefined") {
    	modal_class = '';
    }
    var on_show = a.attr('data-modal-on-show');
    if (typeof(params.on_show) != "undefined") {
    	on_show = params.on_show;
    }
    // Size: lg or sm
    if (typeof(params.size) == "undefined") {
    	params.size = a.attr('data-modal-size');
    }
    if (typeof(params.width_auto) == "undefined") {
    	params.width_auto = a.attr('data-modal-width-auto');
    }
    // Fit modal body into the screen
    var fit = a.attr('data-modal-fit');

    var modal;

    if (params.size) {
    	modal_class += ' modal-'+params.size;
    }
    if (params.width_auto) {
    	modal_class += ' modal-width-auto';
    }
    if (typeof(modal_class) == "undefined") {
    	modal_class = '';
    }

    // Convert bool to string
    for (param in params) {
    	if (params[param] === true) {
    		params[param] = 'true';
    	} else if (params[param] === false) {
    		params[param] = 'false';
    	}
    }

    var html = [
    '<div class="modal '+(params.no_fade == 'true' ? '' : 'fade')+'" tabindex="-1" role="dialog" aria-labelledby="jsmodal-label" aria-modal="true">',
        '<div class="modal-dialog '+modal_class+'">',
            '<div class="modal-content">',
                '<div class="modal-header '+(params.no_header == 'true' ? 'hidden' : '')+'">',
                    '<button type="button" class="f-button f-button--ghost f-button--icon f-button--small modal-close" data-dismiss="modal" aria-label="'+Lang.get("messages.close")+'"><svg class="f-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg></button>',
                    '<h3 class="modal-title" id="jsmodal-label">'+htmlEscape(title)+'</h3>',
                '</div>',
                '<div class="modal-body '+(fit == 'true' ? 'modal-body-fit' : '')+'"><div class="text-center modal-loader"><img src="'+Vars.public_url+loader+'" width="31" height="31"/></div></div>',
                '<div class="modal-footer '+(params.no_footer == 'true' ? 'hidden' : '')+'">',
                    (params.no_close_btn == 'true' ? '': '<button type="button" class="f-button" data-dismiss="modal">'+Lang.get("messages.close")+'</button>'),
                    footer,
                '</div>',
            '</div>',
        '</div>',
    '</div>'].join('');
    modal = $(html);

    // if (typeof(onshow) !== "undefined") {
    //     modal.on('shown.bs.modal', onshow);
    // }

    modal.modal(params.options);

    modal.on('hidden.bs.modal', function () {
	    $(this).remove();
	});

    if (body) {
    	var body_html = $(body).html();
    	if (!body_html) {
    		body_html = $('<div>'+body+'</div>').html()
    	}
        modal.children().find(".modal-body").html(body_html);
        if (on_show) {
        	if (typeof(window[on_show]) == "function") {
        		window[on_show](modal);
        	} else if (typeof(on_show) == "function") {
        		on_show(modal, a);
        	}
        }
    } else {
        setTimeout(function(){
            $.ajax({
                url: remote,
                success: function(html) {
                    modal.children().find(".modal-body").html(html);

			        if (on_show) {
			        	if (typeof(window[on_show]) == "function") {
			        		window[on_show](modal, a);
			        	} else if (typeof(on_show) == "function") {
			        		on_show(modal, a);
			        	}
			        }
                },
                error: function(data) {
                    modal.children().find(".modal-body").html('<p class="alert alert-danger">'+Lang.get("messages.error_occurred")+'</p>');
                }
            });
        }, 500);
    }
}

// Show floating error message on ajax error
function showAjaxError(response, no_autohide)
{
	var msg = '';

	if (typeof(response.msg) != "undefined") {
		msg = response.msg;
	} else if (typeof(response.message) != "undefined") {
		// Standard Laravel error message is returned in [message]
		msg = response.message;
	}
	if (msg) {
		showFloatingAlert('error', response.msg, no_autohide);
	} else {
		showFloatingAlert('error', Lang.get("messages.error_occurred"), no_autohide);
	}
}

function initAfterSendModal(modal)
{
	$(document).ready(function() {
		modal.children().find(".after-send-save:first").click(function(e) {
			saveAfterSend(e.target);
		});
	});
}

// Save default redirect
function saveAfterSend(el)
{
	var button = $(el);
	button.button('loading');

	var value = $(el).parents('.modal-body:first').children().find('[name="after_send_default"]:first').val();

	var mailbox_id = getGlobalAttr('mailbox_id');
	if (!mailbox_id) {
		mailbox_id = $(el).parents('.modal-body:first').children().find('[name="default_redirect_mailbox_id"]:first').val()
	}
	data = {
		value: value,
		mailbox_id: mailbox_id,
		action: 'save_after_send'
	};

	fsAjax(data, laroute.route('conversations.ajax'), function(response) {
		if (typeof(response.status) != "undefined" && response.status == 'success') {
			// Show selected option in the dropdown
			$('input[name="after_send"]').val(value);
			showFloatingAlert('success', Lang.get("messages.settings_saved"));
			$('.modal').modal('hide');
		} else {
			showAjaxError(response);
		}
		button.button('reset');
	}, true);
}

function searchInit()
{
	$(document).ready(function() {
		// Open all links in new window
		//$(".conv-row a").attr('target', '_blank');
		$(".sidebar-menu .menu-link a").filter('[data-filter]').click(function(e){
			var trigger = $(this);
			var filter = trigger.attr('data-filter');
			if (!trigger.parent().hasClass('active')) {
				// Show
				$('#search-filters div[data-filter="'+filter+'"]:first').addClass('active')
					.find(':input:first').removeAttr('disabled');
				trigger.parent().addClass('active');
			} else {
				// Hide
				$('#search-filters div[data-filter="'+filter+'"]:first').removeClass('active')
					.find(':input:first').attr('disabled', 'disabled');
				trigger.parent().removeClass('active');
			}
			appScroller().animate({scrollTop: 0}, 600, 'swing');
			e.preventDefault();
		});

		$("#search-filters .remove").click(function(e){
			var container = $(this).parents('.form-group:first');
			var filter = container.attr('data-filter');
			// Hide
			$('#search-filters div[data-filter="'+filter+'"]:first').removeClass('active')
				.find(':input:first').attr('disabled', 'disabled');
			$('.sidebar-menu a[data-filter="'+filter+'"]:first').parent().removeClass('active');

			e.preventDefault();
		});

		initCustomerSelector($('#search-filter-customer'), {width: '100%'});

		// Dates
		$('#search-filters .input-date').flatpickr({allowInput: true});

		$('#search-filters .filter-multiple').select2({
			multiple: true,
			tags: true
			// Causes JS error on clear
			//allowClear: true
		});
	});
}

// Change customer modal
function changeCustomerInit()
{
	$(document).ready(function() {
		var input = $(".change-customer-input");
		initCustomerSelector(input, {
			dropdownParent: $('.modal-dialog:visible:first'),
			multiple: true,
			placeholder: input.attr('placeholder'),
			maximumSelectionLength: 1,
			ajax: {
				url: laroute.route('customers.ajax_search'),
				dataType: 'json',
				delay: 250,
				cache: true,
				data: function (params) {
					return {
						q: params.term,
						exclude_email: input.attr('data-customer_email'),
						search_by: 'all',
						page: params.page
						//use_id: true
					};
				}
			}
		});

		// Show confirmation dialog on customer select
		input.on('select2:selecting', function (e) {
			if (typeof(e.params) == "undefined" || typeof(e.params.args.data) == "undefined") {
				console.log(e);
				return;
			}
			var data = e.params.args.data;
			//el.select2('close');

			var confirm_html = '<div>'+
				'<div class="text-center">'+
				'<div class="text-larger margin-top-10">'+Lang.get("messages.confirm_change_customer", {customer_email: data.id})+'</div>'+
				'<div class="form-group margin-top">'+
        		'<button class="f-button f-button--primary change-customer-ok" data-customer_email='+data.id+'>OK</button>'+
        		'<button class="f-button f-button--ghost" data-dismiss="modal">'+Lang.get("messages.cancel")+'</button>'+
        		'</div>'+
        		'</div>'+
        		'</div>';

			triggerModal(null, {
				body: confirm_html,
				width_auto: 'true',
				no_header: 'true',
				no_footer: 'true',
				no_fade: 'true',
				size: 'sm',
				on_show: function(modal) {
					modal.children().find('.change-customer-ok:first').click(function(e) {
						conversationChangeCustomer($(this).attr('data-customer_email'));
					});
				}
			});
		    e.preventDefault();
		});

		$("#change-customer-create-trigger a:first").click(function(e){
			$('#change-customer-create').removeClass('hidden');
			$(this).hide();
			e.preventDefault();
		});

		$("#change-customer-create form:first").submit(function(e){
			e.preventDefault();
		});

		$("#change-customer-create button:first").click(function(e){
			var button = $(this);

			button.button('loading');

			var data = button.parents('form:first').serialize();
			data += '&action=create';

			fsAjax(data,
				laroute.route('customers.ajax'),
				function(response) {
					showAjaxResult(response);

					if (typeof(response.status) != "undefined" && response.status == 'success') {
						conversationChangeCustomer(response.email);
					}
					
					ajaxFinish();
				}
			);
		});
	});
}

function conversationChangeCustomer(email)
{
	fsAjax({
			action: 'conversation_change_customer',
			customer_email: email,
			conversation_id: getGlobalAttr('conversation_id')
		},
		laroute.route('conversations.ajax'),
		function(response) {
			if (typeof(response.status) != "undefined" && response.status == 'success') {
				if (typeof(response.redirect_url) != "undefined") {
					window.location.href = response.redirect_url;
				} else {
					window.location.href = '';
				}
			} else {
				showAjaxError(response);
				loaderHide();
			}
		}
	);
}

// Move conversation modal
function initMoveConv()
{
	$(document).ready(function() {
		$(".btn-move-conv:visible:first").click(function(e){
			var button = $(this);

			button.button('loading');

			fsAjax({
					action: 'conversation_move',
					mailbox_id: $('.move-conv-mailbox-id:visible:first').val(),
					mailbox_email: $('.move-conv-mailbox-email:visible:first').val(),
					conversation_id: getGlobalAttr('conversation_id'),
					folder_id: getQueryParam('folder_id')
				},
				laroute.route('conversations.ajax'),
				function(response) {
					showAjaxResult(response);
					if (isAjaxSuccess(response)) {
						if (typeof(response.redirect_url) != "undefined" && response.redirect_url) {
							window.location.href = response.redirect_url;
						} else {
							window.location.href = '';
						}
					}
					ajaxFinish();
				}
			);
		});

		$(".move-conv-mailbox-email:first").on('keyup keypress', function(e){
			if ($(this).val()) {
				$(".move-conv-mailbox-id:first").attr('disabled', 'disabled');
			} else {
				$(".move-conv-mailbox-id:first").removeAttr('disabled');
			}
		});
	});
}

// Move conversation modal
function initMergeConv()
{
	$(document).ready(function() {
		initTooltips();

		initMergeConvSelect();

		$(".btn-merge-conv:visible:first").click(function(e){
			var button = $(this);

			button.button('loading');

			var conv_ids = [];
			$('.conv-merge-selected:visible:first .conv-merge-id:checked').each(function() {
				conv_ids.push($(this).val());
			});

			if (!conv_ids.length) {
				return;
			}

			fsAjax({
					action: 'conversation_merge',
					merge_conversation_id: conv_ids,
					conversation_id: getGlobalAttr('conversation_id')
				},
				laroute.route('conversations.ajax'),
				function(response) {
					showAjaxResult(response);
					if (isAjaxSuccess(response)) {
						window.location.href = '';
					}
					ajaxFinish();
				}
			);

			e.preventDefault();
		});

		$(".btn-merge-search:visible:first").click(function(e){
			var button = $(this);

			button.button('loading');

			fsAjax({
					action: 'merge_search',
					number: $('.merge-conv-number:visible:first').val(),
					cur_conv_id: getGlobalAttr('conversation_id')
				},
				laroute.route('conversations.ajax'),
				function(response) {
					showAjaxResult(response);
					if (isAjaxSuccess(response) && response.html) {
						$('.conv-merge-search-result:first td:first').html(response.html);
						$('.conv-merge-search-result:first').removeClass('hidden');
						initTooltips();
						initMergeConvSelect();
					} else {
						if ($('.conv-merge-search-result:first .conv-merge-id').is(':checked')) {
							$('.btn-merge-conv:visible:first').attr('disabled', 'disabled');
						}
						$('.conv-merge-search-result:first td:first').html(response.html);
						$('.conv-merge-search-result:first').addClass('hidden');
					}
					ajaxFinish();
				}
			);
		});
	});
}

function initMergeConvSelect()
{
	$('.conv-merge-id').click(function() {
		$('.btn-merge-conv:visible:first').removeAttr('disabled');

		var checkbox_container = $(this).parent();
		var selected_list = $('.conv-merge-selected:visible:first');
		var clicked_conv_id = parseInt($(this).val());

		// Do not add same conversation twice
		if (!isNaN(clicked_conv_id) && !selected_list.children().find('.conv-merge-id[value="'+parseInt($(this).val())+'"]').length) {

			var html = '<div class="f-alert conv-merge-selected-item">'
				+checkbox_container[0].outerHTML
				+'</div>';

			selected_list.append(html);

			// Remove conv from selected list
			selected_list.children().find('.conv-merge-id:last').attr('checked', 'checked').click(function(e){
				$('.conv-merge-list:visible:first').children()
					.find('.conv-merge-id[value="'+parseInt($(this).val())+'"]:first')
					.parents('tr:first').show();
				$(this).parent().parent().remove();

				if (!selected_list.children().find('.conv-merge-id').length) {
					$('.btn-merge-conv:visible:first').attr('disabled', 'disabled');
				}
			});
		}

		if ($(this).hasClass('conv-merge-searched')) {
			$('.conv-merge-search-result:first').addClass('hidden');
		} else {
			checkbox_container.parents('tr:first').hide();
		}

		$(this).prop("checked", false);
	});
}

// Check if ajax request was successfull
function isAjaxSuccess(response)
{
	if (typeof(response.status) != "undefined" && response.status == 'success') {
		return true;
	} else {
		return false;
	}
}

// Initialize customer select2
function initCustomerSelector(input, custom_options)
{
	var use_id = true;

	if (typeof(custom_options.use_id) != "undefined") {
		use_id = custom_options.use_id;
		if (!use_id) {
			use_id = null;
		}
	}

	var search_by = 'all';
	if (typeof(custom_options.search_by) != "undefined") {
		search_by = custom_options.search_by;
	}

	var show_fields = 'all';
	if (typeof(custom_options.show_fields) != "undefined") {
		show_fields = custom_options.show_fields;
	}

	var allow_non_emails = null;
	if (typeof(custom_options.allow_non_emails) != "undefined") {
		allow_non_emails = true;
	}

	var options = {
		ajax: {
			url: laroute.route('customers.ajax_search'),
			dataType: 'json',
			delay: 250,
			cache: true,
			data: function (params) {
				return {
					q: params.term,
					exclude_email: input.attr('data-customer_email'),
					use_id: use_id,
					search_by: search_by,
					show_fields: show_fields,
					allow_non_emails: allow_non_emails,
					page: params.page
				};
			}/*,
			beforeSend: function(){
		    	showSelect2Loader(input);
		    },
		    complete: function(){
		    	hideSelect2Loader(input);
		    }*/
		},
		containerCssClass: "select2-multi-container", // select2-with-loader
 		dropdownCssClass: "select2-multi-dropdown",
		minimumInputLength: 2
	};
	// When placeholder is set on invisible input, it breaks input
	// todo: fix this
	if (input.length == 1 && input.is(':visible')) {
		options.placeholder = input.attr('placeholder');
	}
	if (typeof(custom_options.editable) != "undefined" && custom_options.editable) {
		var token_separators = [",", ", ", " "];
		if (typeof(custom_options.maximumSelectionLength) != "undefined" && custom_options.maximumSelectionLength == 1) {
			token_separators = [];
		}
		$.extend(options, {
			multiple: true,
			tags: true,
			tokenSeparators: token_separators,
			createTag: function (params) {
				// Don't allow to create a tag if there is no @ symbol
				if (typeof(custom_options.allow_non_emails) == "undefined") {
				    if (!/^.+@.+$/.test(params.term)) {
						// Return null to disable tag creation
						return null;
				    }
				}
			    // Check if select already has such option
			    var data = this.select2('data');
			    for (i in data) {
			    	if (data[i].id == params.term) {
			    		return null;
			    	}
			    }
			    return {
					id: params.term,
					text: params.term,
					newOption: true
			    }
			}.bind(input),
			templateResult: function (data) {
			    var $result = $("<span></span>");

			    $result.text(data.text);

			    if (data.newOption) {
			     	$result.append(" <em>("+Lang.get("messages.add_lower")+")</em>");
			    }

			    return $result;
			}
		});
	}
	if (typeof(custom_options) != 'undefined') {
		$.extend(options, custom_options);
	}

	return input.select2(options);
}

// Show confirmation dialog
function showModalConfirm(text, ok_class, options, ok_text)
{
	if (typeof(ok_text) == "undefined") {
		ok_text = 'OK';
	}
	var confirm_html = '<div>'+
		'<div class="text-center">'+
		'<div class="text-larger margin-top-10">'+text+'</div>'+
		'<div class="form-group margin-top">'+
		'<button class="f-button f-button--primary '+ok_class+'">'+ok_text+'</button>'+
		'<button class="f-button f-button--ghost" data-dismiss="modal">'+Lang.get("messages.cancel")+'</button>'+
		'</div>'+
		'</div>'+
		'</div>';

	showModalDialog(confirm_html, options);
}

// Show modal dialog
function showModalDialog(body, options)
{
	var standard_options = {
		body: body,
		width_auto: 'true',
		no_header: 'true',
		no_footer: 'true',
		no_fade: 'true'
		//size: 'sm'
	};
	if (typeof(options) == "undefined") {
		options = {};
	}
	options = Object.assign(standard_options, options);

	triggerModal(null, options);
}

/*function showSelect2Loader(input)
{
	input.closest('.select2-with-loader:first').addClass('loading');
}

function hideSelect2Loader(input)
{
	input.closest('.select2-with-loader:first').removeClass('loading');
}*/

function showAjaxResult(response)
{
	loaderHide();

	if (typeof(response.status) != "undefined" && response.status == 'success') {
		if (typeof(response['msg_success']) != "undefined") {
			showFloatingAlert('success', response['msg_success']);
		}
	} else {
		showAjaxError(response);
	}
}

function getCsrfToken()
{
	return $('meta[name="csrf-token"]:first').attr('content');
}

// Real-time website notifications in the menu and browser push notifications
function polycastInit()
{
	var auth_user_id = getGlobalAttr('auth_user_id');
	if (!auth_user_id) {
		return;
	}

	if (getGlobalAttr('conversation_id')) {
		poly_data_closures.push(function(data) {
			if (getReplyFormMode() == 'reply') {
				data.replying = 1;
			} else {
				data.replying = 0;
			}
			if (!fs_prev_focus && document.hasFocus()) {
				data.conversation_view_focus = 1;
			}
			fs_prev_focus = document.hasFocus();
			// If conversation_id is passed it means that user is viewing the converation
			if (document.hasFocus() || data.replying) {
				data.conversation_id = getGlobalAttr('conversation_id');
			}
			return data;
		});
	}

	// create the connection
    poly = new Polycast(Vars.public_url+'/polycast', {
        token: getCsrfToken(),
        data: poly_data_closures
    });

    // register callbacks for connection events
    // poly.on('connect', function(obj){
    //     console.log('connect event fired!');
    //     console.log(obj);
    // });

    // poly.on('disconnect', function(obj){
    //     console.log('disconnect event fired!');
    //     console.log(obj);
    // });

    // subscribe to channel(s)
    var channel = poly.subscribe('private-App.User.'+auth_user_id);

    // fired when event on channel is received
    channel.on('App\\Events\\RealtimeBroadcastNotificationCreated', function(data, event){
        /*
            event.id = mysql id
            event.channels = array of channels
            event.event = event name
            event.payload = object containing event data (same as the first data argument)
            event.created_at = timestamp from mysql
            event.requested_at = when the ajax request was performed
            event.delay = the delay in seconds from when the request was made and when the event happened (used internally to delay callbacks)
        */
        // console.log(data);
        // console.log(event);

        if (typeof(event.data) != "undefined") {
        	// Show notification in the menu
        	if (typeof(event.data.web) != "undefined"
	        	&& typeof(event.data.web.html) != "undefined"
	        	&& event.data.web.html
	        ) {
	        	showMenuNotification(event.data.web.html);
	        }

	        // Browser push-notification
	        if (typeof(event.data.browser) != "undefined"
	        	&& typeof(event.data.browser.text) != "undefined"
	        	&& event.data.browser.text
	        ) {
	        	showBrowserNotification(event.data.browser.text, event.data.browser.url);
	        }

			// Play audio notification for chat conversations.
	        playAudioNotification(event.data);
	    }
    });

	var channel = poly.subscribe('conv');

	// Show who is viewing a conversation or replying.
    channel.on('App\\Events\\RealtimeConvView', function(data, event){

        if (!data
        	|| data.conversation_id != getGlobalAttr('conversation_id')
        	// Skip own notifications
        	|| data.user_id == getGlobalAttr('auth_user_id')
        ) {
        	return;
	    }

	    // Show user avatar in conversation
	    if (data.replying) {
	    	title = Lang.get("messages.user_replying", {'user': data.user_name});
	    } else {
	    	title = Lang.get("messages.user_viewing", {'user': data.user_name});
	    }
	    var item = $('#conv-viewers .viewer-'+data.user_id+':first');
	    var sorting_required = false;
	    var change_title = false;
	    var is_new = false;
	    if (item.length) {
		    // Existing
		    if (!item.is(':visible')) {
		    	item.fadeIn();
		    }
		    // Add/remove replying class if needed
		    if (data.replying && !item.hasClass('viewer-replying')) {
				item.addClass('viewer-replying');
				change_title = true;
				sorting_required = true;
			}
			if (!data.replying && item.hasClass('viewer-replying')) {
				item.removeClass('viewer-replying');
				change_title = true;
				sorting_required = true;
			}
		} else {
			// New
			var item_class = 'viewer-'+data.user_id;
			if (data.replying) {
				item_class += ' viewer-replying';
				sorting_required = true;
			}
		    var html = personPhotoHtml(data.user_initials, data.user_photo_url, 'data-toggle="tooltip" title="'+title+'" style="display:none"', item_class, 'xs');
		    $('#conv-viewers').prepend(html);
		    is_new = true;
		}
		// Move replying viewers to the left
		if (change_title) {
			// Change tooltip
			item.attr('data-original-title', title);
			//initTooltip('#conv-viewers .viewer-'+data.user_id+':first');
		}
		if (sorting_required) {
			var html_replying = '';
			var html_other = '';
			$('#conv-viewers > span').each(function(i, el) {
				if ($(el).hasClass('viewer-replying')) {
					html_replying += el.outerHTML;
				} else {
					html_other += el.outerHTML;
				}
			});
			$('#conv-viewers').html(html_replying+html_other);
			initTooltip('#conv-viewers > span');
		}
		if (is_new) {
			$('#conv-viewers > span').fadeIn();
		    initTooltip('#conv-viewers .viewer-'+data.user_id+':first');
		}
    });

	// User finished viewing conversation.
    channel.on('App\\Events\\RealtimeConvViewFinish', function(data, event) {
        if (!data
        	|| data.conversation_id != getGlobalAttr('conversation_id')
        	// Skip own notifications
        	|| data.user_id == getGlobalAttr('auth_user_id')
        ) {
        	return;
	    }

	    // Remove user avatar
	    $('#conv-viewers .viewer-'+data.user_id+':first').fadeOut();
    });

    // Show new conversation threads
    var conversation_id = getGlobalAttr('conversation_id');
    if (conversation_id) {
	    var channel = poly.subscribe('conv.'+conversation_id);

	    channel.on('App\\Events\\RealtimeConvNewThread', function(data, event){
	        if (!data
	        	|| typeof(data.conversation_id) == "undefined"
	        	|| data.conversation_id != getGlobalAttr('conversation_id')
	        	// Skip own notifications
	        	|| data.user_id == getGlobalAttr('auth_user_id')
	        ) {
	        	return;
		    }

		    // A new message: the thread list shows it (App\Livewire\ConversationThread).
		    if (typeof(data.thread_html) != "undefined" && data.thread_html && !document.getElementById('thread-'+data.thread_id)) {
		    	Livewire.dispatch('conversation-thread-created');
		    	$.titleAlert('✉ '+Lang.get("messages.new_message"), {
		    		requireBlur: true,
		    		stopOnFocus: true,
		    		interval: 600
		    	});
		    }

		    // Update assignee if needed
		    if (typeof(data.conversation_user_id) != "undefined" && data.conversation_user_id 
		    	&& parseInt(data.conversation_user_id) != convGetUserId()
		    ) {
		    	$('#conv-assignee [data-user_id].active').removeClass('active').removeAttr('aria-current');
		    	var a = $("#conv-assignee [data-user_id='"+data.conversation_user_id+"']");
		    	a.addClass('active').attr('aria-current', 'true');
		    	$('#conv-assignee .conv-info-val span:first').text(a.text());
		    	flashElement($('#conv-assignee'));
		    }

		    // Update status if needed
		    if (typeof(data.conversation_status) != "undefined" && data.conversation_status 
		    	&& parseInt(data.conversation_status) != convGetStatus()
		    ) {
		    	$('#conv-status [data-status].active').removeClass('active').removeAttr('aria-current');
		    	var a = $("#conv-status [data-status='"+data.conversation_status+"']");
		    	a.addClass('active').attr('aria-current', 'true');
		    	$('#conv-status .conv-info-val span:first').text(a.text());
		    	// Update the status colour
		    	if (data.conversation_status_class) {
		    		var tones = {success: 'success', info: 'accent', warning: 'warning', danger: 'danger'};
		    		$('#conv-status .conv-status-dot').attr('class', 'f-badge conv-status-dot f-badge--'+(tones[data.conversation_status_class] || 'neutral'));
		    	}
		    	flashElement($('#conv-status'));
		    }

			// Play audio notification for chat conversations.
	        playAudioNotification(data);
	    });
	}

	// New messages: the sidebar's folders (of every mailbox) and the conversations list.
    var mailbox_id = getGlobalAttr('mailbox_id');
    if (!isChatMode()) {
    	$('.app-sidebar__folders[data-mailbox_id]').each(function() {
    		var folders_mailbox_id = $(this).attr('data-mailbox_id');
    		poly.subscribe('mailbox.'+folders_mailbox_id).on('App\\Events\\RealtimeMailboxNewThread', function(data, event) {
    			if (!data || typeof(data.mailbox_id) == "undefined" || data.mailbox_id != folders_mailbox_id) {
    				return;
    			}
    			// Looked up now: wire:navigate may have replaced the page since.
    			var folders = $('.app-sidebar__folders[data-mailbox_id="'+folders_mailbox_id+'"]:first');
    			if (typeof(data.folders_html) != "undefined" && data.folders_html) {
    				folders.html(data.folders_html);
    				// The open folder's number of active conversations in the page title.
    				var current = folders.children('[aria-current="page"]:first');
    				if (current.length && !getGlobalAttr('conversation_id')) {
    					var new_count = parseInt(current.attr('data-active-count'));
    					new_count = (!isNaN(new_count) && new_count > 0) ? '('+new_count+') ' : '';
    					document.title = new_count+document.title.replace(/^\(\d+\) /, "");
    				}
    			}
    			// The list of this mailbox, or of All Mailboxes.
    			var list_mailbox_id = $(".table-conversations:first").attr('data-mailbox_id') || getGlobalAttr('mailbox_id');
    			if ((list_mailbox_id == folders_mailbox_id || parseInt(list_mailbox_id) < 0)
    				&& $(".table-conversations:first").length && !getSelectedConversations().length
    			) {
    				Livewire.dispatch('conversations-changed');
    			}
    			// Play audio notification for chat conversations.
    			playAudioNotification(data);
    		});
    	});
    }

	// Refresh chats list and also play audio notificaion
    var chats = $('#folders.chats:first');
    if (mailbox_id && chats.length) {
	    var channel = poly.subscribe('chat.'+mailbox_id);

	    channel.on('App\\Events\\RealtimeChat', function(data, event) {
	        if (!data) {
				return;
		    }

			// Play audio notification for chat conversations.
	        playAudioNotification(data.audio.thread_id);

	        if (typeof(data.mailbox_id) == "undefined" || data.mailbox_id != mailbox_id) {
	        	return;
		    }

		    // Show chats
		    if (typeof(data.chats_html) != "undefined" && data.chats_html) {
		    	var chat_id = chats.children('li.active:first').attr('data-chat_id');
		    	var header_html = chats.children('li:first').prop('outerHTML');

		    	chats.html(header_html+data.chats_html);
		    	
		    	var active_chat = chats.children('li[data-chat_id="'+chat_id+'"]');
		    	active_chat.addClass('active');

		    	initChats();
		    }
	    });

	    initChats();
	}

    // at any point you can disconnect
    //poly.disconnect();

    // and when you disconnect, you can again at any point reconnect
    //poly.reconnect();
}

function playAudioNotification(data)
{
	var thread_id = '';

	if (typeof(data.audio) != "undefined"
		&& typeof(data.audio.thread_id) != "undefined" && data.audio.thread_id
    ) {
		thread_id = data.audio.thread_id+'';
    } else {
		return;
    }

	var save = false;
	var now = Math.floor((new Date()).getTime() / 1000);

	// Used to prevent playing same audio notifications on multiple tabs.
	var audio_notifications = localStorageGetObject('audio_notifications');

	if (!audio_notifications) {
		audio_notifications = {};
	}

	if (!(thread_id in audio_notifications)) {
		audio_notifications[thread_id] = now;
		playAudio();
		save = true;
	}

	// Remove expired noitifications from storage.
	for (thread_i in audio_notifications) {
		if (parseInt(audio_notifications[thread_i]) < (now - 3600)) {
			delete audio_notifications[thread_i];
			save = true;
		}
	}
	if (save) {
		localStorageSetObject('audio_notifications', audio_notifications);
	}
}

function playAudio()
{
	// Create the audio object
    audio_chat = new Audio(Vars.public_url+'/audio/chat.mp3');
    audio_chat.preload = 'auto';
    audio_chat.currentTime = 0;

    audio_chat.play().catch(function (err) {
        if (err.name === 'NotAllowedError' && !autoplay_msg_shown) {
			if (isModalOpen()) {
				return;
			}
			autoplay_msg_shown = true;
			showModalConfirm(Lang.get("messages.autoplay"), 'autoplay-confirm', {
				size: 'md',
				on_show: function(modal) {
					modal.children().find('.autoplay-confirm:first').click(function(e) {
						modal.modal('hide');
						audio_chat.play()
					});
				}
			});
        }
    });
}

function initChats()
{
	$('.chats-load-more').click(function(e) {
		var button = $(this);

		button.button('loading');

		fsAjax(
			{
				action: 'chats_load_more',
				mailbox_id: getGlobalAttr('mailbox_id'),
				offset: $('#folders .chat-item').length - 1,
			},
			laroute.route('conversations.ajax'),
			function(response) {
				if (isAjaxSuccess(response)) {
					button.parent().before(response.html);
					button.parent().remove();
					initChats();
				} else {
					showAjaxResult(response);
				}
				button.button('reset');
			}, true
		);

		e.preventDefault();
		e.stopPropagation();
	});
}

function convIsChat()
{
	return $('#conv-layout').hasClass('conv-type-chat');
}

function convGetUserId()
{
	return parseInt($('#conv-assignee [data-user_id].active:first').attr('data-user_id'));
}

function convGetStatus()
{
	return parseInt($('#conv-status [data-status].active:first').attr('data-status'));
}

function flashElement(el)
{
	el.fadeOut(0).fadeIn(2000);
}

// Show notification in the menu
function showMenuNotification(html)
{
	$(html).prependTo($(".web-notifications-list:first"));

	var counter = $('.web-notifications-count:first');
	if (counter) {
		var count = parseInt($('.web-notifications-count:first').text());
		if (isNaN(count)) {
			count = 0;
		}
		count++;
		counter.text(count).removeClass('hidden');
	}

	$('.web-notifications-mark-read:first').removeClass('hidden');

	// Remove double TODAY
	var first_date = $('.web-notification-date:first');
	$('.web-notification-date[data-date="'+first_date.attr('data-date')+'"]:gt(0)').remove();
	$('.web-notifications-trigger:first').addClass('has-unread');
}

// Show browser push-notification
function showBrowserNotification(text, url)
{
	// Push notification body limits: https://www.mobify.com/insights/web-push-character-limits/
	// If we place text into the body and keep title empty, it is being cropped in Chrome
	Push.create(text, {
	    body: "",
	    icon: Vars.public_url+'/img/logo-icon-white-300.png',
	    tag: url,
	    timeout: 5000,
	    //requireInteraction: true
	    onClick: function () {
	    	if (url) {
	        	var win = window.open(url, '_blank');
  				win.focus();
  				this.close();
  				//Push.close(url);
	        }
	    }
	});
}

// Display notification in the menu
function webNotificationsInit()
{
	// Load more
	var button = $('.web-notification-more:first .btn:first');

	button.click(function(e) {
		button.button('loading');

		var wn_page = parseInt(button.attr('data-wn_page'));
		if (isNaN(wn_page)) {
			wn_page = 2;
		}

		fsAjax(
			{
				action: 'web_notifications',
				wn_page: wn_page
			},
			laroute.route('users.ajax'),
			function(response) {
				if (isAjaxSuccess(response)) {
					$(response.html).insertBefore(button.parent());
				} else {
					showAjaxError(response);
				}
				if (typeof(response.has_more_pages) == "undefined" || !response.has_more_pages) {
					button.parent().hide();
				}
				button.button('reset');
				button.attr('data-wn_page', wn_page+1);
			},
			true
		);
		e.preventDefault();
		e.stopPropagation();
	});

	// Mark all as read
	$('.web-notifications-mark-read:first').click(function(e) {
		var mark_button = $(this);

		if (mark_button.hasClass('disabled')) {
			return;
		}

		mark_button.button('loading');

		fsAjax(
			{
				action: 'mark_notifications_as_read'
			},
			laroute.route('users.ajax'),
			function(response) {
				if (isAjaxSuccess(response)) {
					mark_button.remove();
					$('.web-notifications-count:first').addClass('hidden');
					$('.web-notification.is-unread').removeClass('is-unread');
					$('.web-notifications-trigger.has-unread:first').removeClass('has-unread');
				} else {
					showAjaxError(response);
				}
				mark_button.button('reset');
			},
			true
		);
		e.preventDefault();
		e.stopPropagation();
	});

	// Mark notification as read on click
	// $('.web-notifications:first .web-notification a').click(function(e) {
	// 	$(this).parent().removeClass('is-unread');
	// });

}

// Finishe ajax request by hiding loader, etc.
function ajaxFinish()
{
	loaderHide();
	// Buttons
	$(".btn[data-loading-text!='']:disabled").button('reset');
	// Links
	$(".btn.disabled[data-loading-text!='']").button('reset');
}

// Called from polycast
function maybeShowConnectionError()
{
	fs_connection_errors++;
	if (fs_connection_errors == 3) {
		showFloatingAlert('error', Lang.get("messages.lost_connection"));
	}
}

function maybeShowConnectionRestored()
{
	if (fs_connection_errors >= 3) {
		showFloatingAlert('success', Lang.get("messages.connection_restored"));
	}
	fs_connection_errors = 0;
}

function isNewConversation()
{
	if ($('#conv-layout-main .thread:first').length == 0) {
		return true;
	} else {
		return false;
	}
}

/**
 * Save draft automatically, on reply change or on click.
 * Validation is not needed.
 */
function saveDraft(reload_page, no_loader, do_not_save_empty)
{
	if (!reload_page && fs_processing_save_draft) {
		return;
	}
	// User clicked Send Reply button
	if (fs_processing_send_reply) {
		return;
	}

	fs_processing_save_draft = true;

	// Do not autosave draft if reply form has been closed
	var form = $(".form-reply:visible:first");
	if (!form || !form.length) {
		finishSaveDraft();
		return;
	}

	// Do not auto-save draft is there is no thread_id, body and attachments.
	if (typeof(do_not_save_empty) != "undefined") {
		if (!$('.form-reply:visible:first :input[name="thread_id"]:first').val() 
			&& !$('#body').val()
			&& !$('.form-reply:visible:first .thread-attachments li.attachment-loaded:first').length
			&& !$('#to').val()
		) {
			fs_processing_save_draft = false;
			return;
		}
	}

	var button = form.find('.note-btn-save-draft:first');
	// Are we saving a draft of a new conversation
	var new_conversation = isNewConversation();

	if (typeof(no_loader) == "undefined") {
		no_loader = false;
	}

	// Do not save unchanged draft
	// When replying click on Save draft always reloads conversation
	if ((new_conversation || !reload_page) && !fs_reply_changed) {
		fs_processing_save_draft = false;
		return;
	}

	// Make save draft button green when user clicks on it.
	if (reload_page) {
		button.addClass('text-success');
	}

	data = form.serialize();
	data += '&action=save_draft';

	fsAjax(data, laroute.route('conversations.ajax'), function(response) {
		if (typeof(response.status) != "undefined" && response.status == 'success') {
			if (reload_page && !new_conversation) {
				// Reload the conversation
				window.location.href = '';
			} else {
				button.addClass('text-success');
				fs_reply_changed = false;
				// Show Saved
				var saved_text = form.find('.draft-saved:first');
				if (!saved_text.length || !saved_text.is(':visible')) {
					if (!saved_text.length) {
						saved_text = $('<span class="draft-saved">'+Lang.get("messages.saved")+'</span>');
						saved_text.insertBefore(button);
					} else {
						saved_text.show();
					}

					setTimeout(function() {
						saved_text.fadeOut(1000);
				    }, 4000);
				}
				// If conversation returned, set conversation info
				if (typeof(response.conversation_id) != "undefined" && response.conversation_id) {
					form.children(':input[name="conversation_id"][value=""]').val(response.conversation_id);
					form.children(':input[name="thread_id"]').val(response.thread_id);
					form.children(':input[name="customer_id"]').val(response.customer_id);
					$('.conv-new-number:first').text(response.number);
					$('body:first').attr('data-conversation_id', response.conversation_id);

					// Set URL if this is a new conversation
					if (new_conversation) {
						setUrl(laroute.route('conversations.view', {id: response.conversation_id}));
					}
				}
			}
		} else {
			showAjaxError(response);
		}
		loaderHide();
		finishSaveDraft();
	},
	no_loader,
	function() {
		showFloatingAlert('error', Lang.get("messages.ajax_error"));
		loaderHide();
		finishSaveDraft();
	});
}

// If draft is being sent and user clicks Send reply,
// we need to wait and send reply after draft has been saved.
function finishSaveDraft()
{
	fs_processing_save_draft = false;
	if (fs_send_reply_after_draft) {
		fs_processing_send_reply = false;
		$(".btn-reply-submit:first").button('reset').click();
	}
}

function setUrl(url)
{
	if (window.history && typeof(window.history.replaceState) != "undefined") {
		try {
			// Catch an error if by some reason current protocol and protocol in url are different
        	window.history.replaceState({isMine:true}, 'title', url);
        } catch (e) {
        	// Do nothing
        }
    }
}

function goBack()
{
	window.history.go(-1);
}

// Show forward conversation form
function forwardConversation(e)
{
	var reply_block = $(".conv-reply-block");

	// We don't allow to switch, as it creates multiple drafts
	if (!reply_block.hasClass('hidden')) {
		return false;
	}

	prepareReplyForm();
	showReplyForm();
	showForwardForm({}, reply_block);

	// Load attachments
	loadAttachments(true);
}

// Load attachments for the draft of a new conversation or draft of the forward
function loadAttachments(is_forwarding)
{
	var attachments_container = $(".attachments-upload:first");
	var conversation_id = getGlobalAttr('conversation_id');

	if (typeof(is_forwarding) == "undefined") {
		is_forwarding = false;
	}

	if (!attachments_container.hasClass('forward-attachments-loaded') && conversation_id) {
		fsAjax({
				action: 'load_attachments',
				conversation_id: conversation_id,
				is_forwarding: is_forwarding
			},
			laroute.route('conversations.ajax'),
			function(response) {
				if (typeof(response.status) != "undefined" && response.status == 'success'
					&& typeof(response.data) != "undefined"
				) {
					attachments_container.addClass('forward-attachments-loaded');
					showAttachments(response.data);
					// Auto save draft to avoid multiplying attachments
					if (is_forwarding) {
						saveDraft(false, true);
					}
				} else {
					// Do nothing
					//showAjaxError(response);
				}
			}, true
		);
	}
}

// Turn reply form into forward form.
function showForwardForm(data, reply_block)
{
	if (typeof(reply_block) == "undefined" || !reply_block) {
		reply_block = $(".conv-reply-block:first");
	}
	reply_block.children().find(":input[name='subtype']:first").val(Vars.subtype_forward);
	reply_block.children().find(":input[name='to']:first").addClass('hidden');
	reply_block.children().find("#cc").val('').trigger('change');
	reply_block.children().find("#bcc").val('').trigger('change');
	reply_block.children().find(":input[name='to_email[]']:first").removeClass('hidden').removeClass('parsley-exclude').next('.select2:first').show();
	reply_block.addClass('inactive');
	reply_block.addClass('conv-forward-block');
	$(".conv-actions .conv-reply:first").addClass('inactive');

	if (data && typeof(data.to) != "undefined") {
		addSelect2Option($("#to_email"), {
			id: data.to, text: data.to
		});
	} else {
		$("#to_email").children('option:first').removeAttr('selected');
	}

	// Show recipient selector
	initRecipientSelector({
		//maximumSelectionLength: 1
	}, $('#to_email:not(.select2-hidden-accessible)'));
}

// Edit draft
function editDraft(button)
{
	var thread_container = button.parents('.thread:first');

	fsAjax({
			action: 'load_draft',
			thread_id: thread_container.attr('data-thread_id')
		},
		laroute.route('conversations.ajax'),
		function(response) {
			loaderHide();
			if (typeof(response.status) != "undefined" && response.status == 'success') {
				//response.data.is_note = '';
				showReplyForm(response.data, -50);
				if (response.data.is_forward == '1') {
					showForwardForm(response.data);
				}
				// Show all drafts
				$('.thread.thread-type-draft').show();
				// Hide current draft
				thread_container.hide();
				//$("html, body").animate({ scrollTop: $('.navbar:first').height() }, "slow");
			} else {
				showAjaxError(response);
			}
		}
	);
}

// Discards:
// - draft of an old reply
// - current reply
// - current note
//
// If thread_id is passed, it means we are discarding an old reply draft
function discardDraft(thread_id)
{
	var confirm_html = '<div>'+
		'<div class="text-center">'+
		'<div class="text-larger margin-top-10">'+Lang.get("messages.confirm_discard_draft")+'</div>'+
		'<div class="form-group margin-top">'+
		'<button class="f-button f-button--primary discard-draft-confirm">'+Lang.get("messages.yes")+'</button>'+
		'<button class="f-button f-button--ghost" data-dismiss="modal">'+Lang.get("messages.cancel")+'</button>'+
		'</div>'+
		'</div>'+
		'</div>';

	// Discard note
	if (typeof(thread_id) == "undefined" && isNote() && !isNewConversation()) {
		showModalDialog(confirm_html, {
			on_show: function(modal) {
				modal.children().find('.discard-draft-confirm:first').click(function(e) {
					hideReplyEditor();
					setReplyBody('');
					forgetNote();
					modal.modal('hide');
					$('#conv-subject').removeClass('action-visible');
				});
			}
		});
		return;
	}

	if (typeof(thread_id) == "undefined" || !thread_id) {
		thread_id = $('.form-reply :input[name="thread_id"]').val();
	}

	// We are creating a conversation from thread
	var from_thread_id = '';
	if (!thread_id && getQueryParam('from_thread_id')) {
		from_thread_id = getQueryParam('from_thread_id');
	}

	showModalDialog(confirm_html, {
		on_show: function(modal) {
			modal.children().find('.discard-draft-confirm:first').click(function(e) {
				fsAjax(
					{
						action: 'discard_draft',
						thread_id: thread_id,
						from_thread_id: from_thread_id
					},
					laroute.route('conversations.ajax'),
					function(response) {
						modal.modal('hide');
						if (isAjaxSuccess(response)) {
							if (typeof(response.redirect_url) != "undefined" && response.redirect_url) {
								window.location.href = response.redirect_url;
								return;
							}
							var thread_container = $('#thread-'+thread_id+':visible');
							if (thread_container.length) {
								// Remove draft from conversation
								thread_container.remove();
							} else {
								// Hide editor
								hideReplyEditor();
								$("#to").val(
									$("#to option:first").val()
								);
								$(".conv-reply-block :input[name='cc']:first").val('');
								$(".conv-reply-block :input[name='bcc']:first").val('');
								setReplyBody('');
								$('#conv-subject').removeClass('action-visible');
							}
						} else {
							showAjaxError(response);
						}
						loaderHide();
					}
				);
			});
		}
	});
}

function hideReplyEditor()
{
	$(".conv-action-block").addClass('hidden');
	$(".conv-action").removeClass('inactive');
}

function hideActionBlocks()
{
	$(".conv-action-block").addClass('hidden');
	$("#conv-subject").removeClass('action-visible');
}

function getReplyBody()
{
	return $("#body").val();
}

// FruitUI's editor: replace the content, insert at the cursor, focus.
function editorSetContent(id, html)
{
	window.dispatchEvent(new CustomEvent('fruit-editor-set', {detail: {target: String(id).replace('#', ''), html: html}}));
}

function editorInsert(id, html)
{
	window.dispatchEvent(new CustomEvent('fruit-editor-insert', {detail: {target: String(id).replace('#', ''), html: html}}));
}

function editorFocus(id)
{
	var textarea = document.getElementById(String(id).replace('#', ''));
	var editor = textarea ? textarea.closest('.f-editor') : null;
	var surface = editor ? editor.querySelector('.f-editor__surface [contenteditable="true"]') : null;
	if (surface) {
		surface.focus();
	} else if (textarea) {
		textarea.focus();
	}
}

// Files chosen with the reply editor's Attach button.
function editorAttachFiles(files)
{
	for (var i = 0; i < files.length; i++) {
		editorSendFile(files[i], true, true);
	}
}

function setReplyBody(text)
{
	editorSetContent('body', text);
	if (text == fs_body_default) {
		text = '';
	}
	$(".conv-reply-block :input[name='body']:first").val(text);
}


// Get ids of the selected conversations
function getSelectedConversations(checkboxes)
{
	if (typeof(checkboxes) == "undefined") {
		checkboxes = $('.conv-checkbox');
	}

	var conv_ids = [];
	checkboxes.each(function() {
		if ($(this).prop('checked')) {
			conv_ids.push($(this).val());
		}
	});

	return conv_ids;
}

function getBrowser(){
    let browser = "";
    let c = navigator.userAgent.search("Chrome");
    let f = navigator.userAgent.search("Firefox");
    let m8 = navigator.userAgent.search("MSIE 8.0");
    let m9 = navigator.userAgent.search("MSIE 9.0");
    if (c > -1) {
        browser = "Chrome";
    } else if (f > -1) {
        browser = "Firefox";
    } else if (m9 > -1) {
        browser ="MSIE 9.0";
    } else if (m8 > -1) {
        browser ="MSIE 8.0";
    }
    return browser;
}

function switchToNote()
{
	$(".conv-reply-block").addClass('hidden');
	$('.conv-add-note:first').click();
}

function rememberNote()
{
	if (!fs_autosave_note) {
		return;
	}
	
	var conversation_id = getGlobalAttr('conversation_id');
	if (!conversation_id) {
		return;
	}

	var note = $('#body').val();
	var note_plain = stripTags(note);

	var conversation_notes = loadNotesFromStorage(conversation_id);

	// Remove old items from browser storage
	for (var i in conversation_notes) {
		if (conversation_notes[i].time) {
			if (conversation_notes[i].time < (new Date()).getTime() - fs_keep_conversation_notes*24*60*60*1000) {
				delete conversation_notes[i];
			}
		}
	}

	if (!note || !note_plain.trim()) {
		delete conversation_notes[conversation_id];
	} else {
		// Remember current note
		conversation_notes[conversation_id] = {
			note: note,
			time: (new Date()).getTime()
		};
	}

	saveNoteToStorage(conversation_notes);
}

function maybeShowStoredNote()
{
	var conversation_id = getGlobalAttr('conversation_id');
	if (!conversation_id) {
		return;
	}
	// Get stored note fom browser storage
	var conversation_notes = loadNotesFromStorage(conversation_id);

	if (conversation_notes) {
		if (typeof(conversation_notes[conversation_id]) != 'undefined' &&
			typeof(conversation_notes[conversation_id].note) != 'undefined' &&
			conversation_notes[conversation_id].note.trim()
		) {
			setReplyBody(conversation_notes[conversation_id].note);
			showNoteForm();
		}
	}
}

// Happens after Undo
function maybeShowDraft()
{
	var thread_id = getQueryParam('show_draft');

	if (!thread_id) {
		return;
	}

	fsAjax({
			action: 'load_draft',
			thread_id: thread_id
		},
		laroute.route('conversations.ajax'),
		function(response) {
			loaderHide();
			if (typeof(response.status) != "undefined" && response.status == 'success') {
				//response.data.is_note = '';
				showReplyForm(response.data);
				if (response.data.is_forward == '1') {
					showForwardForm(response.data);
				}
				$('#thread-'+thread_id).hide();
			} else {
				showAjaxError(response);
			}
		}
	);
}

function forgetNote(conversation_id)
{
	var conversation_id = getGlobalAttr('conversation_id');
	var conversation_notes = loadNotesFromStorage(conversation_id);
	if (conversation_notes && typeof(conversation_notes[conversation_id]) != 'undefined') {
		delete conversation_notes[conversation_id];
		saveNoteToStorage(conversation_notes);
	}
}

function saveNoteToStorage(conversation_notes)
{
	localStorageSetObject('conversation_notes', conversation_notes);
}

function localStorageSetObject(key, obj) {
	localStorageSet(key, JSON.stringify(obj));
}

function loadNotesFromStorage(conversation_id)
{
	return localStorageGetObject('conversation_notes');
}

function localStorageGetObject(key) {
	var json = localStorageGet(key);

	if (json) {
		var obj = {};
		try {
			obj = JSON.parse(json);
		} catch (e) {}
		if (obj && typeof(obj) == 'object') {
			return obj;
		} else {
			return {};
		}
	} else {
		return {};
	}
}

function localStorageSet(key, value)
{
	if (typeof(localStorage) != "undefined") {
		localStorage.setItem(key, value);
	} else {
		return false;
	}
}

function localStorageGet(key)
{
	if (typeof(localStorage) != "undefined") {
		return localStorage.getItem(key);
	} else {
		return false;
	}
}

function localStorageRemove(key)
{
	if (typeof(localStorage) != "undefined") {
		localStorage.removeItem(key);
	} else {
		return false;
	}
}

function stripTags(html)
{
	var div = document.createElement("div");
	div.innerHTML = html;
	var text = div.textContent || div.innerText || "";
	return text;
}

function htmlEscape(text)
{
	return text
         .replace(/&(?!amp;|lt;|gt;|quot;|#039;)/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

function htmlDecode(input)
{
	var e = document.createElement('div');
	e.innerHTML = input;
	// handle case of empty input
	return e.childNodes.length === 0 ? "" : e.childNodes[0].nodeValue;
}

// Change accordion heading background color on open
function initAccordionHeading()
{
	$(".panel-default .collapse").on('shown.bs.collapse', function(e){
    	// change heading background when expanded
    	$(e.target).parent().children('.panel-heading:first').css('background-color', '#f1f3f5');
	});
	$(".collapse").on('hidden.bs.collapse', function(e){
	    // change heading background when hide
	    $(e.target).parent().children('.panel-heading:first').css('background-color', '');
	});
}

// Scroll to
function scrollToElement(el, selector, speed, offset)
{
    if (typeof(offset) == "undefined") {
        offset = 0;
    }
    if (typeof(speed) == "undefined" || !speed) {
        speed = 600;
    }
    var eljq = null;
    if (el) {
        eljq = $(el);
    } else {
        eljq = $(selector);
    }
    var scroller = appScroller();
    var top = eljq.offset().top + offset;
    if (scroller.is('#app-content-scroll')) {
        top += scroller.scrollTop() - scroller.offset().top;
    }
    scroller.animate({scrollTop: top}, speed);
}

// The element the page scrolls in: the workspace's content pane, or the window.
function appScroller()
{
    var pane = $('#app-content-scroll');
    return pane.length ? pane : $('html, body');
}

// Is user replying to the conversation
function getReplyFormMode()
{
	var block = $(".conv-reply-block");
	if (block.hasClass('hidden')) {
		return '';
	} else if (block.hasClass('conv-forward-block')) {
		return 'forward';
	} else if (block.hasClass('conv-note-block')) {
		return 'note';
	} else {
		return 'reply';
	}
}

// Get HTML of the person avatar
function personPhotoHtml(initials, photo_url, attrs, photo_class, size)
{
	if (typeof(size) == "undefined") {
		size = 'sm';
	}
	if (typeof(photo_class) == "undefined") {
		photo_class = '';
	}

	var html = '<span class="photo-'+size+' '+photo_class+'" '+attrs+'>';
	if (photo_url) {
		html += '<img class="person-photo" src="'+photo_url+'" />';
	} else {
		html += '<i class="person-photo person-photo-auto" data-initial="'+initials+'"></i>';
	}
	html += '</span>';

	return html;
}

function switchHelpdeskUrl()
{
	var url = window.location.href.replace(/#.*/, '');
	if (url.indexOf('?') == -1) {
		url = url+'?';
	} else {
		url = url+'&';
	}
	url = url + 'nc='+Date.now()+"#in-app-close";

	window.location.href = url;
}

function inAppPostMessage(data)
{
	if (typeof(webkit) != "undefined" && typeof(webkit.messageHandlers) != "undefined"
		&& typeof(webkit.messageHandlers.cordova_iab) != "undefined"
		&& typeof(webkit.messageHandlers.cordova_iab.postMessage) != "undefined"
	) {
		webkit.messageHandlers.cordova_iab.postMessage(JSON.stringify(data));
	} else {
		// Wait
		setTimeout(function() {
			inAppPostMessage(data);
		}, 100);
	}
}

function inApp(topic, token)
{
	$(document).ready(function() {
		$('.in-app-switcher').removeClass('hidden');
		if (!jQuery.isEmptyObject(fs_in_app_data)) {
			fs_in_app_data['action'] = 'data';
			inAppPostMessage(fs_in_app_data);
		}
		if (!getCookie('in_app')) {
			setCookie('in_app', '1');
		}
		$('#navbar-back').click(function(e) {
			goBack();
			e.preventDefault();
		});
		$('a.in-app-switcher').click(function(e) {
			switchHelpdeskUrl();
			e.preventDefault();
		});
	});
}

function setCookie(name, value, props) {
    props = props || {};
    
    // Default path if not provided
    if (!("path" in props)) {
        props.path = "/";
    }
    
    // Default SameSite None (if desired)
    if (!("samesite" in props)) {
        props.samesite = "None";
    }
    
    // If SameSite=None, Secure flag is required by modern browsers
    if (props.samesite && props.samesite.toLowerCase() === "none" && !("secure" in props)) {
        props.secure = true;
    }

    if (!props.expires) {
        var exp_date = new Date();
        // Add 68 years explicitly (approx 2147483647 seconds)
        exp_date.setTime(exp_date.getTime() + 2147483647 * 1000);
        props.expires = exp_date;
    }

    var exp = props.expires;

    if (typeof exp === "number") {
        var d = new Date();
        d.setTime(d.getTime() + exp * 1000);
        exp = props.expires = d;
    }

    if (exp && typeof exp.toUTCString === "function") {
        props.expires = exp.toUTCString();
    }

    value = encodeURIComponent(value);

    var updatedCookie = name + "=" + value;

    for (var propName in props) {
        updatedCookie += "; " + propName.toLowerCase();
        var propValue = props[propName];
        if (propValue !== true) {
            updatedCookie += "=" + propValue;
        }
    }

    document.cookie = updatedCookie;
}

function getCookie(name)
{
    var matches = document.cookie.match(new RegExp(
        "(?:^|; )" + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + "=([^;]*)"
    ));
    return matches ? decodeURIComponent(matches[1]) : undefined
}

function deleteCookie(name)
{
    document.cookie = name+'=;path=/;expires=Thu, 01 Jan 1970 00:00:01 GMT;';
}

function fsAddAction(action, callback, priority)
{
	if (typeof(priority) == "undefined") {
		priority = 20;
	}
	if (typeof(fs_actions[action]) == "undefined") {
		fs_actions[action] = [];
	}
	fs_actions[action].push({
		callback: callback,
		priority: priority
	});
}

function fsDoAction(action, params)
{
	if (typeof(fs_actions[action]) != "undefined") {
		if (typeof(params) == "undefined") {
			params = {};
		}
		for (var i in fs_actions[action]) {
			fs_actions[action][i].callback(params);
		}
		return true;
	} else {
		return false;
	}
}

function fsAddFilter(filter, callback, priority)
{
	if (typeof(priority) == "undefined") {
		priority = 20;
	}
	if (typeof(fs_filters[filter]) == "undefined") {
		fs_filters[filter] = [];
	}
	fs_filters[filter].push({
		callback: callback,
		priority: priority
	});
}

function fsApplyFilter(filter, value, params)
{
	if (typeof(fs_filters[filter]) != "undefined") {
		if (typeof(params) == "undefined") {
			params = {};
		}
		for (var i in fs_filters[filter]) {
			value = fs_filters[filter][i].callback(value, params);
		}
	}
	return value;
}

function maybeScrollToReplyBlock(offset)
{
	var reply_block = $('.conv-reply-block:visible:first');
	var block_top = reply_block.position().top;
	if (block_top > $(window).height() / 2
		|| block_top < appScroller().scrollTop()
	) {
		if (typeof(offset) == "undefined") {
			offset = -20;
		}
		scrollToElement(reply_block, '', null, offset);
	}
}

function initConvSettings()
{
	var settings_modal = jQuery('#conv-settings-modal');
    var history_select = jQuery('#email_history', settings_modal);

	$('#conv-settings-modal').on('show.bs.modal', function (e) {
		var is_forward = $(".conv-reply-block.conv-forward-block").length;

		if (is_forward) {
			$('#email_history option[value="global"]').hide();
			$('#email_history option[value="none"]').hide();
			if ($('#email_history').val() == 'global') {
				$('#email_history').val('full');
			}
		} else {
			$('#email_history option[value="global"]').show();
			$('#email_history option[value="none"]').show();
		}
	})

    $('.button-save-settings:first', settings_modal).on('click', function(e) {
        e.preventDefault();
        settings_modal.modal('hide');

        $(".conv-reply-block").children().find(":input[name='conv_history']:first").val(history_select.val());

        /*fsAjax(
            {
                action: 'save_settings',
                conversation_id: getGlobalAttr('conversation_id'),
                email_history: history_select.val(),
            },
            laroute.route('conversations.ajax'),
            function(response) {
                if (typeof(response.status) != "undefined" && response.status !== 'success') {
                    if (typeof (response.msg) != "undefined") {
                        showFloatingAlert('error', response.msg);
                    } else {
                        showFloatingAlert('error', Lang.get("messages.error_occurred"));
                    }
                    loaderHide();
                }
            },
            true
        );*/
    });


    $('.button-cancel-settings:first', settings_modal).on('click', function(e) {
        e.preventDefault();
        settings_modal.modal('hide');

	   	var value = $(".conv-reply-block").children().find(":input[name='conv_history']:first").val();
	   	if (!value) {
	   		value = 'global';
	   	}
		jQuery('#email_history', jQuery('#conv-settings-modal')).val(value);
    });
}

function copyToClipboard(text) {
    var $temp = $("<input>");
    $("body").append($temp);
    $temp.val(text).select();
    document.execCommand("copy");
    $temp.remove();
}

function closeAllModals()
{
	$('.modal').modal('hide');
}

function closeVisibleModal()
{
	$('.modal:visible:first').modal('hide');
}

function isModalOpen()
{
	return $('.modal.in').length;
}

function replaceAll(text, search, replacement) {
    return text.split(search).join(replacement);
}

function isChatMode()
{
	return $("body:first").hasClass('chat-mode');
}

function reloadPage()
{
	window.location.href = '';
}

function getLocale()
{
	return $('html:first').attr('lang');
}

function initMergeCustomers()
{
	$(document).ready(function(){
		var input = $('#merge_customer2_id');
		initCustomerSelector(input, {
			placeholder: input.attr('placeholder'),
			multiple: true,
			maximumSelectionLength: 1,
			ajax: {
				url: laroute.route('customers.ajax_search'),
				dataType: 'json',
				delay: 250,
				cache: true,
				data: function (params) {
					return {
						q: params.term,
						exclude_id: getGlobalAttr('customer_id'),
						search_by: 'all',
						use_id: true,
						page: params.page
					};
				}
			}
		});
	});
}
/**
 * Recipients that look like no-reply addresses get a warning below the field.
 */
function initNoreplyWarnings()
{
	var info = $('#noreply-patterns');
	if (!info.length) {
		return;
	}
	var regexes = [];
	$.each(JSON.parse(info.attr('data-regexes') || '[]'), function(i, source) {
		try {
			regexes.push(new RegExp(source, 'i'));
		} catch (e) {}
	});
	var check = function(select) {
		var values = select.val() || [];
		if (!$.isArray(values)) {
			values = [values];
		}
		var container = select.parent();
		container.children('.noreply-alert').remove();
		$.each(values, function(i, email) {
			for (var j = 0; j < regexes.length; j++) {
				if (email && regexes[j].test(email)) {
					container.append($('<div class="alert alert-warning alert-narrow margin-bottom-0 noreply-alert"></div>')
						.html(htmlEscape(info.attr('data-message')).replace(':email', '<strong>'+htmlEscape(email)+'</strong>')));
					break;
				}
			}
		});
	};
	var selects = 'select[name="to"], select[name="to[]"], select[name="to_email[]"], select[name="cc[]"], select[name="bcc[]"], input[name="to_email"]';
	$(document).on('change', selects, function() {
		check($(this));
	});
	$(selects).each(function() {
		check($(this));
	});
}

/**
 * Images from other servers: shown for a message (in place) or always for a
 * customer; hidden again for a customer.
 */
$(document).on('click', '.external-images-show, .external-images-block', function(e) {
	e.preventDefault();
	var link = $(this);
	var notice = link.closest('.external-images-notice');
	fsAjax({
			action: link.hasClass('external-images-block') ? 'block_customer' : link.attr('data-action'),
			thread_id: notice.attr('data-thread-id'),
			customer_id: link.attr('data-customer-id')
		},
		laroute.route('conversations.external_images'),
		function(response) {
			if (!isAjaxSuccess(response)) {
				showAjaxError(response);
			} else if (response.reload) {
				window.location.reload();
			} else {
				$('#thread-'+notice.attr('data-thread-id')+' .thread-content:first').html(response.html);
				notice.remove();
			}
		}
	);
});

// The Send button says which status it sends with: "Send & Close".
function updateSendButtonLabel()
{
	$('#editor_bottom_toolbar .btn-send-text').each(function() {
		var toolbar = $(this).closest('#editor_bottom_toolbar');
		var link = toolbar.find('.dropdown-send-status a[data-send-status="'+toolbar.find('select[name="status"]:first').val()+'"]:first');
		if (link.length) {
			$(this).text(link.attr('data-label'));
		}
	});
}
