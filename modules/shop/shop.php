<?php

/**
 * Shop Module
 *
 * Complete online shopping solution integration. This module provides
 * only the basic framework for online shopps. Payment and delivery methods
 * need to be added additionally.
 *
 * Author: Mladen Mijatov
 */

use Core\Events;
use Core\Module;

require_once('units/payment_method.php');
require_once('units/delivery_method.php');
require_once('units/item_handler.php');
require_once('units/category_handler.php');
require_once('units/currencies_handler.php');
require_once('units/item_sizes_handler.php');
require_once('units/item_size_values_manager.php');
require_once('units/transactions_manager.php');
require_once('units/transactions_handler.php');
require_once('units/warehouse_handler.php');
require_once('units/transaction_items_manager.php');
require_once('units/transaction_plans_manager.php');
require_once('units/recurring_payments_manager.php');
require_once('units/buyers_manager.php');
require_once('units/delivery_address_manager.php');
require_once('units/delivery_address_handler.php');
require_once('units/related_items_manager.php');
require_once('units/manufacturer_handler.php');
require_once('units/delivery_methods_handler.php');
require_once('units/token_manager.php');
require_once('units/delivery.php');
require_once('units/transaction.php');
require_once('units/token.php');

use Modules\Shop\Delivery as Delivery;
use Modules\Shop\Transaction as Transaction;
use Modules\Shop\Token as Token;

use Modules\Shop\TokenManager as TokenManager;
use Modules\Shop\Item\Handler as ShopItemHandler;


final class TransactionType {
	const SUBSCRIPTION = 0;
	const REGULAR = 1;
	const DONATION = 2;
	const DELAYED = 3;

	// language constant mapping
	public static $reverse = array(
		self::SUBSCRIPTION => 'type_subscription',
		self::REGULAR      => 'type_regular',
		self::DONATION     => 'type_donation',
		self::DELAYED      => 'type_delayed'
	);
}


final class TransactionStatus {
	const UNKNOWN = -1;
	const PENDING = 0;
	const DENIED = 1;
	const COMPLETED = 2;
	const CANCELED = 3;
	const SHIPPING = 4;
	const SHIPPED = 5;
	const LOST = 6;
	const DELIVERED = 7;
	const PROCESSED = 8;

	// language constant mapping
	public static $reverse = array(
		self::UNKNOWN   => 'status_unknown',
		self::PENDING   => 'status_pending',
		self::DENIED    => 'status_denied',
		self::COMPLETED => 'status_completed',
		self::CANCELED  => 'status_canceled',
		self::SHIPPING  => 'status_shipping',
		self::SHIPPED   => 'status_shipped',
		self::LOST      => 'status_lost',
		self::DELIVERED => 'status_delivered',
		self::PROCESSED => 'status_processed'
	);

	// list of statuses available for manual setting based on current transaction status
	public static $flow = array(
		TransactionType::REGULAR => array(
			self::COMPLETED	=> array(self::COMPLETED, self::SHIPPING),
			self::SHIPPING	=> array(self::SHIPPING, self::SHIPPED),
			self::SHIPPED	=> array(self::LOST, self::DELIVERED)
		),
		TransactionType::DELAYED => array(
			self::PENDING	=> array(self::PENDING, self::PROCESSED, self::CANCELED),
			self::COMPLETED	=> array(self::COMPLETED, self::SHIPPING),
			self::SHIPPED	=> array(self::LOST, self::DELIVERED),
			self::CANCELED  => array(self::CANCELED),
			self::DENIED	=> array(self::DENIED, self::PROCESSED)
		)
	);
}


final class PackageType {
	const BOX_10 = 0;
	const BOX_20 = 1;
	const BOX = 2;
	const ENVELOPE = 3;
	const PAK = 4;
	const TUBE = 5;
	const USER_PACKAGING = 6;
}


final class UnitType {
	const METRIC = 0;
	const IMPERIAL = 1;
}


final class User {
	const EXISTING = 'log_in';
	const CREATE = 'sign_up';
	const GUEST = 'guest';
}


final class RecurringPayment {
	// interval units
	const DAY = 0;
	const WEEK = 1;
	const MONTH = 2;
	const YEAR = 3;

	// status
	const PENDING = 0;
	const ACTIVE = 1;
	const SKIPPED = 2;
	const FAILED = 3;
	const SUSPENDED = 4;
	const CANCELED = 5;
	const EXPIRED = 6;

	// status to signal
	public static $signals = array(
		self::PENDING => 'recurring-payment-pending',
		self::ACTIVE => 'recurring-payment',
		self::SKIPPED => 'recurring-payment-skipped',
		self::FAILED => 'recurring-payment-failed',
		self::SUSPENDED => 'recurring-payment-suspended',
		self::CANCELED => 'recurring-payment-canceled',
		self::EXPIRED => 'recurring-payment-expired'
	);
}


class CardType {
	const VISA = 0;
	const MASTERCARD = 1;
	const DISCOVER = 2;
	const AMERICAN_EXPRESS = 3;
	const MAESTRO = 4;

	public static $names = array(
		self::VISA => 'Visa',
		self::MASTERCARD => 'MasterCard',
		self::DISCOVER => 'Discover',
		self::AMERICAN_EXPRESS => 'American Express',
		self::MAESTRO => 'Maestro'
	);
}


class PaymentMethodError extends Exception {};


class shop extends Module {
	private static $_instance;
	private $payment_methods;
	private $checkout_scripts = array();
	private $checkout_styles = array();

	private $excluded_properties = array(
		'size_value', 'color_value', 'count'
	);

	private $search_params = array();

	const BUYER_SECRET = 'oz$9=7if~db/MP|BBN>)63T}6w{D6no[^79L]9>8(8wrv6:$/n63YsvCa<BR4379De1d035wvi]]iqA<P=3gHNv1H';

