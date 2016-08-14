<?php



class backend_MenuItem {
	/**
	 * Menu text
	 * @var string
	 */
	private $title;

	/**
	 * Url to menu icon
	 * @var string
	 */
	private $icon;

	/**
	 * Action URL
	 * @var string
	 */
	private $action;

	/**
	 * Access level
	 */
	private $level;

	/**
	 * Submenu items
	 * @var array
	 */
	private $children = array();

	/**
	 * Constructor
	 *
	 * @param string $title
	 * @param string $icon
	 * @param string $action
	 * @param integer $level
	 * @param string $module
	 * @return backend_MenuItem
	 */
	function __construct($title, $icon, $action, $level=0) {
		$this->title = $title;
		$this->icon = $icon;
		$this->action = $action;
		$this->level = $level;
	}

	/**
	 * Adds item to child list
	 *
	 * @param string $name
	 * @param resource $item
	 */
	function addChild($name, $item) {
		if (is_null($name) || empty($name))
			$this->children[] = $item; else
			$this->children[$name] = $item;

		// register named item in backend
		if (!is_null($name)) {
			$backend = backend::getInstance();
			$backend->registerNamedItem($name, $item);
		}
	}

	/**
	 * Insert item to child list on specified location
	 *
	 * @param resource $item
	 * @param integer $position
	 */
	function insertChild($item, $position=0) {
		array_splice($this->children, $position, 0, array($item));
	}

	/**
	 * Add separator to child list
	 *
	 * @param integer $level
	 */
	function addSeparator($level) {
		$this->children[] = new backend_MenuSeparator($level);
	}

	/**
	 * Draws item on screen
	 */
	function drawItem() {
		if (!$this->isDrawable()) return;

		$icon = '<span style="background-image: url('.$this->icon.')"></span>';

		if (!empty($this->action))
			$link =  "<a onclick=\"{$this->action}\">{$icon}{$this->title}</a>"; else
			$link =  "<a>{$icon}{$this->title}</a>";

		$class = (count($this->children) > 0) ? ' class="submenu"' : '';

		echo "<li{$class}>{$link}";

		if (count($this->children) > 0) {
			echo '<ul>';

			foreach ($this->children as $child)
				$child->drawItem();

			echo '</ul>';
		}

		echo '</li>';
	}

	/**
	 * Check if item id available for current level
	 *
	 * @return boolean;
	 */
	function isDrawable() {
		return $_SESSION['level'] >= $this->level;
	}
}

class backend_MenuSeparator{
	/**
	 * Separator level
	 * @var integer
	 */
	var $level;

	function __construct($level) {
		$this->level = $level;
	}

	/**
	 * Draw separator
	 *
	 * @param integer $level
	 */
	function drawItem() {
		if (!$this->isDrawable()) return;
		echo '<li class="separator"></li>';
	}

	/**
	 * Check if separator is available for current level
	 *
	 * @return boolean;
	 */
	function isDrawable() {
		return $_SESSION['level'] >= $this->level;
	}
}

/**
 * Create backend form action URL for given action
 *
 * @param string $action
 * @return string
 */
function backend_UrlMake($module, $action, $sub_action=null) {
	if (is_null($sub_action)) {
		$result = URL::make_query(
				'backend_module',
				'transfer_control',
				array('backend_action', $action),
				array('module', $module)
			);
	} else {
		$result = URL::make_query(
				'backend_module',
				'transfer_control',
				array('backend_action', $action),
				array('sub_action', $sub_action),
				array('module', $module)
			);
	}

	return $result;
}


function window_Open($id, $width, $title, $can_close, $can_minimize, $url) {
	$can_close = $can_close ? 'true' : 'false';
	$can_minimize = $can_minimize ? 'true' : 'false';

	return "javascript: Caracal.window_system.openWindow('{$id}', {$width}, '{$title}', {$can_close}, '{$url}', this);";
}

function window_OpenHyperlink($text, $id, $width, $title, $can_close, $can_minimize, $module, $action) {
	$url = URL::make_query(_BACKEND_SECTION_, 'transfer_control', array('backend_action', $action), array('module', $module));

	return URL::make_hyperlink($text, window_Open($id, $width, $title, $can_close, $can_minimize, $url), $text);
}

function window_Close($window) {
	return "javascript: Caracal.window_system.closeWindow('{$window}');";
}

function window_LoadContent($window, $url) {
	return "javascript: Caracal.window_system.loadWindowContent('{$window}', '{$url}');";
}

function window_ReloadContent($window) {
	return "javascript: Caracal.window_system.loadWindowContent('{$window}');";
}

function window_Resize($window, $size) {
	return "javascript: window_Resize('{$window}', {$size});";
}

?>
