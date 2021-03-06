/**
 * Checkout Form Implemenentation
 *
 * Copyright (c) 2015. by Way2CU
 * Author: Mladen Mijatov
 */

// create namespaces if needed
var Site = Site || {};
var Caracal = Caracal || {};
Caracal.Shop = Caracal.Shop || {};


Caracal.Shop.BuyerInformationForm = function() {
	var self = this;

	self.page_control = new PageControl('div#input_details div.pages');
	self.interface_save_function = null;

	// local namespaces
	self.handler = new Object();
	self.validator = new Object();
	self.account = new Object();
	self.shipping = new Object();
	self.payment = new Object();
	self.billing = new Object();

	/**
	 * Complete object initialization.
	 */
	self._init = function() {
		// find DOM elements
		self.account.page = $('div#sign_in.page');
		self.shipping.page = $('div#shipping_information.page');
		self.payment.page = $('div#payment_method.page');
		self.billing.page = $('div#billing_information.page');

		// implement page control
		self.page_control
			.setAllowForward(false)
			.setSubmitOnEnd(true)
			.attachControls('div#checkout_steps a')
			.attachForm('div#input_details form');

		// create password recovery dialog
		self.account.password_dialog = new Caracal.Dialog();

		// create cvv information dialog
		self.billing.cvv_dialog = new Caracal.Dialog();
		self.billing.cvv_dialog
				.set_size(642, 265)
				.set_content_from_dom('img#what_is_cvv');

		Caracal.language.load_text_array(
				'shop',
				[
					'title_password_dialog', 'title_cvv_dialog',
					'label_no_estimate', 'label_estimated_time'
				],
				self._configure_dialogs
			);

		// set validators used by page control
		self.account.page.data('validator', self.validator.sign_in_page);
		self.shipping.page.data('validator', self.validator.shipping_information_page);
		self.billing.page.data('validator', self.validator.billing_information_page);

		// no payment method was preselected, we need validator
		if (self.payment.page.length > 0)
			self.payment.page.data('validator', self.validator.payment_method_page);

		self.payment.methods = $('div.payment_methods span');
		self.payment.method_field = $('input[name=payment_method]');

		// shipping information pages
		self.shipping.overlay = self.shipping.page.find('div.container.methods div.overlay');
		self.shipping.types_overlay = self.shipping.page.find('div.container.types div.overlay');
		self.shipping.method_container = self.shipping.page.find('div.container.method');
		self.shipping.address_container = self.shipping.page.find('div.container.address');
		self.shipping.contact_container = self.shipping.page.find('div.container.contact');
		self.shipping.types_container = self.shipping.page.find('div.container.types');
		self.shipping.interface_container = self.shipping.page.find('div.container.interface');
		self.shipping.methods = self.shipping.method_container.find('div.details a');

		// check if user is already logged
		if (self.shipping.page.find('select[name=presets]').data('autoload') == 1)
			self._load_account_information();

		// connect events
		self.account.page.find('a.password_recovery').click(self._show_password_dialog);
		self.account.page.find('input[name=existing_user]').change(self.handler.account_type_change);
		self.shipping.page.find('select[name=presets]').change(self.handler.shipping_information_preset_change);
		self.shipping.page.find('div.container div.summary').on('click', self.handler.summary_click);
		self.shipping.methods.on('click', self.handler.delivery_method_click);
		self.payment.methods.click(self.handler.payment_method_click);
		self.page_control.events.connect('page-flip', self.handler.page_flip);

		// apply account option
		self.handler.account_type_change(null);

		// select delivery method if only one is available
		if (self.shipping.methods.length == 1)
			self.shipping.methods.eq(0).trigger('click');
	};

	/**
	 * Function called once async load of text variables is completed.
	 */
	self._configure_dialogs = function(data) {
		self.account.password_dialog.set_title(data['title_password_dialog']);
		self.billing.cvv_dialog.set_title(data['title_cvv_dialog']);
	};

	/**
	 * Show password recovery dialog.
	 *
	 * @param object event
	 */
	self._show_password_dialog = function(event) {
		event.preventDefault();
		self.account.password_dialog.open();
	};
	/**
	 * Show CVV explanation dialog.
	 *
	 * @param object event
	 */
	self._show_cvv_dialog = function(event) {
		event.preventDefault();
		self.billing.cvv_dialog.open();
	};

	/**
	 * Load account information from backend.
	 */
	self._load_account_information = function() {
		new Communicator('shop')
			.on_success(self.handler.account_load_success)
			.on_error(self.handler.account_load_error)
			.get('json_get_account_info', null);
	};

	/**
	 * Update summary for address container.
	 */
	self._update_shipping_address_summary = function() {
		var fields = self.shipping.address_container.find('input,select');
		var summary = self.shipping.address_container.find('div.summary');

		fields.each(function() {
			var field = $(this);
			var label = summary.find('span.' + field.attr('name'));

			label.html(field.val());
		});
	};

	/**
	 * Update summary for contact information container.
	 */
	self._update_shipping_contact_summary = function() {
		var fields = self.shipping.contact_container.find('input,select');
		var summary = self.shipping.contact_container.find('div.summary');

		fields.each(function() {
			var field = $(this);
			var label = summary.find('span.' + field.attr('name'));

			label.html(field.val());
		});
	};

	/**
	 * Update summary for delivery type container.
	 */
	self._update_shipping_types_summary = function() {
		var summary = self.shipping.types_container.find('div.summary span.price');
		var type = self.shipping.types_container.find('div.details a.selected');

		summary.html(type.data('price') + ' ' + type.data('currency'));
	};

	/**
	 * Handle page flip and scroll window to the top if checkout process
	 * is not entirely visible.
	 *
	 * @param integer current_page
	 * @param integer new_page
	 * @return boolean
	 */
	self.handler.page_flip = function(current_page, new_page) {
		var container = document.getElementById('checkout_container');
		container.scrollIntoView();
		return true;  // we don't want to prevent page flip
	};

	/**
	 * Handle clicking on delivery type.
	 *
	 * @param object event
	 */
	self.handler.delivery_type_click = function(event) {
		// prevent default link behavior
		event.preventDefault();

		// hightlight selected type
		var type = $(this);
		self.shipping.types_container.find('div.summary a').not(type).removeClass('selected');
		type.addClass('selected');

		// set delivery type values
		self.set_delivery_method(null, type.data('type'));
	};

	/**
	 * Handle clicking on summary.
	 */
	self.handler.summary_click = function(event) {
		// prevent default behavior
		event.preventDefault();

		// switch container to edit mode
		$(this).closest('div.container').removeClass('completed');
	};

	/**
	 * Handle successful data load from server.
	 *
	 * @param object data
	 */
	self.handler.delivery_types_load = function(data) {
		// add every delivery method to the container
		var container = self.shipping.types_container.find('div.details');
		container.html('');

		// pre-cache language constants
		var no_estimate = Caracal.language.get_text('shop', 'label_no_estimate');
		var estimated_time = Caracal.language.get_text('shop', 'label_estimated_time');

		if (data.delivery_prices) {
			for (var id in data.delivery_prices) {
				var method = data.delivery_prices[id];
				var entry = $('<a>');
				var name = $('<span>');
				var price = $('<span>');
				var time = $('<span>');

				// create interface
				entry.on('click', self.handler.delivery_type_click);

				price
					.html(method[1])
					.attr('data-currency', method[2]);

				name
					.html(method[0])
					.addClass('name')
					.append(price)
					.appendTo(entry);

				if (method[4] === null) {
					// no estimate available
					time.html(no_estimate);

				} else {
					var start = method[3] != null ? method[3] + ' - ' : '';
					var end = method[4];
					time.html(estimated_time + '<br>' + start + end);
				}

				time
					.addClass('estimate')
					.appendTo(entry);

				entry
					.data('type', id)
					.data('price', method[1])
					.data('currency', method[2])
					.attr('href', 'javascript: void(0)')
					.addClass('method')
					.appendTo(container);
			}

			// show list of delivery methods
			self.shipping.types_container.addClass('visible');
		}

		// hide overlay
		self.shipping.types_overlay.removeClass('visible');
	};

	/**
	 * Handle error on server side while loading delivery methods.
	 *
	 * @param object error
	 */
	self.handler.delivery_types_error = function(error) {
		// add every delivery method to the container
		self.shipping.types_interface.removeClass('visible');

		// hide overlay
		self.shipping.types_overlay.removeClass('visible');
	};

	/**
	 * Handle clicking on delivery method.
	 *
	 * @param object event
	 */
	self.handler.delivery_method_click = function(event) {
		var method = $(this);

		// prevent default behavior
		event.preventDefault();

		// reset all containers
		self.shipping.address_container.removeClass('visible completed');
		self.shipping.contact_container.removeClass('visible completed');
		self.shipping.types_container.removeClass('visible completed');
		self.shipping.interface_container.removeClass('visible completed');

		if (method.data('user-information') == 1) {
			// show fields for user information entry
			self.shipping.address_container.addClass('visible');

		} else if (method.data('custom-interface') == 1) {
			// show busy indicator
			self.shipping.overlay.addClass('visible');

			// load delivery method custom interface
			new Communicator('shop')
				.on_success(self.handler.custom_interface_load)
				.on_error(self.handler.custom_interface_error)
				.get('json_get_delivery_method_interface', {method: method.data('value')}, 'html');
		}

		// set value for hidden fields
		self.set_delivery_method(method.data('value'), '');

		// update button status
		self.shipping.methods.not(method).removeClass('selected');
		method.addClass('selected');

		// remove bad class
		self.shipping.methods.removeClass('bad');
	};

	/**
	 * Handle loading custom delivery method interface from the server.
	 *
	 * @param object data
	 */
	self.handler.custom_interface_load = function(data) {
		self.shipping.interface_container
				.html(data)
				.addClass('visible')
				.find('div.summary').on('click', self.handler.summary_click);
		self.shipping.overlay.removeClass('visible');
	};

	/**
	 * Handle communication error when loading custom delivery method interface
	 * from the server.
	 *
	 * @param object error
	 */
	self.handler.custom_interface_error = function(object) {
		// add every delivery method to the container
		self.shipping.interface_container.removeClass('visible');

		// hide overlay
		self.shipping.overlay.removeClass('visible');
	};

	/**
	 * Handle changing type of account for buyers information.
	 *
	 * @param object event
	 */
	self.handler.account_type_change = function(event) {
		var selection = self.account.page.find('input[name=existing_user]:checked').val();

		switch (selection) {
			// existing account
			case 'log_in':
				self.account.page.find('div.new_account').removeClass('visible');
				self.account.page.find('div.existing_account').addClass('visible');
				self.account.page.find('div.guest_checkout').removeClass('visible');
				break;

			// new account
			case 'sign_up':
				self.account.page.find('div.new_account').addClass('visible');
				self.account.page.find('div.existing_account').removeClass('visible');
				self.account.page.find('div.guest_checkout').removeClass('visible');
				break;

			// checkout as guest
			case 'guest':
			default:
				self.account.page.find('div.new_account').removeClass('visible');
				self.account.page.find('div.existing_account').removeClass('visible');
				self.account.page.find('div.guest_checkout').addClass('visible');
				break;
		}
	};

	/**
	 * Handle change in shipping information preset control.
	 *
	 * @param object event
	 */
	self.handler.shipping_information_preset_change = function(event) {
		var control = $(this);
		var option = control.find('option[value='+control.val()+']');

		self.shipping.page.find('input[name=name]').val(option.data('name'));
		self.shipping.page.find('input[name=phone]').val(option.data('phone'));
		self.shipping.page.find('input[name=street]').val(option.data('street'));
		self.shipping.page.find('input[name=street2]').val(option.data('street2'));
		self.shipping.page.find('input[name=city]').val(option.data('city'));
		self.shipping.page.find('input[name=zip]').val(option.data('zip'));
		self.shipping.page.find('select[name=country]').val(option.data('country'));
		self.shipping.page.find('input[name=state]').val(option.data('state'));
		self.shipping.page.find('input[name=access_code]').val(option.data('access_code'));
	};

	/**
	 * Handle clicking on payment method.
	 *
	 * @param object event
	 */
	self.handler.payment_method_click = function(event) {
		var method = $(this);

		// set payment method before processing
		self.payment.method_field.val(method.data('name'));

		// add selection class to
		self.payment.methods.not(method).removeClass('active');
		method.addClass('active');

		// remove bad class
		self.payment.methods.removeClass('bad');
	};

	/**
	 * Handle account data loading.
	 *
	 * @param object data
	 */
	self.handler.account_load_success = function(data) {
		var presets = self.shipping.page.find('select[name=presets]');
		var email_field = self.account.page.find('input[name=sign_in_email]');
		var password_field = self.account.page.find('input[name=sign_in_password]');

		// reset presets
		presets.html('');

		// clear bad state from fields
		email_field.removeClass('bad');
		password_field.removeClass('bad');

		// populate shipping information with data received from the server
		self.shipping.page.find('input[name=first_name]').val(data.information.first_name);
		self.shipping.page.find('input[name=last_name]').val(data.information.last_name);
		self.shipping.page.find('input[name=email]').val(data.information.email);

		// empty preset
		var empty_option = $('<option>');

		empty_option
			.html(Caracal.language.get_text('shop', 'new_preset'))
			.attr('value', 0)
			.appendTo(presets);

		// add different presets of data
		for (var index in data.delivery_addresses) {
			var address = data.delivery_addresses[index];
			var option = $('<option>');

			option
				.html(address.name)
				.attr('value', address.id)
				.data('name', address.name)
				.data('street', address.street)
				.data('street2', address.street2)
				.data('phone', address.phone)
				.data('city', address.city)
				.data('zip', address.zip)
				.data('state', address.state)
				.data('country', address.country)
				.appendTo(presets);
		}

		// alter field visibility
		self.shipping.page.find('select[name=presets]').parent().show();
		self.shipping.page.find('input[name=name]').parent().show();
		self.shipping.page.find('input[name=email]').parent().hide();
		self.shipping.page.find('hr').eq(0).show();
	};

	/**
	 * Handle server side error during account load process.
	 *
	 * @param object xhr
	 * @param string transfer_status
	 * @param string description
	 */
	self.handler.account_load_error = function(xhr, transfer_status, description) {
		var email_field = self.account.page.find('input[name=sign_in_email]');
		var password_field = self.account.page.find('input[name=sign_in_password]');

		// add "bad" class to input fields
		email_field.addClass('bad');
		password_field.addClass('bad');

		// show error message to user
		alert(description);
	};

	/**
	 * Validate sign in page.
	 *
	 * @return boolean
	 */
	self.validator.sign_in_page = function() {
		var result = false;

		// check which option is selected
		var selection = self.account.page.find('input[name=existing_user]:checked').val();

		switch (selection) {
			case 'log_in':
				var email_field = self.account.page.find('input[name=sign_in_email]');
				var password_field = self.account.page.find('input[name=sign_in_password]');
				var captcha_field = self.account.page.find('label.captcha');

				// prepare data
				var data = {
						username: email_field.val(),
						password: password_field.val(),
						captcha: captcha_field.find('input').val()
					};

				new Communicator('backend')
						.on_success(function(data) {
							// load account information
							if (data.logged_in) {
								self._load_account_information();

								// hide captcha field
								captcha_field.addClass('hidden');

							} else {
								// failed login
								email_field.addClass('bad');
								password_field.addClass('bad');

								// show captcha if required
								if (data.show_captcha)
									captcha_field.removeClass('hidden');
							}

							// allow page switch
							result = data.logged_in;
						})
						.on_error(function() {
							// don't allow page switch
							result = false;

							// mark fields as bad
							email_field.addClass('bad');
							password_field.addClass('bad');
						})
						.set_asynchronous(false)
						.get('json_login', data);
				break;

			case 'sign_up':
				// get new account section
				var container = self.account.page.find('div.new_account');
				var fields = container.find('input');
				var first_name = container.find('input[name=first_name]');
				var last_name = container.find('input[name=last_name]');

				// ensure required fields are filled in
				fields.each(function(index) {
					var field = $(this);

					if (field.attr('type') != 'checkbox')
						value_is_good = field.val() != ''; else
						value_is_good = field.is(':checked');

					if (field.data('required') == 1 && !value_is_good)
						field.addClass('bad'); else
						field.removeClass('bad');
				});

				// make sure passwords match
				var password = container.find('input[name=new_password]');
				var password_confirm = container.find('input[name=new_password_confirm]');

				if (password.val() != password_confirm.val()) {
					password.addClass('bad');
					password_confirm.addClass('bad');
				}

				// check if account with specified email already exists
				var email_field = self.account.page.find('input[name=new_email]');

				if (email_field.val() != '') {
					new Communicator('shop')
						.on_success(function(data) {
							if (data.account_exists) {
								email_field.addClass('bad');
								alert(data.message);
							} else {
								email_field.removeClass('bad');
							}
						})
						.get('json_get_account_exists', {email: email_field.val()});
				}

				// alter field visibility
				self.shipping.page.find('select[name=presets]').parent().hide();
				self.shipping.page.find('input[name=name]').val(first_name.val() + ' ' + last_name.val()).parent().show();
				self.shipping.page.find('input[name=email]').val(email_field.val()).parent().hide();
				self.shipping.page.find('hr').eq(0).hide();

				result = !(password.hasClass('bad') || password_confirm.hasClass('bad') || email_field.hasClass('bad'));
				break;

			case 'guest':
			default:
				// get agree checkbox
				var agree_to_terms = self.account.page.find('input[name=agree_to_terms]');
				var fields = self.account.page.find('div.guest_checkout input');

				// ensure required fields are filled in
				fields.each(function(index) {
					var field = $(this);

					if (field.attr('type') != 'checkbox')
						value_is_good = field.val() != ''; else
						value_is_good = field.is(':checked');

					if (field.data('required') == 1 && !value_is_good)
						field.addClass('bad'); else
						field.removeClass('bad');
				});

				result = fields.filter('.bad').length == 0;

				// hide unneeded fields
				self.shipping.page.find('select[name=presets]').parent().hide();
				self.shipping.page.find('input[name=name]').parent().show();
				self.shipping.page.find('input[name=email]').parent().show();
				self.shipping.page.find('hr').eq(0).show();
		}

		return result;
	};

	/**
	 * Validate shipping information page. System expects the following process:
	 * Delivery method -> [User information -> Additional information] -> [Custom interface | types]
	 *
	 * User information and custom interface are completely optional. However if delivery method
	 * requires showing this interface interface will be shown one step at a time.
	 *
	 * @return boolean
	 */
	self.validator.shipping_information_page = function() {
		var method = self.shipping.methods.filter('.selected');

		// make sure delivery method is selected
		if (method.length == 0) {
			self.shipping.methods.addClass('bad');
			return false;  // prevent page from switching
		}

		// prepare for checking what should be shown next
		var show_address = method.data('user-information') == 1;
		var show_interface = method.data('custom-interface') == 1;
		var address_visible = self.shipping.address_container.hasClass('visible');
		var address_completed = self.shipping.address_container.hasClass('completed');
		var contact_visible = self.shipping.contact_container.hasClass('visible');
		var contact_completed = self.shipping.contact_container.hasClass('completed');
		var interface_visible = self.shipping.interface_container.hasClass('visible');
		var interface_completed = self.shipping.interface_container.hasClass('completed');
		var types_visible = self.shipping.types_container.hasClass('visible');
		var types_completed = self.shipping.types_container.hasClass('completed');

		// make sure required address fields are entered
		if (address_visible) {
			var fields = self.shipping.address_container.find('input,select');

			// iterate over fields
			fields.each(function() {
				var field = $(this);

				if (field.data('required') == 1 && field.is(':visible') && field.val() == '')
					field.addClass('bad'); else
					field.removeClass('bad');
			});

			// complete current interface
			if (fields.filter('.bad').length == 0 && !address_completed) {
				address_completed = true;
				self.shipping.address_container.addClass('completed');
				self._update_shipping_address_summary();
			}
		}

		// show contact information container
		if (show_address && address_completed && !contact_visible) {
			self.shipping.contact_container.addClass('visible');
			return false;  // prevent page from switching
		}

		// make sure required contact fields are filled in
		if (contact_visible) {
			var fields = self.shipping.contact_container.find('input,select');

			for (var i = 0, count = fields.length; i < count; i++) {
				var field = fields.eq(i);

				if (field.data('required') == 1 && field.is(':visible') && field.val() == '')
					field.addClass('bad'); else
					field.removeClass('bad');
			}

			// complete current interface
			if (fields.filter('.bad').length == 0 && !contact_completed) {
				contact_completed = true;
				self.shipping.contact_container.addClass('completed');
				self._update_shipping_contact_summary();
			}
		}

		// flag denoting status of user information
		var user_information_complete = (show_address && (address_completed && contact_completed)) || !show_address;

		// show custom interface
		if (show_interface && user_information_complete && !interface_visible) {
			// show busy indicator
			self.shipping.overlay.addClass('visible');

			// load delivery method custom interface
			new Communicator('shop')
				.on_success(self.handler.custom_interface_load)
				.on_error(self.handler.custom_interface_error)
				.get('json_get_delivery_method_interface', {method: method.data('value')}, 'html');

			return false;  // prevent page from switching

		} else if (!show_interface && user_information_complete && !types_visible) {
			// prepare data
			var data = {
					method: method.data('value'),
					street: self.shipping.address_container.find('input[name=street]').val(),
					street2: self.shipping.address_container.find('input[name=street2]').val(),
					city: self.shipping.address_container.find('input[name=city]').val(),
					zip_code: self.shipping.address_container.find('input[name=zip]').val(),
					state: self.shipping.address_container.find('input[name=state]').val(),
					country: self.shipping.address_container.find('input[name=country]').val()
				};

			// get delivery types for selected method
			new Communicator('shop')
				.on_success(self.handler.delivery_types_load)
				.on_error(self.handler.delivery_types_error)
				.get('json_get_delivery_estimate', data);

			return false;  // prevent page from switching
		}

		// validate custom interface
		if (show_interface && !interface_completed && self.interface_save_function != null)
			self.interface_save_function();

		// validate delivery type
		if (!show_interface && !types_completed) {
			var types = self.shipping.types_container.find('div.details a');

			if (types.filter('.selected').length == 0) {
				// mark delivery types as bad
				types.addClass('bad');

			} else {
				// complete delivery type selection process
				types.removeClass('bad');
				self._update_shipping_types_summary();
				self.shipping.types_container.addClass('completed');
			}
		}

		// prepare conditions
		var delivery_type_completed = (show_interface && interface_completed) || (!show_interface && types_completed);

		return user_information_complete && delivery_type_completed;
	};

	/**
	 * Validate billing information page.
	 *
	 * @return boolean
	 */
	self.validator.billing_information_page = function() {
		var fields = self.billing_information_form.find('input,select');
		var method = self.methods.filter('.active');

		fields.each(function(index) {
			var field = $(this);

			if (field.data('required') == 1 && field.val() == '')
				field.addClass('bad'); else
				field.removeClass('bad');
		});

		// if supported check data validity with method provided functions
		if (self.billing_information_form.find('.bad').length == 0)
			switch (self.method_field.val()) {
				case 'stripe':
					var card_number = fields.filter('input[name=billing_credit_card]');
					var card_expire_month = fields.filter('input[name=billing_expire_month]');
					var card_expire_year = fields.filter('input[name=billing_expire_year]');
					var card_cvv = fields.filter('input[name=billing_cvv]');

					if (!Stripe.card.validateCardNumber(card_number.val()))
						card_number.addClass('bad');

					if (!Stripe.card.validateExpiry(card_expire_month.val(), card_expire_year.val())) {
						card_expire_month.addClass('bad');
						card_expire_year.addClass('bad');
					}

					if (!Stripe.card.validateCVC(card_cvv.val()))
						card_cvv.addClass('bad');

					break;
			}

		return self.billing_information_form.find('.bad').length == 0;
	};

	/**
	* Validate payment method page.
	*
	* @return boolean
	*/
	self.validator.payment_method_page = function() {
		var result = self.payment.methods.filter('.active').length > 0;

		if (!result)
			self.payment.methods.addClass('bad');

		return result;
	};

	/**
	 * Set interface save function which will be called before switching to a new page. Return
	 * value of specified function does not play a role in validating shipping information page.
	 * If class `completed` is present on custom interface container then upon completion of
	 * other containers on the page user will be allowed to proceed.
	 *
	 * Return value denotes if function was set.
	 *
	 * @param function callback
	 * @return boolean
	 */
	self.set_interface_save_function = function(callback) {
		if (!typeof callback == 'function')
			return false;

		self.interface_save_function = callback;
		return true;
	};

	/**
	 * Set delivery method and type to be sent to server.
	 *
	 * @param string method
	 * @param string type
	 */
	self.set_delivery_method = function(method, type) {
		if (!method)
			var method = self.shipping.method_container.find('div.details a.selected').data('value');

		self.shipping.page.find('input[name=delivery_method]').val(method);
		self.shipping.page.find('input[name=delivery_type]').val(type);
	};

	/**
	 * Return name of selected delivery method.
	 *
	 * @return string
	 */
	self.get_selected_delivery_method = function() {
		var result = null;
		var selected = self.shipping.method_container.find('div.details a.selected');

		if (selected.length > 0)
			result = selected.data('value');

		return result;
	};

	// finalize object
	self._init();
};


