<?php

/**
 * Multi-language support module.
 *
 * Author: Mladen Mijatov
 */
use Core\Events;
use Core\Module;
use Core\Session\Manager as Session;


class language_menu extends Module {
	private static $_instance;
	private $invalid_params = array(
						'__utmz', '__utma', 'language', '__utmc', '__utmb',
						'_', 'subject', 'MAX_FILE_SIZE', '_rewrite',
						Session::COOKIE_ID, Session::COOKIE_TYPE
					);

	/**
	 * Constructor
	 */
	protected function __construct() {
		global $section;

		parent::__construct(__FILE__);

		// connect events
		Events::connect('head-tag', 'before-print', 'add_tags', $this);
		Events::connect('backend', 'add-tags', 'add_backend_tags', $this);
	}

	/**
	 * Public function that creates a single instance
	 */
	public static function get_instance() {
		if (!isset(self::$_instance))
			self::$_instance = new self();

		return self::$_instance;
	}

	/**
	 * Transfers control to module functions
	 *
	 * @param array $params
	 * @param array $children
	 */
	public function transfer_control($params, $children) {
		// global control actions
		if (isset($params['action']))
			switch ($params['action']) {
				case 'print':
					$this->tag_LanguageList($params, $children);
					break;

				case 'json':
					$this->json_Menu();
					break;

				case 'json_get_text':
					$this->json_GetText();
					break;

				case 'json_get_text_array':
					$this->json_GetTextArray();
					break;

				case 'json_get_current_language':
					$this->json_GetCurrentLanguage();
					break;

				default:
					break;
			}
	}

	public function initialize() {
	}

	public function cleanup() {
	}

	/**
	 * Add meta and other tags to head.
	 */
	public function add_tags() {
		global $default_language;

		$head_tag = head_tag::get_instance();
		$language_list = Language::get_languages(true);
		$in_backend = isset($_REQUEST['section']);

		// add initial language menu payload
		$data = array(
				'items'   => array(),
				'rtl'     => Language::get_rtl(),
				'default' => $default_language
			);

		foreach($language_list as $short => $long)
			$data['items'][] = array(
						'short' => $short,
						'long'  => $long,
					);

		$head_tag->add_tag('meta', array(
				'name'    => 'language-payload',
				'content' => base64_encode(json_encode($data))
			));

		// add language selector script
		$head_tag->add_tag('script', array(
				'src'  => URL::from_file_path($this->path.'include/language.js'),
				'type' => 'text/javascript'
			));

		// get parameters for URL
		if ($in_backend) {
			// get regular query parameters
			$link_params = $this->get_params();

		} else {
			// get values matched with URL
			$pattern = SectionHandler::get_matched_pattern();
			$request_path = URL::get_request_path();

			// extract parameter values from request path
			if (!is_null($pattern)) {
				preg_match($pattern, $request_path, $link_params);
				foreach ($link_params as $key => $value)
					if (is_int($key))
						unset($link_params[$key]);

			} else {
				// there are no parameters matched in URL
				$link_params = array();
			}
		}

		// add link to each language
		foreach ($language_list as $language_code => $language_name) {
			// prepare parameters for url building
			$link_params['language'] = $language_code;
			if ($in_backend)
				$url = URL::get_base().'?'.http_build_query($link_params); else
				$url = URL::make($link_params);

			// add new tag to the head
			$head_tag->add_tag('link', array(
						'rel'      => 'alternate',
						'href'     => $url,
						'hreflang' => $language_code == $default_language ? 'x-default' : $language_code
					));
		}
	}

	/**
	 * Include tags needed for backend.
	 */
	public function add_backend_tags() {
		$head_tag = head_tag::get_instance();
		$head_tag->add_tag('script', array(
				'src'  => URL::from_file_path($this->path.'include/selector.js'),
				'type' => 'text/javascript'
			));
	}

	/**
	 * Return parameters forming language URLs.
	 *
	 * @return array
	 */
	private function get_params() {
		$result = array();

		// prepare params
		switch ($_SERVER['REQUEST_METHOD']) {
			case 'POST':
				$params = $_POST;
				break;

			case 'GET':
			default:
				$params = $_GET;
		}

		// filter out invalid parameters
		foreach($params as $key => $value)
			if (!in_array($key, $this->invalid_params))
				$result[$key] = escape_chars($value);

		return $result;
	}

