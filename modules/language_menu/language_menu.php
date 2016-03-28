<?php

/**
 * Multi-language support module.
 *
 * Author: Mladen Mijatov
 */
use Core\Module;


class language_menu extends Module {
	private static $_instance;
	private $invalid_params = array(
						'PHPSESSID', '__utmz', '__utma', 'language',
						'__utmc', '__utmb', '_', 'subject', 'MAX_FILE_SIZE', '_rewrite'
					);

	/**
	 * Constructor
	 */
	protected function __construct() {
		global $section;

		parent::__construct(__FILE__);

		// load CSS and JScript
		if (ModuleHandler::is_loaded('head_tag')) {
			$head_tag = head_tag::getInstance();

			$head_tag->addTag('script', array('src'=>url_GetFromFilePath($this->path.'include/language.js'), 'type'=>'text/javascript'));

			if ($section == 'backend')
				$head_tag->addTag('script', array('src'=>url_GetFromFilePath($this->path.'include/selector.js'), 'type'=>'text/javascript'));
		}
	}

	/**
	 * Public function that creates a single instance
	 */
	public static function getInstance() {
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
	public function transferControl($params, $children) {
		// global control actions
		if (isset($params['action']))
			switch ($params['action']) {
				case 'print':
					$this->printMenus($params, $children);
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

	public function onInit() {
	}

	public function onDisable() {
	}

	public function addMeta() {
		global $default_language;

		$head_tag = head_tag::getInstance();
		$language_list = Language::getLanguages(false);

		// prepare params
		$params = $_REQUEST;
		$link_params = array();

		foreach($params as $key => $value)
			if (!in_array($key, $this->invalid_params))
				$link_params[$key] = escape_chars($value);

		// add link to each language
		foreach ($language_list as $language_code) {
			$link_params['language'] = $language_code;
			$url = url_MakeFromArray($link_params);

			$head_tag->addTag('link',
					array(
						'rel'		=> 'alternate',
						'href'		=> $url,
						'hreflang'	=> $language_code == $default_language ? 'x-default' : $language_code
					));
		}
	}

	/**
	 * Prints language menu using OL
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	private function printMenus($tag_params, $children) {
		global $action, $section;

		// check if we were asked to get languages from specific module
		if (isset($tag_params['from_module']) && ModuleHandler::is_loaded($tag_params['from_module'])) {
			$module = call_user_func(array($tag_params['from_module'], 'getInstance'));
			$list = $module->language->getLanguages(true);

		} else {
			$list = Language::getLanguages(true);
		}

		$template = $this->loadTemplate($tag_params, 'list_item.xml');
		$template->setTemplateParamsFromArray($children);

		// prepare params
		switch ($_SERVER['REQUEST_METHOD']) {
			case 'POST':
				$params = $_POST;
				break;

			case 'GET':
			default:
				$params = $_GET;
		}
		$link_params = array();

		foreach($params as $key => $value)
			if (!in_array($key, $this->invalid_params))
				$link_params[$key] = escape_chars($value);

		// print language list
		if (count($list) > 0)
			foreach ($list as $short=>$long) {
				$link_params['language'] = $short;
				$link = url_MakeFromArray($link_params);

				$params = array(
					'short_name'	=> $short,
					'long_name'		=> $long,
					'url' 			=> $link
				);

				$template->restoreXML();
				$template->setLocalParams($params);
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
			$module = call_user_func(array(escape_chars($_REQUEST['from_module']), 'getInstance'));
			$list = $module->language->getLanguages(true);

		} else {
			$list = Language::getLanguages(true);
		}

		$rtl = Language::getRTL();
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

		print json_encode($result);
	}

	/**
	 * Get language constant from specified module or from global language file
	 */
	private function json_GetText() {
		// check if we were asked to get languages from specific module
		if (isset($_REQUEST['from_module']) && ModuleHandler::is_loaded($_REQUEST['from_module'])) {
			$module = call_user_func(array(escape_chars($_REQUEST['from_module']), 'getInstance'));
			$text = $module->language->getText(escape_chars($_REQUEST['constant']));

		} else {
			$text = Language::getText(escape_chars($_REQUEST['constant']));
		}

		$result = array(
					'text'	=> $text,
				);

		print json_encode($result);
	}

	/**
	 * Get language constants for specified array
	 */
	private function json_GetTextArray() {
		// check if we were asked to get languages from specific module
		$language_handler = null;
		if (isset($_REQUEST['from_module']) && ModuleHandler::is_loaded($_REQUEST['from_module'])) {
			$module = call_user_func(array(escape_chars($_REQUEST['from_module']), 'getInstance'));
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
					$result['text'][$constant] = $language_handler->getText($constant); else
					$result['text'][$constant] = Language::getText($constant);

		print json_encode($result);
	}

	/**
	 * Get current language
	 */
	private function json_GetCurrentLanguage() {
		global $language;
		print json_encode($language);
	}
}
