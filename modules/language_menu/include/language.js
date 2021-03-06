/**
 * Language Handling
 *
 * Provides language services to browser side of the framework.
 *
 * Author: Mladen Mijatov
 */
var Caracal = Caracal || new Object();
Caracal.language = null;


Caracal.LanguageHandler = function(params) {
	var self = this;

	self.languages = [];
	self.rtl_languages = [];
	self.default_language = 'en';
	self.current_language = 'en';
	self.cache = {};
	self.communicator = null;

	/**
	 * Finalize object initialization.
	 */
	self._init = function() {
		self.current_language = document.querySelector('html').getAttribute('lang');

		// parse language payload
		var raw_payload = document.querySelector('meta[name=language-payload]').getAttribute('content');
		var payload = JSON.parse(window.atob(raw_payload));

		self.languages = payload.items;
		self.rtl_languages = payload.rtl;
		self.default_language = payload.default;
	};

	/**
	 * Generate key for module and constant combination.
	 *
	 * @param string module
	 * @param string constant
	 * @return string
	 */
	self._get_key = function(module, constant) {
		return (module == null ? '_global' : module) + '.' + constant;
	};

	/**
	 * Get language list
	 *
	 * @return json object
	 */
	self.get_languages = function() {
		return self.languages;
	};

	/**
	 * Get RTL language list
	 *
	 * @return array
	 */
	self.get_rtl_languages = function() {
		return self.rtl_languages;
	};

	/**
	 * Check if specified language is RTL
	 *
	 * @return boolean
	 */
	self.is_rtl = function(language) {
		// in case language is not specified use current
		if (language == undefined || language == null)
			var language = self.current_language;

		// return boolean result
		return self.rtl_languages.indexOf(language) > -1;
	};

	/**
	 * Get cached module constnat.
	 *
	 * @param string module
	 * @param string constant
	 */
	self.get_text = function(module, constant) {
		var key = self._get_key(module, constant);
		var result = null;

		if (key in self.cache)
			result = self.cache[key];

		return result;
	};

	/**
	 * Load text constants asynchronously.
	 *
	 * @param string module
	 * @param string constant
	 * @param object callback
	 */
	self.load_text = function(module, constant, callback) {
		var id = (module == null ? '_global' : module) + '.' + constant;
		var data = {
			section: 'language_menu',
			action: 'json_get_text',
			language: self.current_language,
			constant: constant
		};

		if (module != null)
			data.from_module = module;

		// check local cache first
		if (self.cache[id] == undefined) {
			new Communicator('language_menu')
				.on_success(function(data) {
					self.cache[id] = data.text;
					callback(constant, data.text);
				})
				.get('json_get_text', data, 'json');

		} else {
			// we have local cache, send that
			callback(constant, self.cache[id]);
		}
	};

	/**
	 * Get array of cached constants. This function does *NOT*
	 * load data from the server. For that you need to use `load_*`
	 * functions.
	 *
	 * @param string module
	 * @param array constants
	 * @return array
	 */
	self.get_text_array = function(module, constants) {
		var id = (module == null ? '_global' : module) + '.';
		var data = {
			section: 'language_menu',
			action: 'json_get_text_array',
			language: self.current_language,
		};
		var result = {};
		var request = [];

		if (module != null)
			data.from_module = module;

		// check for all constants if we have cache
		for (var i=0; i < constants.length; i++) {
			var key = id + constants[i];

			if (key in self.cache)
				result[constants[i]] = self.cache[key]; else  // add cached value to result
				request.push(constants[i]);  // add constant to requested list
		}

		return result;
	};

	/**
	 * Load array of constants from the server for specified
	 * module. After loading is completed callback function is
	 * called with retrieved data as parameter.
	 *
	 * @param string module
	 * @param array constants
	 * @param object callback
	 */
	self.load_text_array = function(module, constants, callback) {
		var id = (module == null ? '_global' : module) + '.';
		var data = {
			section: 'language_menu',
			action: 'json_get_text_array',
			language: self.current_language,
		};
		var result = {};
		var request = [];

		if (module != null)
			data.from_module = module;

		// check for all constants if we have cache
		for (var i=0; i < constants.length; i++) {
			var key = id + constants[i];

			if (key in self.cache)
				result[constants[i]] = self.cache[key]; else  // add cached value to result
				request.push(constants[i]);  // add constant to requested list
		}

		// check local cache first
		if (request.length > 0) {
			data.constants = request;
			new Communicator('language_menu')
				.on_success(function(data) {
					for (var key in data.text) {
						self.cache[id + key] = data.text[key];
						result[key] = data.text[key];
					}

					callback(result);
				})
				.get('json_get_text_array', data, 'json');

		} else {
			// we have all the data cached, send them right away
			callback(result);
		}
	};

	// initialize
	self._init();
}

// create language handler before other modules
Caracal.language = new Caracal.LanguageHandler();
