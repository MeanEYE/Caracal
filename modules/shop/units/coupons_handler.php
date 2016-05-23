<?php

/**
 * Handler class for coupon related operations.
 */
namespace Modules\Shop\Promotion;

require_once('coupons_manager.php');


class CouponHandler {
	private static $_instance;
	private $parent;
	private $name;
	private $path;

	const SUB_ACTION = 'coupons';

	/**
	 * Constructor
	 */
	protected function __construct($parent) {
		global $section;

		$this->parent = $parent;
		$this->name = $this->parent->name;
		$this->path = $this->parent->path;

		// create main menu
		if ($section == 'backend') {
			$backend = backend::getInstance();
			$method_menu = $backend->getMenu('shop_special_offers');

			if (!is_null($method_menu))
				$method_menu->addChild('', new backend_MenuItem(
									$this->getLanguageConstant('menu_coupons'),
									url_GetFromFilePath($this->path.'images/coupons.svg'),

									window_Open( // on click open window
												'shop_coupons',
												400,
												$this->getLanguageConstant('title_coupons'),
												true, true,
												backend_UrlMake($this->name, self::SUB_ACTION, 'show')
											),
									$level=5
								));
		}
	}

	/**
	 * Public function that creates a single instance
	 */
	public static function getInstance($parent) {
		if (!isset(self::$_instance))
			self::$_instance = new self($parent);

		return self::$_instance;
	}

	/**
	 * Transfer control to group
	 *
	 * @param array $params
	 * @param array $children
	 */
	public function transferControl($params = array(), $children = array()) {
		$action = isset($params['sub_action']) ? $params['sub_action'] : null;

		switch ($action) {

		}
	}

	/**
	 * Show coupons management form.
	 */
	private function show_coupons() {
	}
}

?>
