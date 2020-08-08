/**
 * Window Management System
 * Generic Window Class
 *
 * This class is used to load content from specified URL and use it as a content for window. If
 * window contains form automatic submit event listener is added and properly handled.
 */

var Caracal = Caracal || new Object();
Caracal.WindowSystem = Caracal.WindowSystem || new Object();


/**
 * Backend Window Constructor
 *
 * If optional `structure` parameter is specified window will be created
 * from existing elements on the page and try to adopt components instead
 * of creating new ones.
 *
 * @param string id
 * @param integer width
 * @param string title
 * @param string url
 * @param object structure
 */
Caracal.WindowSystem.Window = function(id, width, title, url, structure) {
	var self = this;

	self.id = id;
	self.url = url;
	self.visible = false;
	self.predefined = structure !== undefined;
	self.caller = null;
	self.stack_position = 1000;  // position of window in stack used by System
	self.icon = null;

	self.parent = null;
	self.system = null;

	// container namespaces
	self.ui = new Object();
	self.ui.notebook = null;
	self.ui.language_selector = null;
	self.handler = new Object();
	self.drag = new Object();

	/**
	 * Finish object initialization.
	 */
	self._init = function() {
		if (!self.predefined) {
			// create new window structure
			self.ui.container = document.createElement('div');
			with (self.ui.container) {
				setAttribute('id', self.id);
				classList.add('window');
				style.width = width;
				addEventListener('mousedown', self.handler.container_mouse_down);
			}

			// create window title bar
			self.ui.title_bar = document.createElement('div');
			with (self.ui.title_bar) {
				classList.add('title');
				addEventListener('mousedown', self.handler.title_drag_start);
				addEventListener('touchstart', self.handler.title_drag_start);
			}
			self.ui.container.append(self.ui.title_bar);

			// create window icon
			self.icon = document.createElement('svg');
			self.ui.title_bar.append(self.icon);

			// title container
			self.ui.title = document.createElement('span');
			self.ui.title.innerHTML = title;
			self.ui.title_bar.append(self.ui.title);

			// main window menu
			self.ui.menu = document.createElement('nav');
			self.ui.container.append(self.ui.menu);

			// window content
			self.ui.content = document.createElement('div');
			self.ui.content.classList.add('content')
			self.ui.container.append(self.ui.content);

			// add close button if needed
			self.ui.close_button = document.createElement('a');
			with (self.ui.close_button) {
				innerHTML = '<svg><use xlink:href="#icon-close"/></svg>';
				classList.add('button');
				classList.add('close');
				addEventListener('click', self.handler.close_button_click)
			}

			self.ui.title_bar.append(self.ui.close_button);

		} else {
			// find new window structure
			self.ui.container = structure;
			with (self.ui.container) {
				style.width = width;
				addEventListener('mousedown', self.handler.container_mouse_down);
			}

			// find window title bar
			self.ui.title_bar = self.ui.container.querySelector('div.title');
			if (self.ui.title_bar) {
				with (self.ui.title_bar) {
					addEventListener('mousedown', self.handler.title_drag_start);
					addEventListener('touchstart', self.handler.title_drag_start);
				}

				// find window icon
				self.icon = self.ui.title_bar.querySelector('svg');

				// title container
				self.ui.title = self.ui.title_bar.querySelector('span');
				if (title != null)
					self.ui.title.innerHTML = title;

				// add close button if needed
				self.ui.close_button = self.ui.title_bar.querySelector('a.button.close');
				if (self.ui.close_button)
					with (self.ui.close_button) {
						innerHTML = '<svg><use xlink:href="#icon-close"/></svg>';
						addEventListener('click', self.handler.close_button_click)
					}
			}

			// window elements
			self.ui.menu = self.ui.container.querySelector('nav');
			self.ui.content = self.ui.container.querySelector('div.content')
		}

		// empty placeholders
		self.ui.language_selector = null;
		self.ui.notebook = null;
	};

	/**
	 * Handle clicking anywhere on window.
	 *
	 * @param object event
	 */
	self.handler.container_mouse_down = function(event) {
		self.system.focus_window(self.id);
	};

	/**
	 * Handle clicking on window list.
	 *
	 * @param object event
	 */
	self.handler.window_list_item_click = function(event) {
		event.preventDefault();
		self.system.focus_window(self.id);
	};

	/**
	 * Handle start dragging.
	 */
	self.handler.title_drag_start = function(event) {
		// allow dragging with first mouse button only
		if (event.type == 'mousemove' && button != 1)
			return;

		// prevent default behavior
		event.preventDefault();

		// store offset for later use
		if (event.type == 'mousedown') {
			self.drag.offset_x = event.clientX - self.ui.container.offsetLeft;
			self.drag.offset_y = event.clientY - self.ui.container.offsetTop;

		} else if (event.type == 'touchstart') {
			var touch = event.targetTouches.item(0);
			self.drag.offset_x = touch.clientX - self.ui.container.offsetLeft;
			self.drag.offset_y = touch.clientY - self.ui.container.offsetTop;
		}

		// add new event listeners
		with (self.parent) {
			addEventListener('mouseup', self.handler.title_drag_end);
			addEventListener('touchend', self.handler.title_drag_end);
			addEventListener('mousemove', self.handler.title_drag);
			addEventListener('touchmove', self.handler.title_drag);
		}

		// disable animations for smooth experience
		self.ui.container.style.transition = 'none';
	};

	/**
	 * Handle stop dragging.
	 */
	self.handler.title_drag_end = function(event) {
		// prevent default behavior
		event.preventDefault();

		// remove event listeners
		with (self.parent) {
			removeEventListener('mouseup', self.handler.title_drag_end);
			removeEventListener('touchend', self.handler.title_drag_end);
			removeEventListener('mousemove', self.handler.title_drag);
			removeEventListener('touchmove', self.handler.title_drag);
		}

		// restore animations
		self.ui.container.style.transition = '';
		self.drag_active = false;
	};

	/**
	 * Handle dragging window.
	 *
	 * @param object event
	 */
	self.handler.title_drag = function(event) {
		event.preventDefault();
		event.stopPropagation();

		// get container offset
		if (event.type == 'mousemove') {
			var position_top = event.clientY - self.drag.offset_y;
			var position_left = event.clientX - self.drag.offset_x;

		} else if (event.type == 'touchmove') {
			var touch = event.targetTouches.item(0);
			var position_top = touch.clientY - self.drag.offset_y;
			var position_left = touch.clientX - self.drag.offset_x;
		}

		// make sure window doesn't run away
		if (position_top < 0)
			position_top = 0;

		if (position_left < 0)
			position_left = 0;

		// update position
		self.ui.container.style.left = position_left;
		self.ui.container.style.top = position_top;
	};

	/**
	 * Handle clicking on close button in title bar.
	 *
	 * @param object event
	 */
	self.handler.close_button_click = function(event) {
		// prevent default behavior
		event.preventDefault();

		// close widnow
		self.close();
	};

	/**
	 * Event fired when there was an error loading AJAX data.
	 *
	 * @param object request
	 * @param string status
	 * @param string error
	 */
	self.handler.content_error = function(request, status, error) {
		self.ui.content.innerHTML = status + ': ' + error;

		// remove loading indicator
		self.ui.container.classList.remove('loading');
	};

	/**
	 * Event fired on when window content has finished loading.
	 *
	 * @param string data
	 */
	self.handler.content_load = function(data) {
		// animate display
		var start_position = self.ui.container.offsetTop;
		var start_height = self.ui.content.offsetHeight;

		// set new window content
		self.ui.content.innerHTML = data;

		// find and execute all the scripts in newly loaded content
		var scripts = self.ui.content.querySelectorAll('script');

		if (scripts != null) {
			for (var i=0, count=scripts.length; i<count; i++) {
				var inactive_script = scripts[i];
				var new_script = document.createElement('script');

				// configure new script
				new_script.type = inactive_script.type;
				new_script.async = inactive_script.async;
				if (inactive_script.src)
					new_script.src = inactive_script.src;

				var code = document.createTextNode(inactive_script.textContent);
				new_script.appendChild(code);

				// add new script and remove inactive one
				var parent = inactive_script.parentNode;
				parent.insertBefore(new_script, inactive_script);
				parent.removeChild(inactive_script);
			}
		}

		// remove previous language selector user interface
		if (self.ui.language_selector)
			self.ui.language_selector.cleanup();

		// check if form contains multi-language fields
		var fields = self.ui.content.querySelectorAll('input.multi-language, textarea.multi-language');
		if (fields.length > 0) {
			// create new language selector
			self.ui.language_selector = new Caracal.WindowSystem.LanguageSelector(self);
		}

		// remove previous notebook user interface
		if (self.ui.notebook)
			self.ui.notebook.cleanup();

		// check if form requires notebook
		if (self.ui.content.querySelectorAll('div.notebook').length > 0) {
			self.ui.notebook = new Caracal.WindowSystem.Notebook(self);
		}

		// improve look of tables
		var tables = self.ui.content.querySelectorAll('table.list');
		if (tables.length > 0) {
			for (var i=0, count=tables.length; i<count; i++) {
				var table = tables[i];

				// we only adjust tables which require height
				if (!('height' in table.dataset))
					continue;

				// create bottom spacing table body
				var existing_body = table.querySelector('tbody');
				var desired_height = parseInt(table.dataset['height']);
				var spacing_body = document.createElement('tbody');
				spacing_body.classList.add('spacing');

				// add spacing body to the table
				table.appendChild(spacing_body);

				// create table wrapper to allow scrolling
				var parent_element = table.parentNode;
				var wrapper = document.createElement('div');

				wrapper.classList.add('scrolled');
				wrapper.style.height = desired_height.toString() + 'px';

				if (table.classList.contains('with-border'))
					wrapper.classList.add('with-border');

				parent_element.insertBefore(wrapper, table);
				wrapper.appendChild(table);
			}
		}

		// integrate toolbars
		Caracal.Toolbar.implement(self);

		// position the window
		var top_position = start_position + Math.floor((start_height - self.ui.content.offsetHeight) / 2);
		self.ui.container.style.top = top_position;
		self.ui.container.classList.add('loaded');

		// attach events
		self._attach_events();

		// remove loading indicator
		self.ui.container.classList.remove('loading');

		// emit event
		self.system.events.trigger('window-content-load', self);

		// if display level is right, focus first element
		if (self.stack_position >= 1000)
			self._focus_input_element();
	};

	/**
	 * Attach window to window system and its containers.
	 *
	 * @param object system
	 */
	self.attach_to_system = function(system) {
		// attach container to main container
		if (!self.predefined)
			system.container.append(self.ui.container);

		// save parent for later use
		self.parent = system.container;
		self.system = system;

		// add window list item
		if (self.system && self.system.window_list) {
			self.ui.window_list_item = document.createElement('a');
			self.ui.window_list_item.innerHTML = self.ui.title.innerHTML;
			self.ui.window_list_item.addEventListener('click', self.handler.window_list_item_click);
			self.system.window_list.append(self.ui.window_list_item);
		}

		// check if form contains multi-language fields
		var fields = self.ui.content.querySelectorAll('input.multi-language, textarea.multi-language');
		if (fields.length > 0)
			self.ui.language_selector = new Caracal.WindowSystem.LanguageSelector(self);

		// check if form requires notebook
		if (self.ui.content.querySelectorAll('div.notebook').length > 0)
			self.ui.notebook = new Caracal.WindowSystem.Notebook(self);

		// integrate toolbars
		Caracal.Toolbar.implement(self);

		// attach events
		self._attach_events();

		// emit event
		self.system.events.trigger('window-content-load', self);

		// if display level is right, focus first element
		if (self.stack_position >= 1000)
			self._focus_input_element();

		return self;
	};

	/**
	 * Open window.
	 *
	 * @param boolean center
	 * @return object
	 */
	self.open = function(center) {
		if (self.visible)
			return self;  // don't allow animation of visible window

		// center if needed
		if (center) {
			var position_top = Math.round((self.parent.offsetHeight - self.ui.container.offsetHeight) / 2);
			var position_left = Math.round((self.parent.offsetWidth - self.ui.container.offsetWidth) / 2);
			self.ui.container.style.left = position_left;
			self.ui.container.style.top = position_top;
		}

		// apply params and show window
		self.ui.container.classList.add('visible');
		self.visible = true;

		// set window to be top level
		self.focus();

		// emit event
		self.system.events.trigger('window-open', self);

		return self;
	};

	/**
	 * Close window.
	 */
	self.close = function() {
		self.visible = false;
		self.system.remove_window(self);
		self.system.focus_top_window();
		if (self.ui.window_list_item)
			self.ui.window_list_item.remove();
		self.ui.container.remove();

		// emit event
		self.system.events.trigger('window-close', self);
	};

	/**
	 * Set focus on self.
	 */
	self.focus = function() {
		self.system.focus_window(self.id);
		return self;
	};

	/**
	 * Event triggered when window gains focus.
	 */
	self.gain_focus = function() {
		// set level
		self.stack_position = 1000;
		self.ui.container.style.zIndex = self.stack_position;

		// update classes
		self.ui.container.classList.add('focused');
		if (self.ui.window_list_item)
			self.ui.window_list_item.classList.add('active');

		// emit event
		self.system.events.trigger('window-focus-gain', self);
	};

	/**
	 * Event triggered when window loses focus.
	 */
	self.lose_focus = function() {
		self.stack_position--;
		self.ui.container.style.zIndex = self.stack_position;

		// upodate classes
		self.ui.container.classList.remove('focused');
		if (self.ui.window_list_item)
			self.ui.window_list_item.classList.remove('active');

		// emit event
		self.system.events.trigger('window-focus-lost', self);
	};

	/**
	 * Load/reload window content.
	 *
	 * @param string url
	 */
	self.load_content = function(url) {
		if (url != undefined)
			self.url = url;

		self.ui.container.classList.add('loading');

		$.ajax({
			cache: false,
			context: self,
			dataType: 'html',
			success: self.handler.content_load,
			error: self.handler.content_error,
			url: self.url
		});

		return self;  // allow linking
	};

	/**
	 * Set window icon.
	 *
	 * @param string icon
	 */
	self.set_icon = function(icon) {
		if (self.icon)
			self.icon.outerHTML = icon;

		if (self.ui.window_list_item)
			self.ui.window_list_item.innerHTML = icon;
	};

	/**
	 * Submit form content to server and load response.
	 *
	 * @param object form
	 */
	self._submit_form = function(form) {
		self.ui.container.classList.add('loading');

		var data = {};
		var field_tags = ['input', 'select', 'textarea'];

		// emit event
		self.system.events.trigger('window-before-submit', self);

		// collect data from from
		for (var i = 0; i < field_tags.length; i++) {
			var tag_name = field_tags[i];
			var fields = form.querySelectorAll(tag_name);

			// make sure form contains fields of this tag name
			if (!fields.length)
				continue;

			for (var j = 0; j < fields.length; j++) {
				var field = fields[j];
				var name = field.getAttribute('name');
				var type = field.hasAttribute('type') ? field.getAttribute('type') : null;

				// we ignore fields without name
				if (!name)
					continue;

				var is_list = name.substring(name.length - 2) == '[]';

				if (field.classList.contains('multi-language')) {
					// get other language data
					var language_data = self.ui.language_selector.data.current[field.name];
					var current_language = self.ui.language_selector.language;

					// update current language data
					language_data[current_language] = field.value;

					// add all languages to data for sending
					for (var language in language_data)
						data[name + '_' + language] = encodeURIComponent(language_data[language]);

				} else {
					if (type == 'checkbox') {
						// checkbox
						data[name] = field.checked ? 1 : 0;

					} else if (type == 'radio') {
						// radio button
						if (data[name] == undefined) {
							var value = form.querySelector('input[type=radio][name='+name+']:checked').value;
							data[name] = encodeURIComponent(value);
						}

					} else {
						// all other components
						var value = encodeURIComponent(field.value);

						if (!is_list) {
							// store regular field value
							data[name] = value;

						} else {
							// create list storage
							if (data[name] == undefined)
								data[name] = new Array();

							// add value to the list
							data[name].push(value);
						}
					}
				}
			}
		}

		// send data to server
		$.ajax({
			cache: false,
			context: self,
			dataType: 'html',
			method: 'POST',
			data: data,
			success: self.handler.content_load,
			error: self.handler.content_error,
			url: form.getAttribute('action')
		});
	};

	/**
	 * Focus first input element if it exists
	 */
	self._focus_input_element = function() {
		var element = self.ui.content.querySelector('input,select,textarea,button');
		if (element)
			element.focus();
	};

	/**
	 * Attach events to window content
	 */
	self._attach_events = function() {
		var forms = self.ui.content.querySelectorAll('form:not([target])');
		var checkboxes = self.ui.content.querySelectorAll('input[type="checkbox"]');
		var radio_buttons = self.ui.content.querySelectorAll('input[type="radio"]');

		// integrate vector icons in checkboxes and radio buttons
		for (var j = 0; j < checkboxes.length; j++) {
			var checkbox = checkboxes[j];
			var sprite = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			sprite.innerHTML = '<use xlink:href="#icon-checkmark"/>';

			checkbox.parentNode.insertBefore(sprite, checkbox.nextSibling);
		}

		for (var j = 0; j < radio_buttons.length; j++) {
			var radio_button = radio_buttons[j];
			var sprite = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			sprite.innerHTML = '<use xlink:href="#icon-checkmark"/>';

			radio_button.parentNode.insertBefore(sprite, radio_button.nextSibling);
		}

		// make sure we have forms to attach events to
		if (forms.length == 0)
			return self;

		// attach events to forms
		for (var i = 0; i < forms.length; i++) {
			var form = forms[i];
			var file_inputs = form.querySelectorAll('input[type="file"]');

			// integrate form event listeners
			if (file_inputs.length == 0) {
				// normal case submission without file uploads
				form.addEventListener('submit', function(event) {
					event.preventDefault();
					self._submit_form(this);
				});

			} else {
				// When submitting from with file uploads we use hidden iframe
				// which is configured to behave the same way we would normally
				// send data through AJAX. To achieve this we need to create hidden
				// fields for each individual language to simulate multi-language
				// data submission.
				form.addEventListener('submit', function(event) {
					// trigger before submit event
					self.system.events.trigger('window-before-submit', self);

					// get all multi-language fields
					var fields = this.querySelectorAll('input.multi-language, textarea.multi-language');
					if (fields.length == 0)
						return;

					var current_language = self.ui.language_selector.language;

					for (var j = 0; j < fields.length; j++) {
						var field = fields[j];
						var field_name = field.getAttribute('name');
						var data = self.ui.language_selector.data.current[field_name];

						// update current language data
						data[current_language] = field.value;

						// create hidden field for each language
						for (var language in data) {
							var hidden_field = document.createElement('input');

							with (hidden_field) {
								setAttribute('type', 'hidden');
								setAttribute('name', field_name + '_' + language);
								value = data[language];
							}

							field.parentNode.insertBefore(hidden_field, field.nextSibling);
						}
					}
				});

				// create target hidden iframe which will receive data
				var id = 'upload_frame_' + (Math.random() * 100).toString(16);
				var iframe = document.createElement('iframe');
				iframe.setAttribute('name', id);
				iframe.setAttribute('id', id);
				iframe.style.display = 'none';

				form.parentNode.insertBefore(iframe, form.nextSibling);
				form.setAttribute('target', id);

				// add event listener to iframe through timeout to avoid initial triggering on some browsers
				setTimeout(function() {
					iframe.addEventListener('load', function(event) {
						var content = iframe.contentDocument.querySelector('body');

						// pass the data to even handler
						self.handler.content_load(content.innerHTML);
						content.innerHTML = '';
					});
				}, 100);
			}
		}

		return self;
	};

	/**
	 * Set window size.
	 *
	 * @param integer width
	 * @return boolean
	 */
	self.set_size = function(width) {
		self.ui.container.style.width = width.toString() + 'px';
		return true;
	};

	/**
	 * Set specified string as window title.
	 *
	 * @param string title
	 * @return boolean
	 */
	self.set_title = function(title) {
		var result = false;

		if (self.ui.title) {
			self.ui.title.innerHTML = title;
			self.ui.title_bar.append(self.ui.close_button);
			result = true;
		}

		return result;
	};

	/**
	 * Return window size.
	 *
	 * @return array
	 */
	self.get_size = function() {
		var result = new Array();

		// get container size
		result.push(self.ui.container.offsetWidth);
		result.push(self.ui.container.offsetHeight);

		return result;
	};

	/**
	 * Return content size.
	 *
	 * @return array
	 */
	self.get_content_size = function(include_menu) {
		var result = new Array();

		// get container size
		result.push(self.ui.content.offsetWidth);
		result.push(self.ui.content.offsetHeight);

		if (include_menu && self.ui.menu)
			result[1] += self.ui.menu.offsetHeight;

		return result;
	};

	/**
	 * Get window title.
	 *
	 * @return string
	 */
	self.get_title = function() {
		return self.ui.title.innerText;
	};

	// finish object initialization
	self._init();
};
