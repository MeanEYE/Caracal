/**
 * Dynamic Contact Form Support JavaScript
 * Caracal Development Framework
 *
 * Copyright (c) 2014. by Way2CU, http://way2cu.com
 * Authors: Mladen Mijatov
 */

var Caracal = Caracal || {};
Caracal.contact_form = Caracal.contact_form || {};


function ContactForm(form_object) {
	var self = this;

	self._form = null;
	self._fields = null;
	self._communicator = null;
	self._overlay = null;
	self._message = null;
	self._silent = false;

	/**
	 * Complete object initialization.
	 */
	self._init = function() {
		// create object for communicating with backend
		self._communicator = new Communicator('contact_form');

		self._communicator
				.on_success(self._handle_success)
				.on_error(self._handle_error);

		// find form and fields
		self._form = $(form_object);
		self._fields = self._form.find('input,textarea,select');

		// connect form events
		self._form.submit(self._handle_submit);

		// get overlay
		self._overlay = self._form.find('div.overlay');
		if (self._overlay.length == 0) {
			self._overlay = $('<div>');
			self._overlay
					.addClass('overlay')
					.appendTo(self._form);
		}

		// create dialog
		if (Caracal.contact_form.dialog == null) {
			Caracal.contact_form.dialog = new Dialog();
			Caracal.contact_form.dialog.setTitle(language_handler.getText('contact_form', 'dialog_title'));
			Caracal.contact_form.dialog.setSize(400, 100);
			Caracal.contact_form.dialog.setScroll(false);
			Caracal.contact_form.dialog.setClearOnClose(true);
		}

		// create message container
		self._message = $('<div>');
		self._message.css('padding', '20px');
	};

	/**
	 * Get data from fields.
	 *
	 * @return object
	 */
	self._get_data = function() {
		var result = {};

		self._fields.each(function() {
			var field = $(this);
			var name = field.attr('name');
			var type = field.attr('type');

			switch (type) {
				case 'checkbox':
					result[name] = this.checked ? 1 : 0;
					break;

				case 'radio':
					if (result[name] == undefined) {
						var selected_radio = self._fields.filter('input:radio[name=' + name + ']:checked');
						if (selected_radio.length > 0)
							result[name] = selected_radio.val()
					}
					break;

				default:
					result[name] = field.val();
					break;
			}

		});

		return result;
	};

	/**
	 * Handle submitting a form.
	 *
	 * @param object event
	 * @return boolean
	 */
	self._handle_submit = function(event) {
		// prevent original form from submitting
		event.preventDefault();

		// collect data
		var data = self._get_data();

		// show overlay
		self._overlay.addClass('visible');

		// send data
		self._communicator.send('submit', data)
	};

	/**
	 * Handle successful data transmission.
	 *
	 * @param object data
	 */
	self._handle_success = function(data) {
		// hide overlay
		self._overlay.removeClass('visible');

		// configure and show dialog
		var response = self._form.triggerHandler('dialog-show', [data.error]);
		if (response == undefined || (response != undefined && response == true)) {
			self._message.html(data.message);
			Caracal.contact_form.dialog.setError(data.error);
			Caracal.contact_form.dialog.setContent(self._message);
			Caracal.contact_form.dialog.show();
		}

		// clear form on success
		if (!data.error)
			self._form[0].reset();

		// trigger other form events
		self._form.trigger('analytics-event', data);
	};

	/**
	 * Handle error in data transmission or on server side.
	 *
	 * @param object xhr
	 * @param string request_status
	 * @param string description
	 */
	self._handle_error = function(xhr, request_status, description) {
		// hide overlay
		self._overlay.removeClass('visible');

		// configure and show dialog
		var response = self._form.triggerHandler('dialog-show', [true]);
		if (response == undefined || (response != undefined && response == true)) {
			self._message.html(data.message);
			Caracal.contact_form.dialog.setError(true);
			Caracal.contact_form.dialog.setContent(self._message);
			Caracal.contact_form.dialog.show();
		}
	};

	// finalize object
	self._init();
}

$(function() {
	Caracal.contact_form.forms = [];
	Caracal.contact_form.dialog = null;

	$('form[data-dynamic]').each(function() {
		var form = new ContactForm(this);
		Caracal.contact_form.forms.push(form);
	});
});
