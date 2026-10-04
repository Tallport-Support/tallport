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
    	legacyModalDialog($(this));
    	e.preventDefault();
	});
}

// Links that open their content in a dialog the FreeScout way (modules):
// FruitUI's remote dialog, then the link's data-modal-on-show function.
function legacyModalDialog(link)
{
	var dialog = FruitUI.dialog({
		title: link.attr('data-modal-title') || link.text(),
		url: link.attr('data-remote') || link.attr('href'),
		size: link.attr('data-modal-size') == 'lg' ? 'large' : 'medium'
	});
	var on_show = link.attr('data-modal-on-show');
	if (on_show && typeof(window[on_show]) == 'function') {
		dialog.loaded.then(function(body) {
			window[on_show]($(body));
		});
	}
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
		// Chat mode: no Show Details without details.
		var conv_top_blocks = $('#conv-top-blocks');
		if (conv_top_blocks.length && !conv_top_blocks.children('.conv-top-block:first').length) {
			conv_top_blocks.prev().hide();
		}

		// Print
		if (getQueryParam('print')) {
			window.print();
		}

		processLinks();
	});
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

function getGlobalAttr(attr)
{
	return $("body:first").attr('data-'+attr);
}

function setGlobalAttr(attr, value)
{
	return $("body:first").attr('data-'+attr, value);
}

function ajaxSetup()
{
	$.ajaxSetup({
		headers: {
	    	'X-CSRF-TOKEN': getCsrfToken()
		}
	});
}

// Generate random unique ID
function generateDummyId()
{
	// Math.random should be unique because of its seeding algorithm.
	// Convert it to base 36 (numbers + letters), and grab the first 9 characters
	// after the decimal.
	return '_' + Math.random().toString(36).substr(2, 9);
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

// Check if ajax request was successfull
function isAjaxSuccess(response)
{
	if (typeof(response.status) != "undefined" && response.status == 'success') {
		return true;
	} else {
		return false;
	}
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

/**
 * Save draft automatically, on reply change or on click.
 * Validation is not needed.
 */
// If draft is being sent and user clicks Send reply,
// we need to wait and send reply after draft has been saved.
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

function getReplyBody()
{
	var body = document.getElementById('body');
	return body ? body.value : '';
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

function setReplyBody(text)
{
	editorSetContent('body', text);
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
	// The composer's (App\Livewire\ConversationComposer): reply, note, forward or nothing.
	var composer = window.Livewire ? Livewire.getByName('conversation-composer')[0] : null;
	return composer ? composer.mode : '';
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

// A customer search field (select2): the search filter, merging customers.
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
