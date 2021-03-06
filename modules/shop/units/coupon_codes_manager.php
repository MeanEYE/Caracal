<?php
/**
 * Manager for coupon codes.
 */
namespace Modules\Shop\Promotion;
use ItemManager;


class CouponCodesManager extends ItemManager {
	private static $_instance;

	/**
	 * Constructor
	 */
	protected function __construct() {
		parent::__construct('shop_coupon_codes');

		$this->add_property('id', 'int');
		$this->add_property('coupon', 'int');
		$this->add_property('code', 'varchar');
		$this->add_property('times_used', 'int');
		$this->add_property('timestamp', 'timestamp');
		$this->add_property('discount', 'varchar');
	}

	/**
	* Public function that creates a single instance
	*/
	public static function get_instance() {
		if (!isset(self::$_instance))
			self::$_instance = new self();

		return self::$_instance;
	}
}

?>
