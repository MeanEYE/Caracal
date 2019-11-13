/**
 * Editor Language Selector
 *
 * Language selector is used to add support for multiple-languages in backend
 * while editing content. Window system will automatically create new language
 * selector for each window if it detects multi-language input fields.
 */

var Caracal = Caracal || new Object();
Caracal.WindowSystem = Caracal.WindowSystem || new Object();


Caracal.WindowSystem.LanguageSelector = function(window) {
	var self = this;

	self.fields = null;
	self.language = null;

	// container namespaces
	self.ui = new Object();
	self.handler = new Object();
	self.data = new Object();

	/**
	 * Complete object initialization.
	 */
	self._init = function() {
		self.ui.window = window;

		// create button container and configure it
		self.ui.container = document.createElement('div');
		self.ui.container.classList.add('language-selector');
		self.ui.window.ui.menu.append(self.ui.container);

		// find fields to integrate with
		self.fields = self.ui.window.ui.content.querySelectorAll('input.multi-language, textarea.multi-language');

		// create language controls
		var language_list = Caracal.language.get_languages();
		var default_language = null;
		self.ui.controls = new Array();

		for (var i=0, count=language_list.length; i<count; i++) {
			var language = language_list[i];

			// create controls
			var control = document.createElement('a');
			control.text = language.long;
			control.dataset.short = language.short;
			control.addEventListener('click', self.handler.control_click);
			self.ui.container.append(control);
			self.ui.controls.push(control);
		}

		// create data storage for each field
		self.data.initial = new Object();
		self.data.current = new Object();

		for (var i=0, count=self.fields.length; i<count; i++) {
			var field = self.fields[i];

			self.data.initial[field.name] = new Object();
			self.data.current[field.name] = new Object();
		}

		// collect language data associated with fields
		var data_tags = self.ui.window.ui.content.querySelectorAll('language-data');

		for (var i=0, count=data_tags.length; i<count; i++) {
			var data_tag = data_tags[i];
			var field = data_tag.getAttribute('field');
			var language = data_tag.getAttribute('language');

			if (self.data.initial[field] == undefined)
				self.data.initial[field] = new Object();

			if (self.data.current[field] == undefined)
				self.data.current[field] = new Object();

			self.data.initial[field][language] = data_tag.textContent;
			self.data.current[field][language] = data_tag.textContent;
			data_tag.remove();
		}

		// connect events
		for (var i=0, count=self.fields.length; i<count; i++)
			self.fields[i].addEventListener('blur', self.handler.field_lost_focus);
		self.ui.window.ui.content.querySelector('form').addEventListener('reset', self.handler.form_reset);

		// select default language
		self.set_language();
	};

	/**
	 * Handle clicking on control.
	 *
	 * @param object event
	 */
	self.handler.control_click = function(event) {
		// change language
		var language = event.target.dataset.short;
		self.set_language(language);

		// stop default handler
		event.preventDefault();
	};

	/**
	 * Handle resetting of the form.
	 *
	 * @param object event
	 */
	self.handler.form_reset = function(event) {
		self.reset_values();
	};

	/**
	 * Handle multi-language field loosing focus.
	 *
	 * @param object event
	 */
	self.handler.field_lost_focus = function(event) {
		var field = event.target;
		self.data.current[field.name][self.language] = field.value;
	};

	/**
	 * Switch multi-language fields to specified language. If no language
	 * was specified, set to default.
	 *
	 * @param string new_language
	 */
	self.set_language = function(new_language) {
		// if omitted we are switching to default language
		if (new_language == undefined)
			var new_language = Caracal.language.default_language;

		// make sure we are not switching to same language
		if (self.language == new_language)
			return;

		var new_language_is_rtl = Caracal.language.is_rtl(new_language);

		// highlight active control
		for (var i=0, count=self.ui.controls.length; i<count; i++) {
			var control = self.ui.controls[i];

			if (control.dataset.short == new_language)
				control.classList.add('active'); else
				control.classList.remove('active');
		}

		// change input field values
		for (var i=0, count=self.fields.length; i<count; i++) {
			var field = self.fields[i];

			// store current language data
			if (self.language != null)
				self.data.current[field.name][self.language] = field.value;

			// load new language data from the storage
			if (new_language in self.data.current[field.name])
				field.value = self.data.current[field.name][new_language]; else
				field.value = '';

			// apply language direction
			if (new_language_is_rtl)
				field.style.direction = 'rtl'; else
				field.style.direction = 'ltr';

			// fire an event notifying control of change
			var changed_event = document.createEvent('HTMLEvents');
			changed_event.initEvent('change', true, true);
			field.dispatchEvent(changed_event);
		}

		// store new language selection
		self.language = new_language;
	};

	/**
	 * Get language data for specified field. If currently edited values
	 * are not found, return initial values.
	 *
	 * @param string field
	 * @return object
	 */
	self.get_values = function(field) {
		var result = new Object();
		var field_name = typeof field == 'string' ? field : field.name;

		if (field_name in self.data.current)
			result = self.data.current[field_name]; else
		if (field_name in self.data.initial)
			result = self.data.initial[field_name];

		return result;
	};

	/**
	 * Set language data for specified field.
	 *
	 * @param object field
	 * @param object values
	 */
	self.set_values = function(field, values) {
		var field_name = typeof field == 'string' ? field : field.name;

		if (!(field_name in self.data.initial))
			self.data.initial = new Object();

		if (!(field_name in self.data.current))
			self.data.current = new Object();

		for (var i=0, count=Caracal.language.languages.length; i<count; i++) {
			var language = Caracal.language.languages[i];

			if (language.short in values)
				self.data.current[field.name][language.short] = values[language.short]; else
				self.data.current[field.name][language.short] = '';
		}

		if (typeof field == 'object' && 'value' in field && self.language in values)
			field.value = values[self.language];
	};

	/**
	 * Clear current language data for the specified field.
	 *
	 * @param object field
	 */
	self.clear_values = function(field) {
		if (field.name in self.data.current)
			self.data.current[field.name] = new Object();
		field.value = '';
	};

	/**
	 * Restore initial field values.
	 */
	self.reset_values = function() {
		if (self.language == null)
			return;

		for (var i=0, count=self.fields.length; i<count; i++) {
			var field = self.fields[i];

			if (self.language in self.data.initial[field.name])
				field.value = self.data.initial[field.name][self.language]; else
				field.value = '';
		}
	};

	/**
	 * Remove elements from the DOM and prepare object for deletion.
	 */
	self.cleanup = function() {
		self.ui.container.remove();
	};

	// finalize object
	self._init();
}
