<?php

/**
 * Section Handler
 *
 * Author: Mladen Mijatov
 */

final class SectionHandler {
	private static $_instance;

	private static $data;
	private static $params;
	private static $matched_file = null;
	private static $matched_pattern = null;

	const PREFIX = '^(/(?<language>[a-z]{2}))?';
	const SUFFIX = '/?';
	const ROOT_KEY = '/';

	/**
	 * Match template based on URL and extract parameters.
	 */
	public static function prepare() {
		global $url_rewrite, $data_path;
		$result = false;

		// prepare storage
		self::$data = array();
		self::$params = array();

		// load section data
		$raw_data = file_get_contents($data_path.'section.json');
		if ($raw_data !== FALSE) {
			// decode section file
			self::$data = json_decode($raw_data, true);

		} else {
			// report loading error
			error_log('Missing section file!');
			return $result;
		}

		// report decoding error
		if (self::$data == NULL) {
			error_log('Invalid section file!');
			return $result;
		}

		// get query string
		$query_string = $_SERVER['QUERY_STRING'];
		if ($query_string[0] != self::ROOT_KEY)
			$query_string = self::ROOT_KEY.$query_string;

		// try to match whole query string
		foreach (self::$data as $pattern => $template_file) {
			$match = preg_replace('|\{([\w\d\+-_]+)\}|iu', '(?<\1>[\w\d]+)', $pattern);
			$match = self::PREFIX.$match.self::SUFFIX;

			// store pattern params for later use
			preg_match_all('|\{([\w\d_-]+)\}|is', $pattern, $params);
			self::$params[$pattern] = $params;

			// successfully matched query string to template
			if (!$result && preg_match($match, $query_string, $matches)) {
				self::$matched_file = $template_file;
				self::$matched_pattern = $match;
				$result = true;
			}
		}

		// matching failed, try to load home template
		if (!$result)
			if (array_key_exists(self::ROOT_KEY, self::$data)) {
				self::$matched_file = self::$data[self::ROOT_KEY];
				self::$matched_pattern = self::ROOT_KEY;
				$result = true;
			}

		return $result;
	}

	/**
	 * Return matched template file based on query string.
	 *
	 * @return string
	 */
	public static function get_matched_file() {
		return self::$matched_file;
	}

	/**
	 * Return regular expression template used to match template file.
	 *
	 * @return string
	 */
	public static function get_matched_pattern() {
		return self::$matched_pattern;
	}

	/**
	 * Return list of matched templates for specified template file.
	 *
	 * @param string $file
	 * @return string
	 */
	public static function get_templates_for_file(string $file=null) {
		$result = array();

		// collect templates
		if (is_null($file)) {
			$result = self::$params;
		} else {
			foreach (self::$data as $pattern => $template_file)
				if ($file == $template_file)
					$result[$pattern] = self::$params[$template_file];
		}

		return $result;
	}

	/**
	 * Find matching template and transfer control to it.
	 */
	public static function transfer_control() {
		// make sure we have matched page template
		if (is_null(self::$matched_file))
			return;

		// create template handler
		$template = new TemplateHandler(self::$matched_file);
		$template->parse();
	}

	/**
	 * Transfers control to preconfigured template.
	 *
	 * @param string $section
	 * @param string $action
	 * @param string $language
	 */
	public function transferControl($section, $action, $language='') {
		$file = '';

		if (!_AJAX_REQUEST)
			$file = $this->getFile($section, $action, $language);

		if (_AJAX_REQUEST || empty($file)) {
			// request came from script, transfer control to modules
			if (ModuleHandler::is_loaded($section)) {
				$module = call_user_func(array(escape_chars($section), 'getInstance'));
				$params = array('action' => $action);

				// transfer control to module
				$module->transferControl($params, array());

			} else if ($section == 'backend_module' && ModuleHandler::is_loaded('backend')) {
				// transfer control to backend modules
				$module = backend::getInstance();
				$params = array('action' => 'transfer_control');

				// transfer control to module
				$module->transferControl($params, array());

			} else {
				// no matching module exist, try loading template
				$template = new TemplateHandler($file);
				$template->parse();
			}

		} else {
			// section file is defined, load and parse it
			$template = new TemplateHandler($file);
			$template->parse();
		}
	}
}

?>