	/**
	 * Prints language menu using OL
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	private function tag_LanguageList($tag_params, $children) {
		global $section, $language;

		$skip_current = false;
		$in_backend = isset($_REQUEST['section']);

		// check if we were asked to get languages from specific module
		if (isset($tag_params['from_module']) && ModuleHandler::is_loaded($tag_params['from_module'])) {
			$module = call_user_func(array(fix_chars($tag_params['from_module']), 'get_instance'));
			$list = $module->language->get_languages(true);

		} else {
			$list = Language::get_languages(true);
		}

		// allow for showing only available languages
		if (isset($tag_params['skip_current']))
			$skip_current = $tag_params['skip_current'] == 1;

		$template = $this->load_template($tag_params, 'list_item.xml');
		$template->set_template_params_from_array($children);

		// get parameters for URL
		if ($in_backend) {
			// get regular query parameters
			$link_params = $this->get_params();

		} else {
			// get values matched with URL
			$pattern = SectionHandler::get_matched_pattern();
			$request_path = URL::get_request_path();

			// extract parameter values from request path
			if (!is_null($pattern)) {
				preg_match($pattern, $request_path, $link_params);
				foreach ($link_params as $key => $value)
					if (is_int($key))
						unset($link_params[$key]);

			} else {
				// there are no parameters matched in URL
				$link_params = array();
			}
		}

		// make sure language list is not empty
		if (count($list) == 0)
			return;

		// print language list
		$matched_file = SectionHandler::get_matched_file();
		foreach ($list as $short => $long) {
			// skip current language if requested
			if ($skip_current && $short == $language)
				continue;

			// prepare parameters for link creation
			$link_params['language'] = $short;
			if ($in_backend)
				$link = URL::get_base().'?'.http_build_query($link_params); else
				$link = URL::make($link_params, $matched_file);

			// prepare template parameters
			$params = array(
				'short_name' => $short,
				'long_name'  => $long,
				'url'        => $link
			);

			// render tag
			$template->restore_xml();
			$template->set_local_params($params);
			$template->parse( );
		}
	}

	/**
	 * Print JSON object for usage by the backend API
	 */
	private function json_Menu() {
		global $action, $section, $language, $default_language;

		// check if we were asked to get languages from specific module
		if (isset($_REQUEST['from_module']) && ModuleHandler::is_loaded($_REQUEST['from_module'])) {
			$module = call_user_func(array(escape_chars($_REQUEST['from_module']), 'get_instance'));
			$list = $module->language->get_languages(true);

		} else {
			$list = Language::get_languages(true);
		}

		$rtl = Language::get_rtl();
		$result = array(
					'error'				=> false,
					'error_message'		=> '',
					'items'				=> array(),
					'rtl'				=> $rtl,
					'default_language'	=> $default_language,
					'current_language'	=> $language
				);

		foreach($list as $short => $long)
			$result['items'][] = array(
									'short'			=> $short,
									'long'			=> $long,
								);

		header('Content-type: application/json');
		print json_encode($result);
	}

	/**
	 * Get language constant from specified module or from global language file
	 */
	private function json_GetText() {
		// check if we were asked to get languages from specific module
		if (isset($_REQUEST['from_module']) && ModuleHandler::is_loaded($_REQUEST['from_module'])) {
			$module = call_user_func(array(escape_chars($_REQUEST['from_module']), 'get_instance'));
			$text = $module->language->get_text(escape_chars($_REQUEST['constant']));

		} else {
			$text = Language::get_text(escape_chars($_REQUEST['constant']));
		}

		// prepare response object
		$result = array(
					'text'	=> $text,
				);

		header('Content-type: application/json');
		print json_encode($result);
	}

	/**
	 * Get language constants for specified array
	 */
	private function json_GetTextArray() {
		// check if we were asked to get languages from specific module
		$language_handler = null;
		if (isset($_REQUEST['from_module']) && ModuleHandler::is_loaded($_REQUEST['from_module'])) {
			$module = call_user_func(array(escape_chars($_REQUEST['from_module']), 'get_instance'));
			$language_handler = $module->language;
		}

		// prepare variables
		$constants = fix_chars($_REQUEST['constants']);
		$result = array(
					'text'	=> array()
				);

		// get constants
		if (count($constants) > 0)
			foreach ($constants as $constant)
				if (!is_null($language_handler))
					$result['text'][$constant] = $language_handler->get_text($constant); else
					$result['text'][$constant] = Language::get_text($constant);

		header('Content-type: application/json');
		print json_encode($result);
	}

	/**
	 * Get current language
	 */
	private function json_GetCurrentLanguage() {
		global $language;
		header('Content-type: application/json');
		print json_encode($language);
	}
}
