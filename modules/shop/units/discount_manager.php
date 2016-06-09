<?php

/**
 * Shop Discount Manager
 *
 * Author: Mladen Mijatov
 */
namespace Modules\Shop\Promotion;

use \ItemManager as ItemManager;


class DiscountManager extends ItemManager {
	private static $_instance;

	/**
	 * Constructor
	 */
	protected function __construct() {
		parent::__construct('shop_discounts');

		$this->addProperty('id', 'int');
		$this->addProperty('type', 'int');
		$this->addProperty('name', 'ml_varchar');
		$this->addProperty('description', 'ml_text');
		$this->addProperty('percent', 'decimal');
	}

	/**
	 * Public function that creates a single instance
	 */
	public static function getInstance() {
		if (!isset(self::$_instance))
		self::$_instance = new self();

		return self::$_instance;
	}
}

?>