/**
 * Checkout form implementation
 */
Caracal.Shop.CheckoutForm = function() {
	var self = this;

	self.checkout = $('div#checkout');
	self.checkout_button = self.checkout.find('div.checkout_controls button[type=submit]');

	// handler functions namespace
	self.handler = new Object();

	/**
	 * Complete object initialization.
	 */
	self._init = function() {
		// connect events
		self.checkout.find('textarea[name=remarks]').on('blur', self.handler.remarks_focus_lost);
	};

	/**
	 * Save remarks when they loose focus.
	 *
	 * @param object event
	 */
	self.handler.remarks_focus_lost = function(event) {
		var textarea = $(this);

		// send data to server
		new Communicator('shop')
			.send('json_save_remark', {remark: textarea.val()});
	};

	/**
	 * Enable checkout button.
	 */
	self.enable_checkout_button = function() {
		self.checkout_button.removeAttr('disabled', 'disabled');
	};

	/**
	 * Disable checkout button.
	 */
	self.disable_checkout_button = function() {
		self.checkout_button.attr('disabled', 'disabled');
	};

	/**
	 * Get total price for charging.
	 * @return float
	 */
	self.get_total = function() {
		return self.value + self.shipping + self.handling;
	};

	// complete object initialization
	self._init();
};


$(function() {
	if ($('div#input_details').length > 0) {
		Site.buyer_information_form = new Caracal.Shop.BuyerInformationForm();

	} else if ($('div#checkout').length > 0) {
		Site.checkout_form = new Caracal.Shop.CheckoutForm();
	}
});
