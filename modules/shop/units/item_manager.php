<?php

/**
 * Shop Item Manager
 *
 * This manager is used to manipulate data in shop_items table.
 * Don't try to access this table manually as other tables depend
 * on it (like payment logs and similar).
 *
 * Author: Mladen Mijatov
 */

class ShopItemManager extends ItemManager {
	private static $_instance;

	/**
	 * Constructor
	 */
	protected function __construct() {
		parent::__construct('shop_items');

		$this->addProperty('id', 'int');
		$this->addProperty('uid', 'varchar');
		$this->addProperty('name', 'ml_varchar');
		$this->addProperty('description', 'ml_text');
		$this->addProperty('gallery', 'int');
		$this->addProperty('manufacturer', 'int');
		$this->addProperty('size_definition', 'int');
		$this->addProperty('colors', 'varchar');
		$this->addProperty('author', 'int');
		$this->addProperty('views', 'int');
		$this->addProperty('price', 'decimal');
		$this->addProperty('discount', 'decimal');
		$this->addProperty('tax', 'decimal');
		$this->addProperty('weight', 'decimal');
		$this->addProperty('votes_up', 'int');
		$this->addProperty('votes_down', 'int');
		$this->addProperty('timestamp', 'timestamp');
		$this->addProperty('priority', 'int');
		$this->addProperty('visible', 'boolean');
		$this->addProperty('deleted', 'boolean');
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