	/**
	 * Constructor
	 */
	protected function __construct() {
		global $section;

		parent::__construct(__FILE__);

		// create methods storage
		$this->payment_methods = array();

		// create events
		Events::register('shop', 'shopping-cart-changed');
		Events::register('shop', 'before-checkout');
		Events::register('shop', 'transaction-completed');
		Events::register('shop', 'transaction-canceled');

		// register recurring events
		foreach (RecurringPayment::$signals as $status => $signal_name)
			Events::register('shop', $signal_name);

		// connect to search module
		Events::connect('search', 'get-results', 'getSearchResults', $this);
		Events::connect('backend', 'user-create', 'handleUserCreate', $this);

		// register backend
		if (class_exists('backend') && $section == 'backend') {
			$head_tag = head_tag::getInstance();
			$backend = backend::getInstance();

			// include collection scripts
			if (class_exists('collection')) {
				$collection = collection::getInstance();
				$collection->includeScript(collection::PROPERTY_EDITOR);
			}

			// include local scripts
			if (class_exists('head_tag')) {
				$head_tag->addTag('script', array('src'=>url_GetFromFilePath($this->path.'include/multiple_images.js'), 'type'=>'text/javascript'));
				$head_tag->addTag('script', array('src'=>url_GetFromFilePath($this->path.'include/backend.js'), 'type'=>'text/javascript'));
				$head_tag->addTag('link', array('href'=>url_GetFromFilePath($this->path.'include/backend.css'), 'rel'=>'stylesheet', 'type'=>'text/css'));
			}

			$shop_menu = new backend_MenuItem(
				$this->getLanguageConstant('menu_shop'),
				url_GetFromFilePath($this->path.'images/icon.svg'),
				'javascript:void(0);',
				5  // level
			);

			$shop_menu->addChild(null, new backend_MenuItem(
				$this->getLanguageConstant('menu_items'),
				url_GetFromFilePath($this->path.'images/items.svg'),
				window_Open( // on click open window
					'shop_items',
					620,
					$this->getLanguageConstant('title_manage_items'),
					true, true,
					backend_UrlMake($this->name, 'items')
				),
				5  // level
			));

			$recurring_plans_menu = new backend_MenuItem(
				$this->getLanguageConstant('menu_recurring_plans'),
				url_GetFromFilePath($this->path.'images/recurring_plans.svg'),
				'javascript: void(0);', 5
			);
			$shop_menu->addChild('shop_recurring_plans', $recurring_plans_menu);

			$shop_menu->addSeparator(5);

			$shop_menu->addChild(null, new backend_MenuItem(
				$this->getLanguageConstant('menu_categories'),
				url_GetFromFilePath($this->path.'images/categories.svg'),
				window_Open( // on click open window
					'shop_categories',
					490,
					$this->getLanguageConstant('title_manage_categories'),
					true, true,
					backend_UrlMake($this->name, 'categories')
				),
				5  // level
			));

			$shop_menu->addChild(null, new backend_MenuItem(
				$this->getLanguageConstant('menu_item_sizes'),
				url_GetFromFilePath($this->path.'images/item_sizes.svg'),
				window_Open( // on click open window
					'shop_item_sizes',
					400,
					$this->getLanguageConstant('title_manage_item_sizes'),
					true, true,
					backend_UrlMake($this->name, 'sizes')
				),
				5  // level
			));

			$shop_menu->addChild(null, new backend_MenuItem(
				$this->getLanguageConstant('menu_manufacturers'),
				url_GetFromFilePath($this->path.'images/manufacturers.svg'),
				window_Open( // on click open window
					'shop_manufacturers',
					400,
					$this->getLanguageConstant('title_manufacturers'),
					true, true,
					backend_UrlMake($this->name, 'manufacturers')
				),
				5  // level
			));

			// delivery methods menu
			$delivery_menu = new backend_MenuItem(
				$this->getLanguageConstant('menu_delivery_methods'),
				url_GetFromFilePath($this->path.'images/delivery.svg'),
				'javascript: void(0);', 5
			);

			$shop_menu->addChild('shop_delivery_methods', $delivery_menu);

			$shop_menu->addSeparator(5);

			$shop_menu->addChild(null, new backend_MenuItem(
				$this->getLanguageConstant('menu_special_offers'),
				url_GetFromFilePath($this->path.'images/special_offers.svg'),
				window_Open( // on click open window
					'shop_special_offers',
					490,
					$this->getLanguageConstant('title_special_offers'),
					true, true,
					backend_UrlMake($this->name, 'special_offers')
				),
				5  // level
			));

			$shop_menu->addSeparator(5);

			// payment methods menu
			$methods_menu = new backend_MenuItem(
				$this->getLanguageConstant('menu_payment_methods'),
				url_GetFromFilePath($this->path.'images/payment_methods.svg'),
				'javascript: void(0);', 5
			);

			$shop_menu->addChild('shop_payment_methods', $methods_menu);

			$shop_menu->addChild(null, new backend_MenuItem(
				$this->getLanguageConstant('menu_currencies'),
				url_GetFromFilePath($this->path.'images/currencies.svg'),
				window_Open( // on click open window
					'shop_currencies',
					350,
					$this->getLanguageConstant('title_currencies'),
					true, true,
					backend_UrlMake($this->name, 'currencies')
				),
				5  // level
			));

			$shop_menu->addSeparator(5);

			$shop_menu->addChild(null, new backend_MenuItem(
				$this->getLanguageConstant('menu_transactions'),
				url_GetFromFilePath($this->path.'images/transactions.svg'),
				window_Open( // on click open window
					'shop_transactions',
					800,
					$this->getLanguageConstant('title_transactions'),
					true, true,
					backend_UrlMake($this->name, 'transactions')
				),
				5  // level
			));
			$shop_menu->addChild(null, new backend_MenuItem(
				$this->getLanguageConstant('menu_warehouses'),
				url_GetFromFilePath($this->path.'images/warehouse.svg'),
				window_Open( // on click open window
					'shop_warehouses',
					490,
					$this->getLanguageConstant('title_warehouses'),
					true, true,
					backend_UrlMake($this->name, 'warehouses')
				),
				5  // level
			));
			$shop_menu->addChild(null, new backend_MenuItem(
				$this->getLanguageConstant('menu_stocks'),
				url_GetFromFilePath($this->path.'images/stock.svg'),
				window_Open( // on click open window
					'shop_stocks',
					490,
					$this->getLanguageConstant('title_stocks'),
					true, true,
					backend_UrlMake($this->name, 'stocks')
				),
				5  // level
			));

			$shop_menu->addSeparator(5);
			$shop_menu->addChild('', new backend_MenuItem(
				$this->getLanguageConstant('menu_settings'),
				url_GetFromFilePath($this->path.'images/settings.svg'),

				window_Open( // on click open window
					'shop_settings',
					400,
					$this->getLanguageConstant('title_settings'),
					true, true,
					backend_UrlMake($this->name, 'settings')
				),
				$level=5
			));

			$backend->addMenu($this->name, $shop_menu);
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
	 * Get search results when asked by search module
	 *
	 * @param array $module_list
	 * @param string $query
	 * @param integer $threshold
	 * @return array
	 */
	public function getSearchResults($module_list, $query, $threshold) {
		global $language;

		// make sure shop is in list of modules requested
		if (!in_array($this->name, $module_list))
			return array();

		// don't bother searching for empty query string
		if (empty($query))
			return array();

		// initialize managers and data
		$manager = ShopItemManager::getInstance();
		$result = array();
		$conditions = array(
			'visible'	=> 1,
			'deleted'	=> 0,
		);
		$query = mb_strtolower($query);
		$query_words = mb_split("\s", $query);

		// include pre-configured options
		if (isset($this->search_params['category'])) {
			$membership_manager = ShopItemMembershipManager::getInstance();
			$category = $this->search_params['category'];
			$item_ids = array();

			if (!is_numeric($category)) {
				$category_manager = ShopCategoryManager::getInstance();
				$raw_category = $category_manager->getSingleItem(
					array('id'),
					array('text_id' => $category)
				);

				if (is_object($raw_category))
					$category = $raw_category->id; else
						$category = -1;
			}

			// get list of item ids
			$membership_list = $membership_manager->getItems(
				array('item'),
				array('category' => $category)
			);

			if (count($membership_list) > 0) {
				foreach($membership_list as $membership)
					$item_ids[] = $membership->item;

				$conditions['id'] = $item_ids;
			}
		}

		// get all items and process them
		$items = $manager->getItems(
			array(
				'id',
				'name',
				'description'
			),
			$conditions
		);

		// search through items
		if (count($items) > 0)
			foreach ($items as $item) {
				$title = mb_strtolower($item->name[$language]);
				$score = 0;

				foreach ($query_words as $query_word)
					if (is_numeric(mb_strpos($title, $query_word)))
						$score += 10;

				// add item to result list
				if ($score >= $threshold)
					$result[] = array(
						'score'			=> $score,
						'title'			=> $title,
						'description'	=> limit_words($item->description[$language], 200),
						'id'			=> $item->id,
						'type'			=> 'item',
						'module'		=> $this->name
					);
			}

		return $result;
	}

	/**
	 * Handle creating system user.
	 *
	 * @param object $user
	 */
	public function handleUserCreate($user) {
		$manager = ShopBuyersManager::getInstance();

		// get user data
		$data = array(
			'first_name'	=> $user->first_name,
			'last_name'		=> $user->last_name,
			'email'			=> $user->email,
			'guest'			=> 0,
			'system_user'	=> $user->id,
			'agreed'		=> $user->agreed
		);

		// create new buyer
		$manager->insertData($data);
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
			case 'show_item':
				$handler = ShopItemHandler::getInstance($this);
				$handler->tag_Item($params, $children);
				break;

			case 'show_item_list':
				$handler = ShopItemHandler::getInstance($this);
				$handler->tag_ItemList($params, $children);
				break;

			case 'show_category':
				$handler = ShopCategoryHandler::getInstance($this);
				$handler->tag_Category($params, $children);
				break;

			case 'show_category_list':
				$handler = ShopCategoryHandler::getInstance($this);
				$handler->tag_CategoryList($params, $children);
				break;

			case 'show_property_list':
				$handler = \Modules\Shop\Property\Handler::getInstance($this);
				$handler->tag_PropertyList($params, $children);
				break;

			case 'show_manufacturer':
				$handler = ShopManufacturerHandler::getInstance($this);
				$handler->tag_Manufacturer($params, $children);
				break;

			case 'show_manufacturer_list':
				$handler = ShopManufacturerHandler::getInstance($this);
				$handler->tag_ManufacturerList($params, $children);
				break;

			case 'show_completed_message':
				$this->tag_CompletedMessage($params, $children);
				break;

			case 'show_canceled_message':
				$this->tag_CanceledMessage($params, $children);
				break;

			case 'show_checkout_form':
				$this->tag_CheckoutForm($params, $children);
				break;

			case 'show_payment_methods':
				$this->tag_PaymentMethodsList($params, $children);
				break;

			case 'show_recurring_plan':
				$this->tag_RecurringPlan($params, $children);
				break;

			case 'show_transaction_list':
				$handler = ShopTransactionsHandler::getInstance($this);
				$handler->tag_TransactionList($params, $children);
				break;

			case 'configure_search':
				$this->configureSearch($params, $children);
				break;

			case 'checkout':
				$this->showCheckout();
				break;

			case 'checkout_completed':
				$this->showCheckoutCompleted();
				break;

			case 'checkout_canceled':
				$this->showCheckoutCanceled();
				break;

			case 'show_checkout_items':
				$this->tag_CheckoutItems($params, $children);
				break;

			case 'set_item_as_cart':
				$this->setItemAsCartFromParams($params, $children);
				break;

			case 'set_cart_from_template':
				$this->setCartFromTemplate($params, $children);
				break;

			case 'set_recurring_plan':
				$this->setRecurringPlan($params, $children);
				break;

			case 'cancel_recurring_plan':
				$this->cancelRecurringPlan($params, $children);
				break;

			case 'set_transaction_type':
				$this->setTransactionType($params, $children);
				break;

			case 'set_terms':
				$this->setTermsLink($params, $children);
				break;

			case 'include_scripts':
				$this->includeScripts();
				break;

			case 'include_cart_scripts':
				$this->includeCartScripts();
				break;

			case 'include_redirect_script':
				$this->includeRedirectScript();
				break;

			case 'json_get_item':
				$handler = ShopItemHandler::getInstance($this);
				$handler->json_GetItem();
				break;

			case 'json_get_currency':
				$this->json_GetCurrency();
				break;

			case 'json_get_conversion_rate':
				$this->json_GetConversionRate();
				break;

			case 'json_get_account_info':
				$this->json_GetAccountInfo();
				break;

			case 'json_get_account_exists':
				$this->json_GetAccountExists();
				break;

			case 'json_get_payment_methods':
				$this->json_GetPaymentMethods();
				break;

			case 'json_add_item_to_shopping_cart':
				$this->json_AddItemToCart();
				break;

			case 'json_remove_item_from_shopping_cart':
				$this->json_RemoveItemFromCart();
				break;

			case 'json_change_item_quantity':
				$this->json_ChangeItemQuantity();
				break;

			case 'json_clear_shopping_cart':
				$this->json_ClearCart();
				break;

			case 'json_get_shopping_cart':
				$this->json_ShowCart();
				break;

			case 'json_set_item_as_cart':
				$this->json_SetItemAsCart();
				break;

			case 'json_get_shopping_cart_summary':
				$this->json_GetShoppingCartSummary();
				break;

			case 'json_save_remark':
				$this->json_SaveRemark();
				break;

			case 'json_set_recurring_plan':
				$this->json_SetRecurringPlan();
				break;

			case 'json_set_delivery_method':
				$this->json_SetDeliveryMethod();
				break;

			case 'json_set_cart_from_transaction':
				$this->json_SetCartFromTransaction();
				break;

			case 'json_get_property':
				$handler = \Modules\Shop\Property\Handler::getInstance($this);
				$handler->json_GetProperty();
				break;

			case 'json_get_property_list':
				$handler = \Modules\Shop\Property\Handler::getInstance($this);
				$handler->json_GetPropertyList();
				break;

			default:
				break;
			}

		// global control actions
		if (isset($params['backend_action'])) {
			$action = $params['backend_action'];

			switch ($action) {
			case 'items':
				$handler = ShopItemHandler::getInstance($this);
				$handler->transferControl($params, $children);
				break;

			case 'currencies':
				$handler = ShopCurrenciesHandler::getInstance($this);
				$handler->transferControl($params, $children);
				break;

			case 'categories':
				$handler = ShopCategoryHandler::getInstance($this);
				$handler->transferControl($params, $children);
				break;

			case 'sizes':
				$handler = ShopItemSizesHandler::getInstance($this);
				$handler->transferControl($params, $children);
				break;

			case 'transactions':
				$handler = ShopTransactionsHandler::getInstance($this);
				$handler->transferControl($params, $children);
				break;

			case 'manufacturers':
				$handler = ShopManufacturerHandler::getInstance($this);
				$handler->transferControl($params, $children);
				break;

			case 'special_offers':
				break;

			case 'warehouses':
				$handler = ShopWarehouseHandler::getInstance($this);
				$handler->transferControl($params, $children);
				break;

			case 'stocks':
				break;

			case 'settings':
				$this->showSettings();
				break;

			case 'settings_save':
				$this->saveSettings();
				break;

			default:
				break;
			}
		}
	}

	/**
	 * Event triggered upon module initialization
	 */
	public function onInit() {
		global $db;

		$list = Language::getLanguages(false);

		// set shop in testing mode by default
		$this->saveSetting('testing_mode', 1);

		// create shop items table
		$sql = "
			CREATE TABLE `shop_items` (
				`id` int NOT NULL AUTO_INCREMENT,
				`uid` VARCHAR(13) NOT NULL,";

		foreach($list as $language)
			$sql .= "`name_{$language}` VARCHAR( 255 ) NOT NULL DEFAULT '',";

		foreach($list as $language)
			$sql .= "`description_{$language}` TEXT NOT NULL ,";

		$sql .= "
				`gallery` INT NOT NULL,
				`manufacturer` INT NOT NULL,
				`size_definition` INT NULL,
				`colors` VARCHAR(255) NOT NULL DEFAULT '',
				`author` INT NOT NULL,
				`views` INT NOT NULL,
				`price` DECIMAL(10,2) NOT NULL,
				`tax` DECIMAL(5,2) NOT NULL,
				`weight` DECIMAL(10,4) NOT NULL,
				`votes_up` INT NOT NULL,
				`votes_down` INT NOT NULL,
				`timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`priority` INT(4) NOT NULL DEFAULT '5',
				`visible` BOOLEAN NOT NULL DEFAULT '1',
				`deleted` BOOLEAN NOT NULL DEFAULT '0',
				PRIMARY KEY ( `id` ),
				KEY `visible` (`visible`),
				KEY `deleted` (`deleted`),
				KEY `uid` (`uid`),
				KEY `author` (`author`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop item membership table
		$sql = "
			CREATE TABLE `shop_item_membership` (
				`category` INT NOT NULL,
				`item` INT NOT NULL,
				KEY `category` (`category`),
				KEY `item` (`item`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop item properties table
		$sql = "
			CREATE TABLE `shop_item_properties` (
				`id` INT NOT NULL AUTO_INCREMENT,
				`item` INT NOT NULL,
				`text_id` VARCHAR(32) NOT NULL,
				`type` VARCHAR(32) NOT NULL";

		foreach($list as $language)
			$sql .= "`name_{$language}` VARCHAR(255) NOT NULL DEFAULT '',";

		$sql .= "
				`value` TEXT NOT NULL,
				PRIMARY KEY ( `id` ),
				KEY `item` (`item`),
				KEY `text_id` (`text_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create table for related shop items
		$sql = "
			CREATE TABLE IF NOT EXISTS `shop_related_items` (
				`item` INT NOT NULL,
				`related` INT NOT NULL,
				KEY `item` (`item`,`related`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin;";
		$db->query($sql);

		// create shop currencies tableshop_related_items
		$sql = "
			CREATE TABLE `shop_currencies` (
				`id` INT NOT NULL AUTO_INCREMENT,
				`currency` VARCHAR(5) NOT NULL,
				PRIMARY KEY ( `id` )
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop item sizes table
		$sql = "
			CREATE TABLE `shop_item_sizes` (
				`id` INT NOT NULL AUTO_INCREMENT,
				`name` VARCHAR(25) NOT NULL,
				PRIMARY KEY ( `id` )
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop item size values table
		$sql = "
			CREATE TABLE `shop_item_size_values` (
				`id` INT NOT NULL AUTO_INCREMENT,
				`definition` INT NOT NULL,";

		foreach($list as $language)
			$sql .= "`value_{$language}` VARCHAR( 50 ) NOT NULL DEFAULT '',";

		$sql .= "PRIMARY KEY ( `id` ),
			KEY `definition` (`definition`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop categories table
		$sql = "
			CREATE TABLE `shop_categories` (
				`id` INT NOT NULL AUTO_INCREMENT,
				`text_id` VARCHAR(32) NOT NULL,
				`parent` INT NOT NULL DEFAULT '0',
				`image` INT NULL,";

		foreach($list as $language)
			$sql .= "`title_{$language}` VARCHAR( 255 ) NOT NULL DEFAULT '',";

		foreach($list as $language)
			$sql .= "`description_{$language}` TEXT NOT NULL ,";

		$sql .="
				PRIMARY KEY ( `id` ),
				KEY `parent` (`parent`),
				KEY `text_id` (`text_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop buyers table
		$sql = "CREATE TABLE IF NOT EXISTS `shop_buyers` (
			`id` INT NOT NULL AUTO_INCREMENT,
			`first_name` varchar(64) NOT NULL,
			`last_name` varchar(64) NOT NULL,
			`email` varchar(127) NOT NULL,
			`guest` boolean NOT NULL DEFAULT '0',
			`system_user` int NULL,
			`agreed` boolean NOT NULL DEFAULT '0',
			`promotions` boolean NOT NULL DEFAULT '0',
			`uid` varchar(50) NOT NULL,
			PRIMARY KEY (`id`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop buyer addresses table
		$sql = "CREATE TABLE IF NOT EXISTS `shop_delivery_address` (
			`id` INT NOT NULL AUTO_INCREMENT,
			`buyer` INT NOT NULL,
			`name` varchar(128) NOT NULL,
			`street` varchar(200) NOT NULL,
			`street2` varchar(200) NOT NULL,
			`phone` varchar(200) NOT NULL,
			`city` varchar(40) NOT NULL,
			`zip` varchar(20) NOT NULL,
			`state` varchar(40) NOT NULL,
			`country` varchar(64) NOT NULL,
			`access_code` varchar(100) NOT NULL,
			PRIMARY KEY (`id`),
				  KEY `buyer` (`buyer`)
			  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop transactions table
		$sql = "CREATE TABLE IF NOT EXISTS `shop_transactions` (
			`id` INT NOT NULL AUTO_INCREMENT,
			`buyer` INT NOT NULL,
			`address` INT NOT NULL,
			`uid` varchar(30) NOT NULL,
			`type` smallint(6) NOT NULL,
			`status` smallint(6) NOT NULL,
			`currency` INT NOT NULL,
			`handling` decimal(8,2) NOT NULL,
			`shipping` decimal(8,2) NOT NULL,
			`weight` decimal(4,2) NOT NULL,
			`payment_method` varchar(255) NOT NULL,
			`payment_token` int NOT NULL DEFAULT '0',
			`delivery_method` varchar(255) NOT NULL,
			`delivery_type` varchar(255) NOT NULL,
			`remark` text NOT NULL,
			`remote_id` varchar(255) NOT NULL,
			`total` decimal(8,2) NOT NULL,
			`timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `buyer` (`buyer`),
			KEY `address` (`address`)
			  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop transaction items table
		$sql = "CREATE TABLE IF NOT EXISTS `shop_transaction_items` (
			`id` int NOT NULL AUTO_INCREMENT,
			`transaction` int NOT NULL,
			`item` int NOT NULL,
			`price` decimal(8,2) NOT NULL,
			`tax` decimal(8,2) NOT NULL,
			`amount` int NOT NULL,
			`description` varchar(500) NOT NULL,
			PRIMARY KEY (`id`),
			KEY `transaction` (`transaction`),
			KEY `item` (`item`)
			  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop payment tokens table
		$sql = "CREATE TABLE IF NOT EXISTS `shop_payment_tokens` (
			`id` int NOT NULL AUTO_INCREMENT,
			`payment_method` varchar(64) NOT NULL,
			`buyer` int NOT NULL,
			`name` varchar(50) NOT NULL,
			`token` varchar(200) NOT NULL,
			`expires` boolean NOT NULL DEFAULT '0',
			`expiration_month` int NOT NULL,
			`expiration_year` int NOT NULL,
			PRIMARY KEY (`id`),
			KEY `index_by_name` (`payment_method`, `buyer`, `name`),
			KEY `index_by_buyer` (`payment_method`, `buyer`)
			  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop transaction plans table
		$sql = "CREATE TABLE IF NOT EXISTS `shop_transaction_plans` (
			`id` int NOT NULL AUTO_INCREMENT,
			`transaction` int NOT NULL,
			`plan_name` varchar(64) NOT NULL,
			`trial` int NOT NULL,
			`trial_count` int NOT NULL,
			`interval` int NOT NULL,
			`interval_count` int NOT NULL,
			`start_time` timestamp NULL,
			`end_time` timestamp NULL,
			PRIMARY KEY (`id`),
				  KEY `transaction` (`transaction`),
				  KEY `plan_name` (`plan_name`)
			  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create show recurring payments table
		$sql = "CREATE TABLE IF NOT EXISTS `shop_recurring_payments` (
			`id` INT NOT NULL AUTO_INCREMENT,
			`plan` INT NOT NULL,
			`amount` DECIMAL(8,2) NOT NULL,
			`status` INT NOT NULL,
			`timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
				  KEY `index_by_plan` (`plan`)
			  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop stock table
		$sql = "CREATE TABLE IF NOT EXISTS `shop_warehouse` (
			`id` int NOT NULL AUTO_INCREMENT,
			`name` varchar(60) NOT NULL,
			`street` varchar(200) NOT NULL,
			`street2` varchar(200) NOT NULL,
			`city` varchar(40) NOT NULL,
			`zip` varchar(20) NOT NULL,
			`country` varchar(64) NOT NULL,
			`state` varchar(40) NOT NULL,
			PRIMARY KEY (`id`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop stock table
		$sql = "CREATE TABLE IF NOT EXISTS `shop_stock` (
			`id` int NOT NULL AUTO_INCREMENT,
			`item` int NOT NULL,
			`size` int DEFAULT NULL,
			`amount` int NOT NULL,
			PRIMARY KEY (`id`),
				  KEY `item` (`item`)
			  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create shop manufacturers table
		$sql = "CREATE TABLE IF NOT EXISTS `shop_manufacturers` (
			`id` int NOT NULL AUTO_INCREMENT,";

		foreach($list as $language)
			$sql .= "`name_{$language}` VARCHAR(255) NOT NULL DEFAULT '',";

		$sql .= " `web_site` varchar(255) NOT NULL,
			`logo` int NOT NULL,
			PRIMARY KEY (`id`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);
	}

	/**
	 * Event triggered upon module deinitialization
	 */
	public function onDisable() {
		global $db;

		$tables = array(
			'shop_items',
			'shop_currencies',
			'shop_categories',
			'shop_item_membership',
			'shop_item_sizes',
			'shop_item_size_values',
			'shop_buyers',
			'shop_delivery_address',
			'shop_transactions',
			'shop_transaction_items',
			'shop_transaction_plans',
			'shop_recurring_payments',
			'shop_warehouse',
			'shop_stock',
			'shop_related_items',
			'shop_manufacturers',
			'shop_payment_tokens',
			'shop_item_properties'
		);

		$db->drop_tables($tables);
	}

	/**
	 * Method used by payment providers to register with main module.
	 *
	 * @param string $name
	 * @param object $module
	 */
	public function registerPaymentMethod($name, &$module) {
		if (!array_key_exists($name, $this->payment_methods))
			$this->payment_methods[$name] = $module; else
			throw new Exception("Payment method '{$name}' is already registered with the system.");
	}

	/**
	 * Add script to be included with other checkout scripts.
	 *
	 * @param string $url
	 */
	public function addCheckoutScript($url) {
		if (!in_array($url, $this->checkout_scripts))
			$this->checkout_scripts[] = $url;
	}

	/**
	 * Add checkout style to be included with other checkout styles.
	 *
	 * @param string $url
	 */
	public function addCheckoutStyle($url) {
		if (!in_array($url, $this->checkout_styles))
			$this->checkout_styles[] = $url;
	}

	/**
	 * Include buyer information and checkout form scripts.
	 */
	public function includeScripts() {
		if (!class_exists('head_tag') || !class_exists('collection'))
			return;

		$head_tag = head_tag::getInstance();
		$collection = collection::getInstance();
		$css_file = _DESKTOP_VERSION ? 'checkout.css' : 'checkout_mobile.css';

		$collection->includeScript(collection::DIALOG);
		$collection->includeScript(collection::PAGE_CONTROL);
		$collection->includeScript(collection::COMMUNICATOR);
		$head_tag->addTag('link', array('href'=>url_GetFromFilePath($this->path.'include/'.$css_file), 'rel'=>'stylesheet', 'type'=>'text/css'));
		$head_tag->addTag('script', array('src'=>url_GetFromFilePath($this->path.'include/checkout.js'), 'type'=>'text/javascript'));

		// add custom scripts
		if (count($this->checkout_scripts) > 0)
			foreach ($this->checkout_scripts as $script_url)
				$head_tag->addTag('script', array( 'src' => $script_url, 'type' => 'text/javascript'));

		// add custom styles
		if (count($this->checkout_styles) > 0)
			foreach ($this->checkout_styles as $style_url)
				$head_tag->addTag('link', array('href' => $style_url, 'rel' => 'stylesheet', 'type' => 'text/css'));
	}

	/**
	 * Include shopping cart scripts.
	 */
	public function includeCartScripts() {
		if (!class_exists('head_tag') || !class_exists('collection'))
			return;

		$head_tag = head_tag::getInstance();
		$collection = collection::getInstance();

		$collection->includeScript(collection::COMMUNICATOR);
		$head_tag->addTag('script', array('src' => url_GetFromFilePath($this->path.'include/cart.js'), 'type'=>'text/javascript'));
	}

	/**
 	 * Include script that makes sure page is not running in iframe.
	 */
	public function includeRedirectScript() {
		if (!class_exists('head_tag'))
			return;

		$head_tag = head_tag::getInstance();
		$head_tag->addTag('script', array('src' => url_GetFromFilePath($this->path.'include/redirect.js'), 'type'=>'text/javascript'));
	}

	/**
	 * Show shop configuration form
	 */
	private function showSettings() {
		$template = new TemplateHandler('settings.xml', $this->path.'templates/');
		$template->setMappedModule($this->name);

		$params = array(
			'form_action'	=> backend_UrlMake($this->name, 'settings_save'),
			'cancel_action'	=> window_Close('shop_settings')
		);

		if (class_exists('contact_form')) {
			$contact_form = contact_form::getInstance();
			$template->registerTagHandler('cms:template_list', $contact_form, 'tag_TemplateList');
		}

		$template->restoreXML();
		$template->setLocalParams($params);
		$template->parse();
	}

	/**
	 * Save settings
	 */
	private function saveSettings() {
		// save new settings
		$regular_template = fix_chars($_REQUEST['regular_template']);
		$recurring_template = fix_chars($_REQUEST['recurring_template']);
		$delayed_template = fix_chars($_REQUEST['delayed_template']);
		$shop_location = fix_chars($_REQUEST['shop_location']);
		$fixed_country = fix_chars($_REQUEST['fixed_country']);
		$testing_mode = fix_id($_REQUEST['testing_mode']);

		$this->saveSetting('regular_template', $regular_template);
		$this->saveSetting('recurring_template', $recurring_template);
		$this->saveSetting('delayed_template', $delayed_template);
		$this->saveSetting('shop_location', $shop_location);
		$this->saveSetting('fixed_country', $fixed_country);
		$this->saveSetting('testing_mode', $testing_mode);

		// show message
		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->setMappedModule($this->name);

		$params = array(
			'message'	=> $this->getLanguageConstant('message_settings_saved'),
			'button'	=> $this->getLanguageConstant('close'),
			'action'	=> window_Close('shop_settings')
		);

		$template->restoreXML();
		$template->setLocalParams($params);
		$template->parse();
	}

	/**
	 * Generate variation Id based on UID and properties.
	 *
	 * @param string $uid
	 * @param array $properties
	 * @return string
	 */
	public function generateVariationId($uid, $properties=array()) {
		$data = $uid;

		ksort($properties);
		foreach($properties as $key => $value)
			$data .= ",{$key}:{$value}";

		$result = md5($data);
		return $result;
	}

	/**
	 * Set item as cart content from provided template params.
	 *
	 * @param array $params
	 * @param array $children
	 */
	private function setItemAsCartFromParams($params, $children) {
		$uid = isset($params['uid']) ? fix_chars($params['uid']) : null;
		$count = isset($params['count']) ? fix_id($params['count']) : 1;
		$variation_id = isset($params['variation_id']) ? fix_chars($params['variation_id']) : null;

		// set cart content
		$this->setItemAsCart($uid, $count, $variation_id);
	}

	/**
	 * Set shopping cart to contain only one item.
	 *
	 * @param string $uid
	 * @param integer $count
	 * @param string $variation_id
	 * @return boolean
	 */
	private function setItemAsCart($uid, $count, $variation_id=null) {
		$cart = array();
		$result = false;
		$manager = ShopItemManager::getInstance();

		// make sure we have variation id
		if (is_null($variation_id))
			$variation_id = $this->generateVariationId($uid, array());

		// check if item exists in database to avoid poluting shopping cart
		$item = $manager->getSingleItem(array('id'), array('uid' => $uid));

		// make new content of shopping cart
		if (is_object($item) && $count > 0) {
			$cart[$uid] = array(
				'uid'			=> $uid,
				'quantity'		=> $count,
				'variations'	=> array()
			);
			$cart[$uid]['variations'][$variation_id] = array('count' => $count);
			$result = true;
		}

		// assign new cart
		$_SESSION['shopping_cart'] = $cart;

		// notify all the listeners about change
		Events::trigger('shop', 'shopping-cart-changed');

		return $result;
	}

	/**
	 * Set content of a shopping cart from template
	 *
	 * @param
	 */
	private function setCartFromTemplate($params, $children) {
		if (count($children) > 0) {
			$cart = array();
			$manager = ShopItemManager::getInstance();

			foreach ($children as $data) {
				$uid = array_key_exists('uid', $data->tagAttrs) ? fix_chars($data->tagAttrs['uid']) : null;
				$amount = array_key_exists('count', $data->tagAttrs) ? fix_id($data->tagAttrs['count']) : 0;
				$properties = isset($data->tagAttrs['properties']) ? fix_chars($data->tagAttrs['properties']) : array();
				$variation_id = $this->generateVariationId($uid, $properties);
				$item = null;

				if (!is_null($uid))
					$item = $manager->getSingleItem(array('id'), array('uid' => $uid));

				// make sure item actually exists in database to avoid poluting
				if (is_object($item) && $amount > 0) {
					$cart[$uid] = array(
						'uid'			=> $uid,
						'quantity'		=> $amount,
						'variations'	=> array()
					);
					$cart[$uid]['variations'][$variation_id] = array('count' => $amount);
				}
			}

			$_SESSION['shopping_cart'] = $cart;
			Events::trigger('shop', 'shopping-cart-changed');
		}
	}

	/**
	 * Set recurring plan to be activated.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	private function setRecurringPlan($tag_params, $children) {
		$recurring_plan = fix_chars($tag_params['text_id']);
		$_SESSION['recurring_plan'] = $recurring_plan;
	}

	/**
	 * Cancel recurring payment plan for specified user or transaction.
	 * If not provided system will try to find information for currently
	 * logged user.
	 *
	 * @param array $tag_params
	 * @param array $children
	 * @return boolean
	 */
	private function cancelRecurringPlan($tag_params, $children) {
		$result = false;
		$user_id = null;
		$transaction_id = null;

		$transaction_manager = ShopTransactionsManager::getInstance();

		// try to get user id
		if (isset($tag_params['user']))
			$user_id = fix_id($tag_params['user']);

		if (is_null($user_id) && $_SESSION['logged'])
			$user_id = $_SESSION['uid'];

		// try to get transaction id
		if (isset($tag_params['transaction']))
			$transaction_id = fix_chars($tag_params['transaction']);

		if (is_null($transaction_id) && !is_null($user_id)) {
			$transaction = $transaction_manager->getSingleItem(
				array('id'),
				array('system_user' => $user_id)
			);

			if (is_object($transaction))
				$transaction_id = $transaction->id;
		}

		// cancel recurring plan
		if (!is_null($transaction_id))
			$this->cancelTransaction($transaction_id);

		return $result;
	}

	/**
	 * Set current transaction type.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	private function setTransactionType($tag_params, $children) {
		$type = TransactionType::REGULAR;
		if (isset($tag_params['type']) && array_key_exists($tag_params['type'], TransactionType::$reverse))
			$type = fix_id($tag_params['type']);

		$_SESSION['transaction_type'] = $type;
	}

	/**
	 * Set terms of use link to be displayed in the shop checkout
	 * page. If link is not specified, no checkbox will appear on checkout.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	private function setTermsLink($tag_params, $children) {
		if (isset($tag_params['link']))
			$_SESSION['buyer_terms_link'] = $tag_params['link'];
	}

	/**
	 * Get transaction type.
	 *
	 * @return integer
	 */
	public function getTransactionType() {
		return isset($_SESSION['transaction_type']) ? $_SESSION['transaction_type'] : TransactionType::REGULAR;
	}

	/**
	 * Get recurring payment plan associated with specified user. If no
	 * user id is specified system will try to find payment plan associated
	 * with currently logged in user.
	 *
	 * @param integer $user_id
	 * @return object
	 */
	public function getRecurringPlan($user_id=null) {
		$result = null;

		// get managers
		$transaction_manager = ShopTransactionsManager::getInstance();
		$plan_manager = ShopTransactionPlansManager::getInstance();
		$recurring_manager = ShopRecurringPaymentsManager::getInstance();

		// try to get currently logged user
		if (is_null($user_id) && $_SESSION['logged'])
			$user_id = $_SESSION['uid'];

		// we need to have a user
		if (is_null($user_id))
			return $result;

		// get all recurring payment transactions for current buyer
		$transaction = $transaction_manager->getSingleItem(
			array('id'),
			array(
				'type'			=> TransactionType::SUBSCRIPTION,
				'status'		=> TransactionStatus::COMPLETED,
				'system_user'	=> $user_id
			),
			array('timestamp'),
			false  // ascending
		);

		// user doesn't have a recurring payment
		if (!is_object($transaction))
			return $result;

		$plan = $plan_manager->getSingleItem(
			$plan_manager->getFieldNames(),
			array('transaction' => $transaction->id)
		);

		// get last payment
		$last_payment = $recurring_manager->getSingleItem(
			$recurring_manager->getFieldNames(),
			array('plan' => $plan->id),
			array('timestamp'),
			false  // ascending
		);

		if (is_object($last_payment) && $last_payment->status <= RecurringPayment::ACTIVE)
			$result = $plan;

		return $result;
	}

	/**
	 * Set transaction status.
	 *
	 * @param string $transaction_id
	 * @param string $status
	 * @return boolean
	 */
	public function setTransactionStatus($transaction_id, $status) {
		$result = false;
		$manager = ShopTransactionsManager::getInstance();

		// try to get transaction with specified id
		$transaction = $manager->getSingleItem(
			$manager->getFieldNames(),
			array('uid' => $transaction_id)
		);

		// set status of transaction
		if (is_object($transaction)) {
			$manager->updateData(
				array('status' => $status),
				array('id' => $transaction->id)
			);
			$result = true;

			// get template based on transaction type
			switch ($transaction->type) {
				case TransactionType::SUBSCRIPTION:
					$template_name = $this->settings['recurring_template'];
					break;

				case TransactionType::DELAYED:
					$template_name = $this->settings['delayed_template'];
					break;

				case TransactionType::REGULAR:
				default:
					$template_name = $this->settings['regular_template'];
					break;
			}

			// whether we should send email notification
			$send_email = true;

			// trigger event
			switch ($status) {
				case TransactionStatus::COMPLETED:
					Events::trigger('shop', 'transaction-completed', $transaction);
					unset($_SESSION['transaction']);
					break;

				case TransactionStatus::PROCESSED:
					// get payment method
					if (!array_key_exists($transaction->payment_method, $this->payment_methods)) {
						trigger_error('Unable to update transaction status. Missing payment method!', E_USER_NOTICE);
						break;
					}

					// charge transaction
					$payment_method = $this->payment_methods[$transaction->payment_method];
					$payment_method->charge_transaction($transaction);

					// we don't send emails for delayed transactions
					$send_email = $transaction->type != TransactionType::DELAYED;
					break;

				case TransactionStatus::CANCELED:
					Events::trigger('shop', 'transaction-canceled', $transaction);
					break;

				case TransactionStatus::UNKNOWN:
				case TransactionStatus::PENDING:
					// we don't send emails for delayed transactions
					$send_email = $transaction->type != TransactionType::DELAYED;
					break;
			}

			// send notification email
			if ($send_email)
				$this->sendTransactionMail($transaction, $template_name);
		}

		return $result;
	}

	/**
	 * Cancel specified transaction.
	 *
	 * @param integer $id
	 * @param string $uid
	 * @param string $token
	 * @return boolean
	 */
	public function cancelTransaction($id=null, $uid=null, $token=null) {
		$result = false;
		$conditions = array();

		// we should always have at least one identifying method
		if (is_null($id) && is_null($uid) && is_null($token)) {
			trigger_error('Shop: Unable to cancel transaction, no id provided.', E_USER_WARNING);
			return $result;
		}

		// prepare conditions for manager
		if (!is_null($id))
			$conditions['id'] = $id;

		if (!is_null($uid))
			$conditions['uid'] = $uid;

		if (!is_null($token))
			$conditions['token'] = $token;

		// get transaction
		$manager = ShopTransactionsManager::getInstance();
		$transaction = $manager->getSingleItem($manager->getFieldNames(), $conditions);

		// cancel transaction
		if (is_object($transaction) && array_key_exists($transaction->payment_method, $this->payment_methods)) {
			// get payment method and initate cancelation process
			$payment_method = $this->payment_methods[$transaction->payment_method];

			if ($transaction->type == TransactionType::SUBSCRIPTION)
				$result = $payment_method->cancel_recurring_payment($transaction);

		} else {
			// unknown method or transaction, log error
			trigger_error('Shop: Unknown payment method or transaction. Unable to cancel recurring payment.', E_USER_WARNING);
		}
	}

	/**
	 * Add recurring payment for specified plan.
	 * Returns true if new recurring payment was added for
	 * specified transaction.
	 *
	 * @param integer $plan_id
	 * @param float $amount
	 * @param integer $status
	 * @return boolean
	 */
	public function addRecurringPayment($plan_id, $amount, $status) {
		$result = false;

		// get managers
		$manager = ShopRecurringPaymentsManager::getInstance();
		$plan_manager = ShopTransactionPlansManager::getInstance();
		$buyer_manager = ShopBuyersManager::getInstance();
		$transaction_manager = ShopTransactionsManager::getInstance();

		// get transaction and associated plan
		$plan = $plan_manager->getSingleItem(
			$plan_manager->getFieldNames(),
			array('id' => $plan_id)
		);

		// plan id is not valid
		if (!is_object($plan))
			return $result;

		// insert new data
		$data = array(
			'plan'		=> $plan->id,
			'amount'	=> $amount,
			'status'	=> $status
		);

		$manager->insertData($data);
		$payment_id = $manager->getInsertedID();
		$result = true;

		// get newly inserted data
		$payment = $manager->getSingleItem(
			$manager->getFieldNames(),
			array('id' => $payment_id)
		);

		// get transaction and buyer
		$transaction = $transaction_manager->getSingleItem(
			$transaction_manager->getFieldNames(),
			array('id' => $plan->transaction)
		);

		// trigger event
		Events::trigger('shop', RecurringPayment::$signals[$status], $transaction, $plan, $payment);

		return $result;
	}

	/**
	 * Pre-configure search parameters
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	private function configureSearch($tag_params, $children) {
		$this->search_params = $tag_params;
	}

	/**
	 * Show checkout form
	 */
	public function showCheckout() {
		if (count($this->payment_methods) == 0)
			return;

		$template = new TemplateHandler('checkout.xml', $this->path.'templates/');
		$template->setMappedModule($this->name);

		$params = array();

		// register tag handler
		$template->registerTagHandler('cms:checkout_form', $this, 'tag_CheckoutForm');

		$template->restoreXML();
		$template->setLocalParams($params);
		$template->parse();
	}

	/**
	 * Show message for completed checkout and empty shopping cart
	 */
	private function showCheckoutCompleted() {
		$template = new TemplateHandler('checkout_completed.xml', $this->path.'templates/');
		$template->setMappedModule($this->name);
		$template->registerTagHandler('cms:completed_message', $this, 'tag_CompletedMessage');

		$template->restoreXML();
		$template->parse();
	}

	/**
	 * Show message before user gets redirected.
	 */
	private function showCheckoutRedirect() {
		$template = new TemplateHandler('checkout_message.xml', $this->path.'templates/');
		$template->setMappedModule($this->name);

		$params = array(
			'message'		=> $this->getLanguageConstant('message_checkout_redirect'),
			'button_text'	=> $this->getLanguageConstant('button_take_me_back'),
			'button_action'	=> url_Make('', 'home'),
			'redirect'		=> true
		);

		$template->restoreXML();
		$template->setLocalParams($params);
		$template->parse();
	}

	/**
	 * Show message for canceled checkout
	 */
	private function showCheckoutCanceled() {
		$template = new TemplateHandler('checkout_canceled.xml', $this->path.'templates/');
		$template->setMappedModule($this->name);
		$template->registerTagHandler('cms:canceled_message', $this, 'tag_CanceledMessage');

		$template->restoreXML();
		$template->parse();
	}

	/**
	 * Return default currency using JSON object
	 */
	private function json_GetCurrency() {
		print json_encode($this->getDefaultCurrency());
	}

	/**
	 * Return conversion rate from two currencies.
	 */
	private function json_GetConversionRate() {
		$from = fix_chars($_REQUEST['from']);
		$to = fix_chars($_REQUEST['to']);
		$rate = 0;

		$url = "http://rate-exchange.appspot.com/currency?from=$from&to=$to";
		$data = json_decode(file_get_contents($url));
		$rate = $data->rate;

		print json_encode($rate);
	}

	/**
	 * Set recurring plan.
	 */
	public function json_SetRecurringPlan() {
		$recurring_plan = fix_chars($_REQUEST['plan']);
		$_SESSION['recurring_plan'] = $recurring_plan;
	}

	/**
	 * Set delivery method and return updated information about cart totals.
	 */
	private function json_SetDeliveryMethod() {
		$result = array(
				'error'		=> false,
				'message'	=> '',
				'delivery_prices'	=> array()
			);
		$method = isset($_REQUEST['method']) ? escape_chars($_REQUEST['method']) : null;
		$type = isset($_REQUEST['type']) ? escape_chars($_REQUEST['type']) : null;

		if (!is_null($method))
			Delivery::set_method($method, $type);

		// get current transaction
		$transaction = Transaction::get_current();

		if (is_null($transaction)) {
			$result['error'] = true;
			$result['message'] = $this->getLanguageConstant('message_error_transaction');
			print json_encode($result);
			return;
		}

		// get prefered method
		$delivery_method = Delivery::get_current();

		if (is_null($delivery_method)) {
			$result['error'] = true;
			$result['message'] = $this->getLanguageConstant('message_error_delivery_method');
			print json_encode($result);
			return;
		}

		// get cart summary
		$result = $this->getCartSummary(
						$transaction->uid,
						$transaction->type,
						$this->payment_methods[$transaction->payment_method]
					);
		unset($result['items_for_checkout']);

		// add language constants
		$result['label_no_estimate'] = $this->getLanguageConstant('label_no_estimate');
		$result['label_estimated_time'] = $this->getLanguageConstant('label_estimated_time');

		// add delivery method related values
		$result['delivery_method'] = Delivery::get_current_name();
		$result['delivery_type'] = Delivery::get_current_type();

		// TODO: Instead of picking up the first warehouse we need to choose proper one based on item property.
		$warehouse_manager = ShopWarehouseManager::getInstance();
		$warehouse = $warehouse_manager->getSingleItem($warehouse_manager->getFieldNames(), array());
		$address = Transaction::get_address();

		if (is_object($warehouse) && is_object($address)) {
			$shipper = array(
				'street'	=> array($warehouse->street, $warehouse->street2),
				'city'		=> $warehouse->city,
				'zip_code'	=> $warehouse->zip,
				'state'		=> $warehouse->state,
				'country'	=> $warehouse->country
			);

			$recipient = array(
				'street'	=> array($address->street, $address->street2),
				'city'		=> $address->city,
				'zip_code'	=> $address->zip,
				'state'		=> $address->state,
				'country'	=> $address->country
			);

			// get types and prices from delivery method provider
			$delivery_prices = $delivery_method->getDeliveryTypes(
				Delivery::get_items_for_estimate(),
				$shipper,
				$recipient,
				$transaction->uid,
				$transaction->currency
			);

			// add formated dates to result
			$date_format = $this->getLanguageConstant('format_date_short');
			$time_format = $this->getLanguageConstant('format_time_short');

			if (count($delivery_prices) > 0)
				foreach ($delivery_prices as $key => $delivery_data) {
					if ($delivery_data[3] != null)
						$start_date = date($date_format.' '.$time_format, $delivery_data[3]); else
						$start_date = '';

					if ($delivery_data[4] != null)
						$end_date = date($date_format.' '.$time_format, $delivery_data[4]); else
						$end_date = '';

					$delivery_prices[$key][] = $start_date;
					$delivery_prices[$key][] = $end_date;
				}

			// assign delivery intervals to result
			$result['delivery_prices'] = $delivery_prices;

			// convert prices and format timestamps
			$date_format = Language::getText('format_date');

			if (count($delivery_prices) > 0)
				foreach ($delivery_prices as $name => $delivery) {
					// format starting date
					if (!is_null($delivery[3]))
						$delivery[3] = date($date_format, $delivery[3]);

					// format ending date
					if (!is_null($delivery[4]))
						$delivery[4] = date($date_format, $delivery[4]);
				}
		} else {
			trigger_error('Shop: No warehouse defined!', E_USER_NOTICE);
		}

		print json_encode($result);
	}

	/**
	 * Return user information if email and password are correct.
	 */
	private function json_GetAccountInfo() {
		// get managers
		$buyer_manager = ShopBuyersManager::getInstance();
		$delivery_address_manager = ShopDeliveryAddressManager::getInstance();
		$transaction_manager = ShopTransactionsManager::getInstance();

		// get buyer from specified email
		if ($_SESSION['logged'])
			$buyer = $buyer_manager->getSingleItem(
				$buyer_manager->getFieldNames(),
				array(
					'guest'			=> 0,
					'system_user'	=> $_SESSION['uid']
				)
			);

		if (is_object($buyer)) {
			$result = array(
				'information'			=> array(),
				'delivery_addresses'	=> array(),
				'last_payment_method'	=> '',
				'last_delivery_method'	=> ''
			);

			// populate user information
			$result['information'] = array(
				'first_name'	=> $buyer->first_name,
				'last_name'		=> $buyer->last_name,
				'email'			=> $buyer->email,
				'uid'			=> $buyer->uid
			);

			// populate delivery addresses
			$address_list = $delivery_address_manager->getItems(
				$delivery_address_manager->getFieldNames(),
				array('buyer' => $buyer->id)
			);

			if (count($address_list) > 0)
				foreach ($address_list as $address) {
					$result['delivery_addresses'][] = array(
						'id'		=> $address->id,
						'name'		=> $address->name,
						'street'	=> $address->street,
						'street2'	=> $address->street2,
						'phone'		=> $address->phone,
						'city'		=> $address->city,
						'zip'		=> $address->zip,
						'state'		=> $address->state,
						'country'	=> $address->country,
						'access_code'	=> $address->access_code
					);
				}

			// get last used payment and delivery method
			$transaction = $transaction_manager->getSingleItem(
				$transaction_manager->getFieldNames(),
				array('buyer' => $buyer->id),
				array('timestamp'), false
			);

			if (is_object($transaction)) {
				$result['last_payment_method'] = $transaction->payment_method;
				$result['last_delivery_method'] = $transaction->delivery_method;
			}

			print json_encode($result);
		}
	}

	/**
	 * Check if account with specified email exists in database already.
	 */
	private function json_GetAccountExists() {
		$email = isset($_REQUEST['email']) ? fix_chars($_REQUEST['email']) : null;
		$manager = ShopBuyersManager::getInstance();
		$result = array(
			'account_exists'	=> false,
			'message'			=> ''
		);

		if (!is_null($email)) {
			$account = $manager->getSingleItem(array('id'), array('email' => $email));
			$result['account_exists'] = is_object($account);
			$result['message'] = $this->getLanguageConstant('message_error_account_exists');
		}

		print json_encode($result);
	}

	/**
	 * Show shopping card in form of JSON object
	 */
	private function json_ShowCart() {
		$manager = ShopItemManager::getInstance();
		$values_manager = ShopItemSizeValuesManager::getInstance();
		$gallery = class_exists('gallery') ? gallery::getInstance() : null;
		$cart = isset($_SESSION['shopping_cart']) ? $_SESSION['shopping_cart'] : array();

		$result = array();

		// get shopping cart from session
		$result['cart'] = array();
		$result['size_values'] = array();
		$result['count'] = count($result['cart']);
		$result['currency'] = $this->getDefaultCurrency();

		if (isset($_SESSION['transaction'])) {
			$result['shipping'] = $_SESSION['transaction']['shipping'];
			$result['handling'] = $_SESSION['transaction']['handling'];

		} else {
			$result['shipping'] = 0;
			$result['handling'] = 0;
		}

		// colect ids from session
		$ids = array_keys($cart);

		// get items from database and prepare result
		$items = $manager->getItems($manager->getFieldNames(), array('uid' => $ids));
		$values = $values_manager->getItems($values_manager->getFieldNames(), array());

		if (count($items) > 0)
			foreach ($items as $item) {
				// get item image url
				$thumbnail_url = class_exists('gallery') ? gallery::getGroupThumbnailById($item->gallery) : '';

				$uid = $item->uid;

				if (array_key_exists($uid, $cart) && count($cart[$uid]['variations']) > 0)
					foreach ($cart[$uid]['variations'] as $variation_id => $properties) {
						$new_properties = $properties;
						unset($new_properties['count']);

						$result['cart'][] = array(
							'name'			=> $item->name,
							'weight'		=> $item->weight,
							'price'			=> $item->price,
							'tax'			=> $item->tax,
							'image'			=> $thumbnail_url,
							'uid'			=> $item->uid,
							'variation_id'	=> $variation_id,
							'count'			=> $properties['count'],
							'properties'	=> unfix_chars($new_properties),
							'size_definition'	=> $item->size_definition
						);
					}
			}

		if (count($values) > 0)
			foreach ($values as $value) {
				$result['size_values'][$value->id] = array(
					'definition'	=> $value->definition,
					'value'			=> $value->value
				);
			}

		print json_encode($result);
	}

	/**
	 * Set single item as shopping cart content.
	 */
	private function json_SetItemAsCart() {
		$uid = fix_chars($_REQUEST['uid']);
		$count = isset($_REQUEST['count']) ? fix_id($_REQUEST['count']) : 1;
		$variation_id = isset($_REQUEST['variation_id']) ? fix_chars($_REQUEST['variation_id']) : null;

		// set cart content
		$result = $this->setItemAsCart($uid, $count, $variation_id);

		print json_encode($result);
	}

	/**
	 * Clear shopping cart and return result in form of JSON object
	 */
	private function json_ClearCart() {
		$_SESSION['shopping_cart'] = array();
		Events::trigger('shop', 'shopping-cart-changed');

		print json_encode(true);
	}

	/**
	 * Set shopping cart from previous transaction.
	 */
	private function json_SetCartFromTransaction() {
		$uid = fix_chars($_REQUEST['uid']);
		$item_manager = ShopItemManager::getInstance();
		$transaction_manager = ShopTransactionsManager::getInstance();
		$transaction_item_manager = ShopTransactionItemsManager::getInstance();

		// find specified transaction
		$transaction = $transaction_manager->getSingleItem(
				array('id'),
				array(
					'uid'	=> $uid,
					'type'	=> array(
						TransactionType::REGULAR,
						TransactionType::DELAYED
					)
				)
			);

		// no transaction was found, show current cart and return
		if (!is_object($transaction)) {
			$this->json_ShowCart();
			return;
		}

		// get transaction items
		$items = $transaction_item_manager->getItems(
			$transaction_item_manager->getFieldNames(),
			array('transaction' => $transaction->id)
		);

		// no items in this transaction, show current cart and return
		if (count($items) == 0) {
			$this->json_ShowCart();
			return;
		}

		// parse transaction item list
		$id_list = array();
		$amount_list = array();
		$description_list = array();
		foreach ($items as $item) {
			$id_list[] = $item->item;
			$amount_list[$item->item] = $item->amount;
			$description_list[$item->item] = $item->description;
		}

		// get active shop items
		$items = $item_manager->getItems(
			$item_manager->getFieldNames(),
			array(
				'deleted'	=> 0,
				'visible'	=> 1,
				'id'		=> $id_list
			)
		);

		// no visible and active items, show current cart and return
		if (count($items) == 0) {
			$this->json_ShowCart();
			return;
		}

		// prepare new items
		$cart = array();
		foreach ($items as $item) {
			$properties = unserialize($description_list[$item->id]);
			$variation_id = $this->generateVariationId($item->uid, $properties);

			if (array_key_exists($item->uid, $cart)) {
				$cart[$item->uid]['quantity'] += $amount_list[$item->id];

			} else {
				$cart[$item->uid] = array(
						'uid'			=> $item->uid,
						'quantity'		=> $amount_list[$item->id],
						'variations'	=> array()
					);
			}

			$cart[$item->uid]['variations'][$variation_id] = $properties;
			$cart[$item->uid]['variations'][$variation_id]['count'] = $amount_list[$item->id];
		}

		// assign new cart to session
		$_SESSION['shopping_cart'] = $cart;

		// trigger an event
		Events::trigger('shop', 'shopping-cart-changed');

		// return response
		$this->json_ShowCart();
	}

	/**
	 * Add item to shopping cart using JSON request
	 */
	private function json_AddItemToCart() {
		$uid = fix_chars($_REQUEST['uid']);
		$cart = isset($_SESSION['shopping_cart']) ? $_SESSION['shopping_cart'] : array();
		$price_property = isset($_REQUEST['price_property']) ? fix_chars($_REQUEST['price_property']) : null;
		$properties = isset($_REQUEST['properties']) ? fix_chars($_REQUEST['properties']) : array();

		// get variation id
		if (isset($_REQUEST['variation_id']))
			$variation_id = fix_chars($_REQUEST['variation_id']); else
			$variation_id = $this->generateVariationId($uid, $properties);

		// get thumbnail options
		$thumbnail_size = isset($_REQUEST['thumbnail_size']) ? fix_id($_REQUEST['thumbnail_size']) : 100;
		$thumbnail_constraint = isset($_REQUEST['thumbnail_constraint']) ? fix_id($_REQUEST['thumbnail_constraint']) : Thumbnail::CONSTRAIN_BOTH;

		// try to get item from database
		$manager = ShopItemManager::getInstance();
		$item = $manager->getSingleItem($manager->getFieldNames(), array('uid' => $uid));

		// default result is false
		$result = null;

		if (is_object($item)) {
			if (array_key_exists($uid, $cart)) {
				// update existing item count
				$cart[$uid]['quantity']++;

			} else {
				// add new item to shopping cart
				$cart[$uid] = array(
					'uid'			=> $uid,
					'quantity'		=> 1,
					'variations'	=> array()
				);
			}

			if (!array_key_exists($variation_id, $cart[$uid]['variations'])) {
				$cart[$uid]['variations'][$variation_id] = $properties;
				$cart[$uid]['variations'][$variation_id]['count'] = 0;
			}

			// increase count in case it already exists
			$cart[$uid]['variations'][$variation_id]['count'] += 1;

			// get item image url
			$thumbnail_url = null;
			if (class_exists('gallery'))
				$thumbnail_url = gallery::getGroupThumbnailById(
										$item->gallery,
										null,
										$thumbnail_size,
										$thumbnail_constraint
									);

			// get item price
			if (!is_null($price_property)) {
				$properties_manager = \Modules\Shop\Property\Manager::getInstance();
				$property = $properties_manager->getSingleItem(
						array('value'),
						array(
							'item'    => $item->id,
							'text_id' => $price_property
						));

				if (is_object($property))
					$item_price = floatval(unserialize($property->value)); else
					$item_price = $item->price;  // fallback, better charge regular than nothing

			} else {
				$item_price = $item->price;
			}

			// prepare result
			$result = array(
				'name'            => $item->name,
				'weight'          => $item->weight,
				'price'           => $item_price,
				'tax'             => $item->tax,
				'size_definition' => $item->size_definition,
				'image'           => $thumbnail_url,
				'count'           => $cart[$uid]['variations'][$variation_id]['count'],
				'uid'             => $item->uid,
				'variation_id'    => $variation_id,
				'properties'      => unfix_chars($properties)
			);

			// update shopping cart
			$_SESSION['shopping_cart'] = $cart;
		}

		Events::trigger('shop', 'shopping-cart-changed');
		print json_encode($result);
	}

	/**
	 * Remove item from shopping cart using JSON request
	 */
	private function json_RemoveItemFromCart() {
		$uid = fix_chars($_REQUEST['uid']);
		$variation_id = fix_chars($_REQUEST['variation_id']);
		$cart = isset($_SESSION['shopping_cart']) ? $_SESSION['shopping_cart'] : array();
		$result = false;

		if (array_key_exists($uid, $cart) && array_key_exists($variation_id, $cart[$uid]['variations'])) {
			$count = $cart[$uid]['variations'][$variation_id]['count'];
			unset($cart[$uid]['variations'][$variation_id]);

			$cart[$uid]['quantity'] -= $count;

			if (count($cart[$uid]['variations']) == 0)
				unset($cart[$uid]);

			$_SESSION['shopping_cart'] = $cart;
			$result = true;
		}

		Events::trigger('shop', 'shopping-cart-changed');
		print json_encode($result);
	}

	/**
	 * Change the amount of items in shopping cart for specified UID and variation id.
	 */
	private function json_ChangeItemQuantity() {
		$uid = fix_chars($_REQUEST['uid']);
		$variation_id = fix_chars($_REQUEST['variation_id']);
		$count = fix_id($_REQUEST['count']);
		$cart = isset($_SESSION['shopping_cart']) ? $_SESSION['shopping_cart'] : array();
		$result = false;

		if (array_key_exists($uid, $cart) && array_key_exists($variation_id, $cart[$uid]['variations'])) {
			$old_count = $cart[$uid]['variations'][$variation_id]['count'];
			$cart[$uid]['variations'][$variation_id]['count'] = $count;

			$cart[$uid]['quantity'] += -$old_count + $count;

			$_SESSION['shopping_cart'] = $cart;
			$result = true;
		}

		Events::trigger('shop', 'shopping-cart-changed');
		print json_encode($result);
	}

	/**
	 * Retrieve list of payment methods
	 */
	private function json_GetPaymentMethods() {
		$result = array();

		// prepare data for printing
		foreach ($this->payment_methods as $payment_method)
			$result[] = array(
				'name'	=> $payment_method->get_name(),
				'title'	=> $payment_method->get_title(),
				'icon'	=> $payment_method->get_icon_url()
			);

		// print data
		print json_encode($result);
	}

	/**
	 * Get shopping cart summary and update delivery method if needed
	 */
	private function json_GetShoppingCartSummary() {
		$result = array();
		$uid = $_SESSION['transaction']['uid'];
		$transaction_manager = ShopTransactionsManager::getInstance();
		$payment_method = $this->getPaymentMethod(null);

		// get specified transaction
		$transaction = $transaction_manager->getSingleItem(
			$transaction_manager->getFieldNames(),
			array('uid' => $uid)
		);

		$type = $this->getTransactionType();
		if (is_object($transaction))
			$type = $transaction->type;

		$result = $this->getCartSummary($uid, $type, $payment_method);
		unset($result['items_for_checkout']);

		print json_encode($result);
	}

	/**
	 * Save transaction remark before submitting form
	 */
	private function json_SaveRemark() {
		$result = false;

		if (isset($_SESSION['transaction'])) {
			$manager = ShopTransactionsManager::getInstance();

			// get data
			$uid = $_SESSION['transaction']['uid'];
			$remark = escape_chars($_REQUEST['remark']);

			// store remark
			$manager->updateData(array('remark' => $remark), array('uid' => $uid));
			$result = true;
		}

		print json_encode($result);
	}

	/**
	 * Save default currency to module settings
	 * @param string $currency
	 */
	public function saveDefaultCurrency($currency) {
		$this->saveSetting('default_currency', $currency);
	}

	/**
	 * Return default currency
	 * @return string
	 */
	public function getDefaultCurrency() {
		return $this->settings['default_currency'];
	}

	/**
	 * Get shopping cart summary.
	 *
	 * @param string $transaction_id
	 * @param integer $type
	 * @param object $payment_method
	 * @return array
	 */
	private function getCartSummary($transaction_id, $type, $payment_method=null) {
		global $default_language;

		// prepare params
		$result = array();
		$shipping = 0;
		$handling = 0;
		$total_money = 0;
		$total_weight = 0;
		$items_by_uid = array();
		$items_for_checkout = array();
		$delivery_items = array();
		$map_id_to_uid = array();
		$currency = null;

		// get currency associated with transaction
		$transaction_manager = ShopTransactionsManager::getInstance();
		$currency_manager = ShopCurrenciesManager::getInstance();

		$transaction = $transaction_manager->getSingleItem(
							array('currency'),
							array('uid' => $transaction_id)
						);

		if (is_object($transaction))
			$currency = $currency_manager->getSingleItem(
				$currency_manager->getFieldNames(),
				array('id' => $transaction->currency)
			);

		if (is_object($currency))
			$preferred_currency = $currency->currency; else
			$preferred_currency = 'EUR';

		// get cart summary
		switch ($type) {
			case TransactionType::SUBSCRIPTION:
				$plan_name = $_SESSION['recurring_plan'];

				// get selected recurring plan
				$plans = array();
				if (!is_null($payment_method))
					$plans = $payment_method->get_recurring_plans();

				// get recurring plan price
				if (isset($plans[$plan_name])) {
					$plan = $plans[$plan_name];

					$handling = $plan['setup_price'];
					$total_money = $plan['price'];
				}
				break;

			case TransactionType::DELAYED:
			case TransactionType::REGULAR:
			default:
				// colect ids from session
				$cart = isset($_SESSION['shopping_cart']) ? $_SESSION['shopping_cart'] : array();
				$ids = array_keys($cart);

				if (count($cart) == 0)
					return $result;

				// get managers
				$manager = ShopItemManager::getInstance();

				// get items from database and prepare result
				$items = $manager->getItems($manager->getFieldNames(), array('uid' => $ids));

				// parse items from database
				foreach ($items as $item) {
					$db_item = array(
						'id'		=> $item->id,
						'name'		=> $item->name,
						'price'		=> $item->price,
						'tax'		=> $item->tax,
						'weight'	=> $item->weight
					);
					$items_by_uid[$item->uid] = $db_item;
					$map_id_to_uid[$item->id] = $item->uid;
				}

				// prepare items for checkout
				foreach ($cart as $uid => $item) {
					// include all item variations in preparation
					if (count($item['variations']) > 0)
						foreach($item['variations'] as $variation_id => $data) {
							// add items to checkout list
							$properties = $data;

							foreach ($this->excluded_properties as $key)
								if (isset($properties[$key]))
									unset($properties[$key]);

							$new_item = $items_by_uid[$uid];
							$new_item['count'] = $data['count'];
							$new_item['description'] = serialize($properties);

							// add item to list for delivery estimation
							$delivery_items []= array(
								'properties'	=> array(),
								'package'		=> 1,
								'weight'		=> 0.5,
								'package_type'	=> 0,
								'width'			=> 2,
								'height'		=> 5,
								'length'		=> 15,
								'units'			=> 1,
								'count'			=> $data['count']
							);

							// add item to the list
							$items_for_checkout[] = $new_item;

							// include item data in summary
							$tax = $new_item['tax'];
							$price = $new_item['price'];
							$weight = $new_item['weight'];

							$total_money += ($price * (1 + ($tax / 100))) * $data['count'];
							$total_weight += $weight * $data['count'];
						}
				}
				break;
		}

		$result = array(
			'items_for_checkout'	=> $items_for_checkout,
			'shipping'				=> $shipping,
			'handling'				=> $handling,
			'weight'				=> $total_weight,
			'total'					=> $total_money,
			'currency'				=> $preferred_currency
		);

		return $result;
	}

	/**
	 * Get payment method for checkout form.
	 *
	 * @param array $tag_params
	 * @return object
	 */
	private function getPaymentMethod($tag_params) {
		$result = null;
		$method_name = null;

		// require at least one payment method
		if (count($this->payment_methods) == 0)
			throw new PaymentMethodError('No payment methods found!');

		// get method name from various sources
		if (!is_null($tag_params) && isset($tag_params['payment_method']))
			$method_name = fix_chars($tag_params['payment_method']);

		if (isset($_REQUEST['payment_method']) && is_null($method_name))
			$method_name = fix_chars($_REQUEST['payment_method']);

		// get method based on its name
		if (isset($this->payment_methods[$method_name]))
			$result = $this->payment_methods[$method_name];

		return $result;
	}

	/**
	 * Get billing information if needed.
	 */
	private function getBillingInformation($payment_method) {
		$result = array();

		// get billing information
		if (!$payment_method->provides_information()) {
			$fields = array(
				'billing_full_name', 'billing_card_type', 'billing_credit_card', 'billing_expire_month',
				'billing_expire_year', 'billing_cvv'
			);

			foreach($fields as $field)
				if (isset($_REQUEST[$field]))
					$result[$field] = fix_chars($_REQUEST[$field]);

			// remove dashes and empty spaces
			$result['billing_credit_card'] = str_replace(
				array(' ', '-', '_'), '',
				$result['billing_credit_card']
			);
		}

		return $result;
	}

	/**
	 * Get shipping information.
	 *
	 * @return array
	 */
	private function getShippingInformation() {
		$result = array();
		$fields = array('name', 'email', 'phone', 'street', 'street2', 'city', 'zip', 'country', 'state');

		// get delivery information
		foreach($fields as $field)
			if (isset($_REQUEST[$field]))
				$result[$field] = fix_chars($_REQUEST[$field]);

		return $result;
	}

	/**
	 * Get existing or create a new user account.
	 *
	 * @return object
	 */
	private function getUserAccount() {
		$result = null;
		$manager = ShopBuyersManager::getInstance();
		$existing_user = isset($_POST['existing_user']) ? escape_chars($_POST['existing_user']) : null;

		// set proper account data based on users choice
		if (!is_null($existing_user)) {
			switch ($existing_user) {
				case User::EXISTING:
					// get managers
					$user_manager = UserManager::getInstance();
					$retry_manager = LoginRetryManager::getInstance();

					// get user data
					$email = escape_chars($_REQUEST['sign_in_email']);
					$password = $_REQUEST['sign_in_password'];

					// check credentials
					$retry_count = $retry_manager->getRetryCount();
					$credentials_ok = $user_manager->check_credentials($email, $password);

					// get user account if sign in is valid
					if ($credentials_ok && $retry_count <= 3)
						$result = $manager->getSingleItem(
								$manager->getFieldNames(),
								array('email' => $email)
							);

					break;

				case User::CREATE:
					// get manager
					$user_manager = UserManager::getInstance();
					$retry_manager = LoginRetryManager::getInstance();

					// check if user agrees
					$agree_to_terms = false;
					if (isset($_REQUEST['agree_to_terms']))
					   $agree_to_terms = $_REQUEST['agree_to_terms'] == 'on' || $_REQUEST['agree_to_terms'] == '1';

					$want_promotions = $_REQUEST['want_promotions'] == 'on' || $_REQUEST['want_promotions'] == '1';

					// get user data
					$data = array(
						'first_name'	=> escape_chars($_REQUEST['first_name']),
						'last_name'		=> escape_chars($_REQUEST['last_name']),
						'email'			=> escape_chars($_REQUEST['new_email']),
						'uid'			=> isset($_REQUEST['uid']) ? escape_chars($_REQUEST['uid']) : '',
						'guest'			=> 0,
						'agreed'		=> $_REQUEST['agree_to_terms'] == 'on' || $_REQUEST['agree_to_terms'] == '1',
						'promotions'	=> $want_promotions ? 1 : 0
					);

					$password = $_REQUEST['new_password'];
					$password_confirm = $_REQUEST['new_password_confirm'];

					// check if system user already exists
					$user = $user_manager->getSingleItem(array('id'), array('email' => $data['email']));

					if (is_object($user)) {
						// check if buyer exists
						$buyer = $manager->getSingleItem(
									$manager->getFieldNames(),
									array('system_user' => $user->id)
								);

						if (is_object($buyer)) {
							// buyer already exists, no need to create new
							$result = $buyer;

						} else {
							// assign system user to buyer
							$data['system_user'] = $user->id;

							// create new account
							$manager->insertData($data);

							// get account object
							$id = $manager->getInsertedID();
							$result = $manager->getSingleItem($manager->getFieldNames(), array('id' => $id));

							// send notification email
							if (class_exists('Backend_UserManager')) {
								$backed_user_manager = Backend_UserManager::getInstance();
								$backed_user_manager->sendNotificationEmail($user->id);
							}
						}

					} else if ($password == $password_confirm) {
						$user_data = array(
								'username'		=> $data['email'],
								'email'			=> $data['email'],
								'fullname'		=> $data['first_name'].' '.$data['last_name'],
								'first_name'	=> $data['first_name'],
								'last_name'		=> $data['last_name'],
								'level'			=> 0,
								'verified'		=> 0,
								'agreed'		=> 0
							);
						$user_manager->insertData($user_data);
						$data['system_user'] = $user_manager->getInsertedID();
						$user_manager->change_password($user_data['username'], $password);

						// create new account
						$manager->insertData($data);

						// get account object
						$id = $manager->getInsertedID();
						$result = $manager->getSingleItem($manager->getFieldNames(), array('id' => $id));

						// send notification email
						if (class_exists('Backend_UserManager')) {
							$backed_user_manager = Backend_UserManager::getInstance();
							$backed_user_manager->sendNotificationEmail($result->system_user);
						}
					}

					break;

				case User::GUEST:
				default:
					// check if user agrees
					$agree_to_terms = false;
					if (isset($_REQUEST['agree_to_terms']))
					   $agree_to_terms = $_REQUEST['agree_to_terms'] == 'on' || $_REQUEST['agree_to_terms'] == '1';

					// check if user wants to receive promotional emails
					$want_promotions = false;
					if (isset($_REQUEST['want_promotions']))
						$want_promotions = $_REQUEST['want_promotions'] == 'on' || $_REQUEST['want_promotions'] == '1';

					// collect data
					if (isset($_REQUEST['name'])) {
						$name = explode(' ', escape_chars($_REQUEST['name']), 2);
						$first_name = $name[0];
						$last_name = count($name) > 1 ? $name[1] : '';

					} else {
						$first_name = escape_chars($_REQUEST['first_name']);
						$last_name = escape_chars($_REQUEST['last_name']);
					}

					$uid = isset($_REQUEST['uid']) ? escape_chars($_REQUEST['uid']) : null;
					$email = isset($_REQUEST['email']) ? escape_chars($_REQUEST['email']) : null;

					$conditions = array();
					$data = array(
						'first_name'	=> $first_name,
						'last_name'		=> $last_name,
						'guest'			=> 1,
						'system_user'	=> 0,
						'agreed'		=> $agree_to_terms,
						'promotions'	=> $want_promotions ? 1 : 0
					);

					// include uid if specified
					if (!is_null($uid)) {
						$conditions['uid'] = $uid;
						$data['uid'] = $uid;
					}

					// include email if specified
					if (!is_null($email)) {
						$conditions['email'] = $email;
						$data['email'] = $email;
					}

					// try finding existing account
					if (count($conditions) > 0) {
						$account = $manager->getSingleItem($manager->getFieldNames(), $conditions);

						if (is_object($account))
							$result = $account;
					}

					// create new account
					if (!is_object($result)) {
						$manager->insertData($data);

						// get account object
						$id = $manager->getInsertedID();
						$result = $manager->getSingleItem($manager->getFieldNames(), array('id' => $id));
					}

					break;
			}

		} else if ($_SESSION['logged']) {
			// user is already logged in, get associated buyer
			$buyer = $manager->getSingleItem(
				$manager->getFieldNames(),
				array('system_user' => $_SESSION['uid'])
			);

			if (is_object($buyer))
				$result = $buyer;
		}

		return $result;
	}

	/**
	 * Get user's address.
	 */
	private function getAddress($buyer, $shipping_information) {
		$address_manager = ShopDeliveryAddressManager::getInstance();

		// try to associate address with transaction
		$address = $address_manager->getSingleItem(
			$address_manager->getFieldNames(),
			array(
				'buyer'		=> $buyer->id,
				'name'		=> $shipping_information['name'],
				'street'	=> $shipping_information['street'],
				'street2'	=> isset($shipping_information['street2']) ? $shipping_information['street2'] : '',
				'city'		=> $shipping_information['city'],
				'zip'		=> $shipping_information['zip'],
				'state'		=> $shipping_information['state'],
				'country'	=> $shipping_information['country'],
			));

		if (is_object($address)) {
			// existing address
			$result = $address;

		} else {
			// create new address
			$address_manager->insertData(array(
				'buyer'		=> $buyer->id,
				'name'		=> $shipping_information['name'],
				'street'	=> $shipping_information['street'],
				'street2'	=> isset($shipping_information['street2']) ? $shipping_information['street2'] : '',
				'phone'		=> $shipping_information['phone'],
				'city'		=> $shipping_information['city'],
				'zip'		=> $shipping_information['zip'],
				'state'		=> $shipping_information['state'],
				'country'	=> $shipping_information['country'],
				'access_code'	=> $shipping_information['access_code']
			));

			$id = $address_manager->getInsertedID();
			$result = $address_manager->getSingleItem($address_manager->getFieldNames(), array('id' => $id));
		}

		return $result;
	}

	/**
	 * Update transaction data.
	 *
	 * @param integer $type
	 * @param object $payment_method
	 * @param string $delivery_method
	 * @param object $buyer
	 * @param object $address
	 * @return array
	 */
	private function updateTransaction($type, $payment_method, $delivery_method, $buyer, $address) {
		global $db;

		$result = array();
		$transactions_manager = ShopTransactionsManager::getInstance();
		$transaction_items_manager = ShopTransactionItemsManager::getInstance();
		$transaction_plans_manager = ShopTransactionPlansManager::getInstance();

		// update buyer
		if (!is_null($buyer))
			$result['buyer'] = $buyer->id;

		// determine if we need a new session
		$new_transaction = true;

		if (isset($_SESSION['transaction']) && isset($_SESSION['transaction']['uid'])) {
			$uid = $_SESSION['transaction']['uid'];
			$transaction = $transactions_manager->getSingleItem(array('status'), array('uid' => $uid));
			$new_transaction = !(is_object($transaction) && $transaction->status == TransactionStatus::PENDING);
		}

		// check if we have existing transaction in our database
		if ($new_transaction) {
			// get shopping cart summary
			$uid = uniqid('', true);
			$summary = $this->getCartSummary($uid, $type, $payment_method);

			// decide on new transaction status
			$new_status = TransactionStatus::PENDING;
			if ($type == TransactionType::DELAYED)
				$new_status = TransactionStatus::UNKNOWN;

			// prepare data
			$result['uid'] = $uid;
			$result['type'] = $type;
			$result['status'] = $new_status;
			$result['handling'] = $summary['handling'];
			$result['shipping'] = $summary['shipping'];
			$result['weight'] = $summary['weight'];
			$result['payment_method'] = $payment_method->get_name();
			$result['delivery_method'] = $delivery_method;
			$result['remark'] = '';
			$result['total'] = $summary['total'];

			// get default currency
			$currency_manager = ShopCurrenciesManager::getInstance();
			$default_currency = $this->settings['default_currency'];
			$currency = $currency_manager->getSingleItem(array('id'), array('currency' => $default_currency));

			if (is_object($currency))
				$result['currency'] = $currency->id;

			// add address if needed
			if (!is_null($address))
				$result['address'] = $address->id;

			// create new transaction
			$transactions_manager->insertData($result);
			$result['id'] = $transactions_manager->getInsertedID();

			// store transaction data to session
			$_SESSION['transaction'] = $result;

		} else {
			$uid = $_SESSION['transaction']['uid'];
			$summary = $this->getCartSummary($uid, $type, $payment_method);

			// there's already an existing transaction
			$result = $_SESSION['transaction'];
			$result['handling'] = $summary['handling'];
			$result['shipping'] = $summary['shipping'];
			$result['total'] = $summary['total'];

			$data = array(
				'handling'	=> $summary['handling'],
				'shipping'	=> $summary['shipping'],
				'total'		=> $summary['total']
			);

			if (!is_null($address))
				$data['address'] = $address->id;

			// update existing transaction
			$transactions_manager->updateData($data, array('uid' => $uid));

			// update session storage with newest data
			$_SESSION['transaction'] = $result;
		}

		// remove items associated with transaction
		$transaction_items_manager->deleteData(array('transaction' => $result['id']));

		// remove plans associated with transaction
		$transaction_plans_manager->deleteData(array('transaction' => $result['id']));

		// store items
		if (count($summary['items_for_checkout']) > 0)
			foreach($summary['items_for_checkout'] as $uid => $item) {
				$transaction_items_manager->insertData(array(
					'transaction'	=> $result['id'],
					'item'			=> $item['id'],
					'price'			=> $item['price'],
					'tax'			=> $item['tax'],
					'amount'		=> $item['count'],
					'description'	=> $item['description']
				));
			}

		$result['items_for_checkout'] = $summary['items_for_checkout'];

		// create plan entry
		if (isset($_SESSION['recurring_plan'])) {
			$plan_name = $_SESSION['recurring_plan'];
			$plan_list = $payment_method->get_recurring_plans();
			$plan = isset($plan_list[$plan_name]) ? $plan_list[$plan_name] : null;

			if (!is_null($plan))
				$transaction_plans_manager->insertData(array(
					'transaction'		=> $result['id'],
					'plan_name'			=> $plan_name,
					'trial'				=> $plan['trial'],
					'trial_count'		=> $plan['trial_count'],
					'interval'			=> $plan['interval'],
					'interval_count'	=> $plan['interval_count'],
					'start_time'		=> $db->format_timestamp($plan['start_time']),
					'end_time'			=> $db->format_timestamp($plan['end_time'])
				));
		}

		// if affiliate system is active, update referral
		if (isset($_SESSION['referral_id']) && class_exists('affiliates')) {
			$referral_id = $_SESSION['referral_id'];
			$referrals_manager = AffiliateReferralsManager::getInstance();

			$referrals_manager->updateData(
				array('transaction' => $result['id']),
				array('id' => $referral_id)
			);
		}

		return $result;
	}

	/**
	 * Update buyer information for specified transaction. This function is
	 * called by the payment methods that provide buyer information. Return
	 * value denotes whether information update is successful and if method
	 * should complete the billing process.
	 *
	 * @param string $transaction_uid
	 * @param array $buyer_data
	 * @return boolean
	 */
	public function updateBuyerInformation($transaction_uid, $buyer_data) {
		$result = false;
		$transaction_manager = ShopTransactionsManager::getInstance();
		$buyer_manager = ShopBuyersManager::getInstance();

		// make sure buyer is marked as guest if password is not specified
		if (!isset($buyer_data['password']))
			$buyer_data['guest'] = 1;

		// get transaction from database
		$transaction = $transaction_manager->getSingleItem(
			array('id', 'buyer'),
			array('uid' => $transaction_uid)
		);

		// try to get buyer from the system based on uid
		if (isset($buyer_data['uid']))
			$buyer = $buyer_manager->getSingleItem(
				$buyer_manager->getFieldNames(),
				array('uid' => $buyer_data['uid'])
			);

		// update buyer information
		if (is_object($transaction)) {
			// get buyer id
			if (is_object($buyer)) {
				$buyer_id = $buyer->id;

				// update buyer information
				$buyer_manager->updateData($buyer_data, array('id' => $buyer->id));

			} else {
				// create new buyer
				$buyer_manager->insertData($buyer_data);
				$buyer_id = $buyer_manager->getInsertedID();
			}

			// update transaction buyer
			$transaction_manager->updateData(
				array('buyer'	=> $buyer_id),
				array('id'		=> $transaction->id)
			);

			$result = true;

		} else {
			trigger_error("No transaction with specified id: {$transaction_uid}");
		}

		return $result;
	}

	/**
	 * Check if data doesn't contain required fields.
	 *
	 * @param array $data
	 * @param array $required
	 * @param array $start
	 * @return array
	 */
	private function checkFields($data, $required, $start=array()) {
		$result = $start;
		$keys = array_keys($data);

		foreach($required as $field)
			if (!in_array($field, $keys))
				$return[] = $field;

		return $result;
	}

	/**
	 * Send email for transaction using specified template.
	 *
	 * @param object $transaction
	 * @param string $template
	 * @return boolean
	 */
	private function sendTransactionMail($transaction, $template) {
		global $language;

		$result = false;

		// require contact form
		if (!class_exists('contact_form'))
			return $result;

		$email_address = null;
		$contact_form = contact_form::getInstance();

		// template replacement data
		$status_text = $this->getLanguageConstant(TransactionStatus::$reverse[$transaction->status]);
		$fields = array(
			'transaction_id'				=> $transaction->id,
			'transaction_uid'				=> $transaction->uid,
			'status'						=> $transaction->status,
			'status_text'					=> $status_text,
			'handling'						=> $transaction->handling,
			'shipping'						=> $transaction->shipping,
			'total'							=> $transaction->total,
			'weight'						=> $transaction->weight,
			'payment_method'				=> $transaction->payment_method,
			'delivery_method'				=> $transaction->delivery_method,
			'delivery_type'					=> $transaction->delivery_type,
			'remark'						=> $transaction->remark,
			'remote_id'						=> $transaction->remote_id,
			'timestamp'						=> $transaction->timestamp
		);

		$timestamp = strtotime($transaction->timestamp);
		$fields['date'] = date($this->getLanguageConstant('format_date_short'), $timestamp);
		$fields['time'] = date($this->getLanguageConstant('format_time_short'), $timestamp);

		// get currency
		$currency_manager = ShopCurrenciesManager::getInstance();
		$currency = $currency_manager->getSingleItem(
				$currency_manager->getFieldNames(),
				array('id' => $transaction->currency)
			);

		if (is_object($currency))
			$fields['currency'] = $currency->currency;

		// add buyer information
		$buyer_manager = ShopBuyersManager::getInstance();
		$buyer = $buyer_manager->getSingleItem(
				$buyer_manager->getFieldNames(),
				array('id' => $transaction->buyer)
			);

		if (is_object($buyer)) {
			$fields['buyer_first_name'] = $buyer->first_name;
			$fields['buyer_last_name'] = $buyer->last_name;
			$fields['buyer_email'] = $buyer->email;
			$fields['buyer_uid'] = $buyer->uid;

			$email_address = $buyer->email;
		}

		// add buyer address
		$address_manager = ShopDeliveryAddressManager::getInstance();
		$address = $address_manager->getSingleItem(
			$address_manager->getFieldNames(),
			array('id' => $transaction->address)
		);

		if (is_object($address)) {
			$fields['address_name'] = $address->name;
			$fields['address_street'] = $address->street;
			$fields['address_street2'] = $address->street2;
			$fields['address_phone'] = $address->phone;
			$fields['address_city'] = $address->city;
			$fields['address_zip'] = $address->zip;
			$fields['address_state'] = $address->state;
			$fields['address_country'] = $address->country;
		}

		// create item table
		switch ($transaction->type) {
			case TransactionType::REGULAR:
				$subtotal = 0;
				$item_manager = ShopItemManager::getInstance();
				$transaction_item_manager = ShopTransactionItemsManager::getInstance();
				$items = $transaction_item_manager->getItems(
					$transaction_item_manager->getFieldNames(),
					array('transaction' => $transaction->id)
				);

				if (count($items) > 0) {
					// prepare item names
					$id_list = array();
					foreach ($items as $item)
						$id_list[] = $item->item;

					$item_names = array();
					$item_list = $item_manager->getItems(array('id', 'name'), array('id' => $id_list));
					foreach ($item_list as $item)
						$item_names[$item->id] = $item->name[$language];

					// create items table
					$text_table = str_pad($this->getLanguageConstant('column_name'), 60);
					$text_table .= str_pad($this->getLanguageConstant('column_price'), 8);
					$text_table .= str_pad($this->getLanguageConstant('column_amount'), 6);
					$text_table .= str_pad($this->getLanguageConstant('column_item_total'), 8);
					$text_table .= "\n" . str_repeat('-', 60 + 8 + 6 + 8) . "\n";

					$html_table = '<table border="0" cellspacing="5" cellpadding="0">';
					$html_table .= '<thead><tr>';
					$html_table .= '<td>'.$this->getLanguageConstant('column_name').'</td>';
					$html_table .= '<td>'.$this->getLanguageConstant('column_price').'</td>';
					$html_table .= '<td>'.$this->getLanguageConstant('column_amount').'</td>';
					$html_table .= '<td>'.$this->getLanguageConstant('column_item_total').'</td>';
					$html_table .= '</td></thead><tbody>';

					foreach ($items as $item) {
						// append item name with description
						$description = unserialize($item->description);

						if (!empty($description)) {
							$description_text = implode(', ', array_values($description));
							$line = $item_names[$item->item]. ' (' . $description_text . ')';
						} else {
							$line = $item_names[$item->item];
						}

						$line = utf8_wordwrap($line, 60, "\n", true);
						$line = mb_split("\n", $line);

						// append other columns
						$line[0] = str_pad($line[0], 60, ' ', STR_PAD_RIGHT);
						$line[0] .= str_pad($item->price, 8, ' ', STR_PAD_LEFT);
						$line[0] .= str_pad($item->amount, 6, ' ', STR_PAD_LEFT);
						$line[0] .= str_pad($item->price * $item->amount, 8, ' ', STR_PAD_LEFT);

						// add this item to text table
						foreach ($line as $row)
							$text_table .= $row;
						$text_table .= "\n\n";

						// form html row
						$row = '<tr><td>' . $item_names[$item->item];

						if (!empty($description))
							$row .= ' <small>' . $description_text . '</small>';

						$row .= '</td><td>' . $item->price . '</td>';
						$row .= '<td>' . $item->amount . '</td>';
						$row .= '<td>' . ($item->price * $item->amount) . '</td></tr>';
						$html_table .= $row;

						// update subtotal
						$subtotal += $item->price * $item->amount;
					}

					// close text table
					$text_table .= str_repeat('-', 60 + 8 + 6 + 8) . "\n";
					$html_table .= '</tbody>';

					// create totals
					$text_table .= str_pad($this->getLanguageConstant('column_subtotal'), 15);
					$text_table .= str_pad($subtotal, 10, ' ', STR_PAD_LEFT) . "\n";

					$text_table .= str_pad($this->getLanguageConstant('column_shipping'), 15);
					$text_table .= str_pad($transaction->shipping, 10, ' ', STR_PAD_LEFT) . "\n";

					$text_table .= str_pad($this->getLanguageConstant('column_handling'), 15);
					$text_table .= str_pad($transaction->handling, 10, ' ', STR_PAD_LEFT) . "\n";

					$text_table .= str_repeat('-', 25);
					$text_table .= str_pad($this->getLanguageConstant('column_total'), 15);
					$text_table .= str_pad($transaction->total, 10, ' ', STR_PAD_LEFT) . "\n";

					$html_table .= '<tfoot>';
					$html_table .= '<tr><td colspan="2"></td><td>' . $this->getLanguageConstant('column_subtotal') . '</td>';
					$html_table .= '<td>' . $subtotal . '</td></tr>';

					$html_table .= '<tr><td colspan="2"></td><td>' . $this->getLanguageConstant('column_shipping') . '</td>';
					$html_table .= '<td>' . $transaction->shipping . '</td></tr>';

					$html_table .= '<tr><td colspan="2"></td><td>' . $this->getLanguageConstant('column_handling') . '</td>';
					$html_table .= '<td>' . $transaction->handling . '</td></tr>';

					$html_table .= '<tr><td colspan="2"></td><td><b>' . $this->getLanguageConstant('column_total') . '</b></td>';
					$html_table .= '<td><b>' . $transaction->total . '</b></td></tr>';

					$html_table .= '</tfoot>';

					// close table
					$html_table .= '</table>';

					// add field
					$fields['html_item_table'] = $html_table;
					$fields['text_item_table'] = $text_table;
				}
				break;

			case TransactionType::DELAYED:
				$subtotal = 0;
				$item_manager = ShopItemManager::getInstance();
				$transaction_item_manager = ShopTransactionItemsManager::getInstance();
				$items = $transaction_item_manager->getItems(
					$transaction_item_manager->getFieldNames(),
					array('transaction' => $transaction->id)
				);

				if (count($items) > 0) {
					// prepare item names
					$id_list = array();
					foreach ($items as $item)
						$id_list[] = $item->item;

					$item_names = array();
					$item_list = $item_manager->getItems(array('id', 'name'), array('id' => $id_list));
					foreach ($item_list as $item)
						$item_names[$item->id] = $item->name[$language];

					// create items table
					$text_table = str_pad($this->getLanguageConstant('column_name'), 60);
					$text_table .= str_pad($this->getLanguageConstant('column_amount'), 6);
					$text_table .= "\n" . str_repeat('-', 60 + 6) . "\n";

					$html_table = '<table border="0" cellspacing="5" cellpadding="0">';
					$html_table .= '<thead><tr>';
					$html_table .= '<td>'.$this->getLanguageConstant('column_name').'</td>';
					$html_table .= '<td>'.$this->getLanguageConstant('column_amount').'</td>';
					$html_table .= '</td></thead><tbody>';

					foreach ($items as $item) {
						// append item name with description
						$description = unserialize($item->description);

						if (!empty($description)) {
							$description_text = implode(', ', array_values($description));
							$line = $item_names[$item->item]. ' (' . $description_text . ')';
						} else {
							$line = $item_names[$item->item];
						}

						$line = utf8_wordwrap($line, 60, "\n", true);
						$line = mb_split("\n", $line);

						// correct columns
						$line[0] = str_pad($line[0], 60, ' ', STR_PAD_RIGHT);
						$line[0] .= str_pad($item->amount, 6, ' ', STR_PAD_LEFT);

						// add this item to text table
						foreach ($line as $row)
							$text_table .= $row;
						$text_table .= "\n\n";

						// form html row
						$row = '<tr><td>' . $item_names[$item->item];

						if (!empty($description))
							$row .= ' <small>' . $description_text . '</small>';

						$row .= '</td><td>' . $item->amount . '</td></tr>';
						$html_table .= $row;
					}

					// close text table
					$text_table .= str_repeat('-', 60 + 6) . "\n";
					$html_table .= '</tbody>';

					// create totals
					$text_table .= str_pad($this->getLanguageConstant('column_shipping'), 15);
					$text_table .= str_pad($transaction->shipping, 10, ' ', STR_PAD_LEFT) . "\n";

					$text_table .= str_pad($this->getLanguageConstant('column_handling'), 15);
					$text_table .= str_pad($transaction->handling, 10, ' ', STR_PAD_LEFT) . "\n";

					$text_table .= str_repeat('-', 25);
					$text_table .= str_pad($this->getLanguageConstant('column_total'), 15);
					$text_table .= str_pad($transaction->total, 10, ' ', STR_PAD_LEFT) . "\n";

					$html_table .= '<tfoot>';
					$html_table .= '<tr><td></td><td>' . $this->getLanguageConstant('column_shipping') . '</td>';
					$html_table .= '<td>' . $transaction->shipping . '</td></tr>';

					$html_table .= '<tr><td></td><td>' . $this->getLanguageConstant('column_handling') . '</td>';
					$html_table .= '<td>' . $transaction->handling . '</td></tr>';

					$html_table .= '<tr><td></td><td><b>' . $this->getLanguageConstant('column_total') . '</b></td>';
					$html_table .= '<td><b>' . $transaction->total . '</b></td></tr>';

					$html_table .= '</tfoot>';

					// close table
					$html_table .= '</table>';

					// add field
					$fields['html_item_table'] = $html_table;
					$fields['text_item_table'] = $text_table;
				}
				break;

			case TransactionType::SUBSCRIPTION:
				$plan_manager = ShopTransactionPlansManager::getInstance();
				$plan = $plan_manager->getSingleItem(
					$plan_manager->getFieldNames(),
					array('transaction' => $transaction->id)
				);

				// get payment method
				$plan_data = null;
				if (isset($this->payment_methods[$transaction->payment_method])) {
					$payment_method = $this->payment_methods[$transaction->payment_method];
					$plans = $payment_method->get_recurring_plans();

					if (isset($plans[$plan->plan_name]))
						$plan_data = $plans[$plan->plan_name];
				}

				// populate fields with plan params
				if (is_object($plan) && !is_null($plan_data)) {
					$fields['plan_text_id'] = $plan->plan_name;
					$fields['plan_name'] = $plan_data['name'][$language];
				}
				break;
		}

		// we require email address for sending
		if (is_null($email_address) || empty($email_address))
			return $result;

		// get mailer
		$mailers = $contact_form->getMailers();
		$sender = $contact_form->getSender();
		$template = $contact_form->getTemplate($template);

		// start creating message
		foreach ($mailers as $mailer_name => $mailer) {
			$mailer->start_message();
			$mailer->set_subject($template['subject']);
			$mailer->set_sender($sender['address'], $sender['name']);
			$mailer->add_recipient($email_address);

			$mailer->set_body($template['plain_body'], $template['html_body']);
			$mailer->set_variables($fields);

			// send email
			$mailer->send();
		}

		return $result;
	}

	/**
	 * Show recurring plan from specified payment method.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_RecurringPlan($tag_params, $children) {
		$plan_name = null;
		$payment_method = $this->getPaymentMethod($tag_params);

		// we ned payment mothod to proceed
		if (!is_object($payment_method))
			return;

		// get plan name from the parameters
		if (isset($tag_params['plan']))
			$plan_name = fix_chars($tag_params['plan']);

		// get all the plans from payment method
		$plans = $payment_method->get_recurring_plans();

		// show plan
		if (count($plans) > 0 && !is_null($plan_name) && isset($plans[$plan_name])) {
			$template = $this->loadTemplate($tag_params, 'plan.xml');
			$template->setTemplateParamsFromArray($children);
			$current_plan = $this->getRecurringPlan();

			$params = $plans[$plan_name];
			$params['selected'] = is_object($current_plan) && $current_plan->plan_name == $plan_name;
			$params['text_id'] = $plan_name;

			$template->restoreXML();
			$template->setLocalParams($params);
			$template->parse();
		}
	}

	/**
	 * Handle drawing checkout form
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_CheckoutForm($tag_params, $children) {
		$account_information = array();
		$shipping_information = array();
		$billing_information = array();
		$payment_method = null;
		$stage = isset($_REQUEST['stage']) ? fix_chars($_REQUEST['stage']) : null;
		$transaction_type = $this->getTransactionType();
		$recurring = $transaction_type == TransactionType::SUBSCRIPTION;

		// decide whether to include shipping and account information
		if (isset($tag_params['include_shipping']))
			$include_shipping = fix_id($tag_params['include_shipping']) == 1; else
			$include_shipping = true;

		$bad_fields = array();
		$info_available = false;

		// grab user information
		if (!is_null($stage)) {
			// get payment method
			$payment_method = $this->getPaymentMethod($tag_params);

			if (is_null($payment_method))
				throw new PaymentMethodError('No payment method selected!');

			// get billing information
			$billing_information = $this->getBillingInformation($payment_method);
			$billing_required = array(
				'billing_full_name', 'billing_card_type', 'billing_credit_card', 'billing_expire_month',
				'billing_expire_year', 'billing_cvv'
			);
			$bad_fields = $this->checkFields($billing_information, $billing_required, $bad_fields);

			// get shipping information
			if ($include_shipping && $stage == 'set_info') {
				$shipping_information = $this->getShippingInformation();
				$shipping_required = array('name', 'email', 'street', 'city', 'zip', 'country');
				$bad_fields = $this->checkFields($shipping_information, $shipping_required, $bad_fields);
			}
		}

		$info_available = count($bad_fields) == 0 && !is_null($payment_method);

		// log bad fields if debugging is enabled
		if (count($bad_fields) > 0 && defined('DEBUG'))
			trigger_error('Checkout bad fields: '.implode(', ', $bad_fields), E_USER_NOTICE);

		if ($info_available) {
			$address_manager = ShopDeliveryAddressManager::getInstance();
			$currency_manager = ShopCurrenciesManager::getInstance();

			// get fields for payment method
			$return_url = url_Make('checkout_completed', 'shop', array('payment_method', $payment_method->get_name()));
			$cancel_url = url_Make('checkout_canceled', 'shop', array('payment_method', $payment_method->get_name()));

			// get currency info
			$currency = $this->settings['default_currency'];
			$currency_item = $currency_manager->getSingleItem(array('id'), array('currency' => $currency));

			if (is_object($currency_item))
				$transaction_data['currency'] = $currency_item->id;

			// get buyer
			$buyer = $this->getUserAccount();

			if (is_null($buyer))
				trigger_error('Unknown buyer, unable to proceed with checkout.', E_USER_ERROR);

			if ($include_shipping)
				$address = $this->getAddress($buyer, $shipping_information); else
				$address = null;

			// update transaction
			$summary = $this->updateTransaction($transaction_type, $payment_method, '', $buyer, $address);

			// emit signal and return if handled
			if ($stage == 'set_info') {
				$result_list = Events::trigger(
					'shop',
					'before-checkout',
					$payment_method->get_name(),
					$return_url,
					$cancel_url
				);

				foreach ($result_list as $result)
					if ($result) {
						$this->showCheckoutRedirect();
						return;
					}
			}

			// create new payment
			switch ($transaction_type) {
				case TransactionType::SUBSCRIPTION:
					// recurring payment
					$checkout_fields = $payment_method->new_recurring_payment(
						$summary,
						$billing_information,
						$_SESSION['recurring_plan'],
						$return_url,
						$cancel_url
					);
					break;

				case TransactionType::DELAYED:
					// regular payment
					$checkout_fields = $payment_method->new_delayed_payment(
						$summary,
						$billing_information,
						$summary['items_for_checkout'],
						$return_url,
						$cancel_url
					);
					break;

				case TransactionType::REGULAR:
				default:
					// regular payment
					$checkout_fields = $payment_method->new_payment(
						$summary,
						$billing_information,
						$summary['items_for_checkout'],
						$return_url,
						$cancel_url
					);
					break;
			}

			// load template
			$template = $this->loadTemplate($tag_params, 'checkout_form.xml', 'checkout_template');
			$template->setTemplateParamsFromArray($children);
			$template->registerTagHandler('cms:checkout_items', $this, 'tag_CheckoutItems');
			$template->registerTagHandler('cms:delivery_methods', $this, 'tag_DeliveryMethodsList');

			// parse template
			$params = array(
				'checkout_url'		=> $payment_method->get_url(),
				'checkout_fields'	=> $checkout_fields,
				'checkout_name'		=> $payment_method->get_title(),
				'currency'			=> $this->getDefaultCurrency(),
				'recurring'			=> $recurring,
				'include_shipping'	=> $include_shipping,
				'type'				=> $transaction_type
			);

			// for recurring plans add additional params
			if ($recurring) {
				$plans = $payment_method->get_recurring_plans();
				$plan_name = $_SESSION['recurring_plan'];

				$plan = $plans[$plan_name];

				$params['plan_name'] = $plan['name'];
				$params['plan_description'] = $this->formatRecurring(array(
					'price'			=> $plan['price'],
					'period'		=> $plan['interval_count'],
					'unit'			=> $plan['interval'],
					'setup'			=> $plan['setup_price'],
					'trial_period'	=> $plan['trial_count'],
					'trial_unit'	=> $plan['trial']
				));

			} else {
				$params['sub-total'] = number_format($summary['total'], 2);
				$params['shipping'] = number_format($summary['shipping'], 2);
				$params['handling'] = number_format($summary['handling'], 2);
				$params['total_weight'] = number_format($summary['weight'], 2);
				$params['total'] = number_format($summary['total'] + $summary['shipping'] + $summary['handling'], 2);
			}

			$template->restoreXML();
			$template->setLocalParams($params);
			$template->parse();

		} else {
			// no information available, show form
			$template = $this->loadTemplate($tag_params, 'buyer_information.xml');
			$template->setTemplateParamsFromArray($children);
			$template->registerTagHandler('cms:card_type', $this, 'tag_CardType');
			$template->registerTagHandler('cms:payment_method', $this, 'tag_PaymentMethod');
			$template->registerTagHandler('cms:payment_method_list', $this, 'tag_PaymentMethodsList');

			// get fixed country if set
			$fixed_country = '';
			if (isset($this->settings['fixed_country']))
				$fixed_country = $this->settings['fixed_country'];

			// get login retry count
			$retry_manager = LoginRetryManager::getInstance();
			$count = $retry_manager->getRetryCount();

			$params = array(
				'include_shipping'	=> $include_shipping,
				'fixed_country'		=> $fixed_country,
				'bad_fields'		=> $bad_fields,
				'recurring'			=> $recurring,
				'show_captcha'		=> $count > 3,
				'terms_link'		=> isset($_SESSION['buyer_terms_link']) ? $_SESSION['buyer_terms_link'] : null,
				'payment_method'	=> isset($tag_params['payment_method']) ? $tag_params['payment_method'] : null
			);

			$template->restoreXML();
			$template->setLocalParams($params);
			$template->parse();
		}
	}

	/**
	 * Handle drawing checkout items
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_CheckoutItems($tag_params, $children) {
		global $language;

		$manager = ShopItemManager::getInstance();
		$cart = isset($_SESSION['shopping_cart']) ? $_SESSION['shopping_cart'] : array();
		$ids = array_keys($cart);
		$transaction_type = $this->getTransactionType();

		// get items from database
		$items = $manager->getItems($manager->getFieldNames(), array('uid' => $ids));
		$items_by_uid = array();
		$items_for_checkout = array();

		// parse items from database
		foreach ($items as $item) {
			$db_item = array(
				'name'		=> $item->name,
				'price'		=> $item->price,
				'tax'		=> $item->tax,
				'weight'	=> $item->weight
			);
			$items_by_uid[$item->uid] = $db_item;
		}

		// prepare items for checkout
		foreach ($cart as $uid => $item) {
			if (count($item['variations']) > 0)
				foreach($item['variations'] as $variation_id => $data) {
					// add items to checkout list
					$properties = $data;

					foreach ($this->excluded_properties as $key)
						if (isset($properties[$key]))
							unset($properties[$key]);

					$new_item = $items_by_uid[$uid];
					$new_item['count'] = $data['count'];
					$new_item['description'] = implode(', ', array_values($properties));
					$new_item['total'] = number_format(($new_item['price'] * (1 + ($new_item['tax'] / 100))) * $new_item['count'], 2);
					$new_item['tax'] = number_format($new_item['price'], 2);
					$new_item['price'] = number_format($new_item['tax'], 2);
					$new_item['weight'] = number_format($new_item['weight'], 2);
					$new_item['transaction_type'] = $transaction_type;

					// add item to the list
					$items_for_checkout[] = $new_item;
				}
		}

		// load template
		$template = $this->loadTemplate($tag_params, 'checkout_form_item.xml');
		$template->setTemplateParamsFromArray($children);

		// parse template
		if (count($items_for_checkout) > 0)
			foreach ($items_for_checkout as $params) {
				$template->setLocalParams($params);
				$template->restoreXML();
				$template->parse();
			}
	}

	/**
	 * Show message for completed checkout operation
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_CompletedMessage($tag_params, $children) {
		// show message
		$template = $this->loadTemplate($tag_params, 'checkout_message.xml');
		$template->setTemplateParamsFromArray($children);

		// get message to show
		$message = Language::getText('message_checkout_completed');
		if (empty($message))
			$message = $this->getLanguageConstant('message_checkout_completed');

		// prepare template parameters
		$params = array(
				'message'		=> $message,
				'button_text'	=> $this->getLanguageConstant('button_take_me_back'),
				'button_action'	=> url_Make('', 'home'),
				'redirect'		=> false
			);

		$template->restoreXML();
		$template->setLocalParams($params);
		$template->parse();
	}

	/**
	 * Show message for canceled checkout operation
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_CanceledMessage($tag_params, $children) {
		// show message
		$template = $this->loadTemplate($tag_params, 'checkout_message.xml');
		$template->setTemplateParamsFromArray($children);

		// get message to show
		$message = Language::getText('message_checkout_canceled');
		if (empty($message))
			$message = $this->getLanguageConstant('message_checkout_canceled');

		// prepare template parameters
		$params = array(
				'message'		=> $message,
				'button_text'	=> $this->getLanguageConstant('button_take_me_back'),
				'button_action'	=> url_Make('', 'home'),
				'redirect'		=> false
			);

		$template->restoreXML();
		$template->setLocalParams($params);
		$template->parse();
	}

	/**
	 * Show currently selected or specified payment method.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_PaymentMethod($tag_params, $children) {
		$method = null;
		$only_recurring = isset($_SESSION['recurring_plan']) && !empty($_SESSION['recurring_plan']);

		// get predefined method
		$name = null;

		if (isset($tag_params['name']))
			$name = escape_chars($tag_params['name']);

		// make sure method exists
		if (!isset($this->payment_methods[$name]))
			return;

		$method = $this->payment_methods[$name];

		// make sure method fits requirement
		if ($only_recurring && !$method->supports_recurring())
			return;

		// prepare parameters
		$params = array(
			'name'					=> $method->get_name(),
			'title'					=> $method->get_title(),
			'icon'					=> $method->get_icon_url(),
			'image'					=> $method->get_image_url(),
			'provides_information'	=> $method->provides_information()
		);

		// load and parse template
		$template = $this->loadTemplate($tag_params, 'payment_method.xml');
		$template->setTemplateParamsFromArray($children);
		$template->restoreXML();
		$template->setLocalParams($params);
		$template->parse();
	}

	/**
	 * Show list of payment methods.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_PaymentMethodsList($tag_params, $children) {
		$template = $this->loadTemplate($tag_params, 'payment_method.xml');
		$template->setTemplateParamsFromArray($children);
		$only_recurring = isset($_SESSION['recurring_plan']) && !empty($_SESSION['recurring_plan']);

		if (count($this->payment_methods) > 0)
			foreach ($this->payment_methods as $name => $module)
				if (($only_recurring && $module->supports_recurring()) || !$only_recurring) {
					$params = array(
						'name'					=> $name,
						'title'					=> $module->get_title(),
						'icon'					=> $module->get_icon_url(),
						'image'					=> $module->get_image_url(),
						'provides_information'	=> $module->provides_information()
					);

					$template->restoreXML();
					$template->setLocalParams($params);
					$template->parse();
				}
	}

	/**
	 * Show list of delivery methods.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_DeliveryMethodsList($tag_params, $children) {
		$template = $this->loadTemplate($tag_params, 'delivery_method.xml');
		$template->setTemplateParamsFromArray($children);
		$selected = Delivery::get_current_name();

		if (Delivery::method_count() > 0)
			foreach(Delivery::get_printable_list() as $name => $data) {
				$params = $data;
				$params['selected'] = ($selected == $name);

				$template->restoreXML();
				$template->setLocalParams($params);
				$template->parse();
			}
	}

	/**
	 * Handle drawing recurring payment cycle units.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_CycleUnit($tag_params, $children) {
		$units = array(
			RecurringPayment::DAY 	=> $this->getLanguageConstant('cycle_day'),
			RecurringPayment::WEEK	=> $this->getLanguageConstant('cycle_week'),
			RecurringPayment::MONTH	=> $this->getLanguageConstant('cycle_month'),
			RecurringPayment::YEAR	=> $this->getLanguageConstant('cycle_year')
		);

		$selected = isset($tag_params['selected']) ? fix_id($tag_params['selected']) : null;
		$template = $this->loadTemplate($tag_params, 'cycle_unit_option.xml');
		$template->setTemplateParamsFromArray($children);

		foreach($units as $id => $text) {
			$params = array(
				'id'		=> $id,
				'text'		=> $text,
				'selected'	=> $id == $selected
			);

			$template->restoreXML();
			$template->setLocalParams($params);
			$template->parse();
		}
	}

	/**
	 * Handle drawing of supported credit cards.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_CardType($tag_params, $children) {
		$template = $this->loadTemplate($tag_params, 'card_type.xml');
		$template->setTemplateParamsFromArray($children);

		foreach (CardType::$names as $id => $name) {
			$params = array(
				'id'	=> $id,
				'name'	=> $name
			);

			$template->restoreXML();
			$template->setLocalParams($params);
			$template->parse();
		}
	}

	/**
	 * Function that returns boolean denoting if shop is in testing phase.
	 *
	 * @return boolean
	 */
	public function isDebug() {
		$result = true;

		if (isset($this->settings['testing_mode']))
			$result = $this->settings['testing_mode'] == 1;

		return $result;
	}

	/**
	 * Format recurring plan description string.
	 *
	 * $params = array(
	 *			'price'			=> 2.99,
	 *			'period'		=> 1,
	 *			'unit'			=> RecurringPayment::DAY,
	 *			'setup'			=> 0.99,
	 *			'trial_period'	=> 0,
	 *			'trial_unit'	=> RecurringPayment::WEEK
	 *		);
	 *
	 * @param array $params
	 * @return string
	 */
	public function formatRecurring($params) {
		$units = array(
			RecurringPayment::DAY 	=> mb_strtolower($this->getLanguageConstant('cycle_day')),
			RecurringPayment::WEEK	=> mb_strtolower($this->getLanguageConstant('cycle_week')),
			RecurringPayment::MONTH	=> mb_strtolower($this->getLanguageConstant('cycle_month')),
			RecurringPayment::YEAR	=> mb_strtolower($this->getLanguageConstant('cycle_year'))
		);

		$template = $this->getLanguageConstant('recurring_description');
		$zero_word = $this->getLanguageConstant('recurring_period_zero');
		$currency = $this->getDefaultCurrency();

		$price = $params['price'].' '.$currency;
		$period = $params['period'].' '.$units[$params['unit']];
		$setup = $params['setup'] == 0 ? $zero_word : $params['setup'].' '.$currency;
		$trial_period = $params['trial_period'] == 0 ? $zero_word : $params['trial_period'].' '.$units[$params['trial_unit']];

		$result = str_replace(
			array('{price}', '{period}', '{setup}', '{trial_period}'),
			array($price, $period, $setup, $trial_period),
			$template
		);

		return $result;
	}
}
