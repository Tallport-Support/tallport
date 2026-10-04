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
var upload_in_progress = false;

var FS_STATUS_CLOSED = 3;

// Ajax based notifications;
// List of funcions preparin data for polycast receive request

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

});

// What the shared scripts set up on the page's body. Run again when
// wire:navigate swaps the body; the scripts themselves load once.
function shellInit()
{
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
	return Tallport.csrf();
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

