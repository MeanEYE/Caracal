<?php

require_once('delivery_methods_manager.php');

class ShopDeliveryMethodsHandler {
	private static $_instance;
	private $_parent;
	private $name;
	private $path;

	/**
	* Constructor
	*/
	protected function __construct($parent) {
		$this->_parent = $parent;
		$this->name = $this->_parent->name;
		$this->path = $this->_parent->path;
	}

	/**
	* Public function that creates a single instance
	*/
	public static function get_instance($parent) {
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
	public function transfer_control($params = array(), $children = array()) {
		$action = isset($params['sub_action']) ? $params['sub_action'] : null;

		switch ($action) {
			case 'add':
				$this->addMethod();
				break;

			case 'change':
				$this->changeMethod();
				break;

			case 'save':
				$this->saveMethod();
				break;

			case 'delete':
				$this->deleteMethod();
				break;

			case 'delete_commit':
				$this->deleteMethod_Commit();
				break;

			case 'prices':
				$this->showPrices();
				break;

			case 'add_price':
				$this->addPrice();
				break;

			case 'change_price':
				$this->changePrice();
				break;

			case 'save_price':
				$this->savePrice();
				break;

			case 'delete_price':
				$this->deletePrice();
				break;

			case 'delete_price_commit':
				$this->deletePrice_Commit();
				break;

			default:
				$this->showMethods();
				break;
		}
	}

	/**
	 * Show delivery methods form
	 */
	private function showMethods() {
		$template = new TemplateHandler('delivery_methods_list.xml', $this->path.'templates/');

		$params = array(
					'link_new' => URL::make_hyperlink(
										$this->_parent->get_language_constant('add_delivery_method'),
										window_Open( // on click open window
											'shop_delivery_method_add',
											370,
											$this->_parent->get_language_constant('title_delivery_method_add'),
											true, true,
											backend_UrlMake($this->name, 'delivery_methods', 'add')
										)
									)
				);

		// register tag handler
		$template->register_tag_handler('cms:delivery_methods', $this, 'tag_DeliveryMethodsList');

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show form for adding new delivery method
	 */
	private function addMethod() {
		$template = new TemplateHandler('delivery_method_add.xml', $this->path.'templates/');

		$params = array(
					'form_action'	=> backend_UrlMake($this->name, 'delivery_methods', 'save'),
					'cancel_action'	=> window_Close('shop_delivery_method_add')
				);

		$template->set_local_params($params);
		$template->restore_xml();
		$template->parse();
	}

	/**
	 * Show form for changing delivery method data
	 */
	private function changeMethod() {
		$id = fix_id($_REQUEST['id']);
		$manager = ShopDeliveryMethodsManager::get_instance();

		$method = $manager->get_single_item($manager->get_field_names(), array('id' => $id));

		if (is_object($method)) {
			$template = new TemplateHandler('delivery_method_change.xml', $this->path.'templates/');

			$params = array(
					'id'			=> $method->id,
					'name'			=> $method->name,
					'international'	=> $method->international,
					'domestic'		=> $method->domestic,
					'form_action'	=> backend_UrlMake($this->name, 'delivery_methods', 'save'),
					'cancel_action'	=> window_Close('shop_delivery_method_change')
				);

			$template->set_local_params($params);
			$template->restore_xml();
			$template->parse();
		}
	}

	/**
	 * Save new or changed method data
	 */
	private function saveMethod() {
		$manager = ShopDeliveryMethodsManager::get_instance();
		$id = isset($_REQUEST['id']) ? fix_id($_REQUEST['id']) : null;

		$data = array(
				'name'			=> $this->_parent->get_multilanguage_field('name'),
				'international'	=> $this->_parent->get_boolean_field('international') ? 1 : 0,
				'domestic'		=> $this->_parent->get_boolean_field('domestic') ? 1 : 0,
			);

		if (is_null($id)) {
			$manager->insert_item($data);
			$window = 'shop_delivery_method_add';

		} else {
			$manager->update_items($data, array('id' => $id));
			$window = 'shop_delivery_method_change';
		}

		// show message
		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'message'	=> $this->_parent->get_language_constant('message_delivery_method_saved'),
					'button'	=> $this->_parent->get_language_constant('close'),
					'action'	=> window_Close($window).";".window_ReloadContent('shop_delivery_methods')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show confirmation form for removing delivery method
	 */
	private function deleteMethod() {
		global $language;

		$manager = ShopDeliveryMethodsManager::get_instance();
		$id = fix_id($_REQUEST['id']);

		$method = $manager->get_single_item(array('name'), array('id' => $id));

		$template = new TemplateHandler('confirmation.xml', $this->path.'templates/');
		$template->set_mapped_module($this->_parent->name);

		$params = array(
					'message'		=> $this->_parent->get_language_constant("message_delivery_method_delete"),
					'name'			=> $method->name[$language],
					'yes_text'		=> $this->_parent->get_language_constant("delete"),
					'no_text'		=> $this->_parent->get_language_constant("cancel"),
					'yes_action'	=> window_LoadContent(
											'shop_delivery_method_delete',
											URL::make_query(
												'backend_module',
												'transfer_control',
												array('module', $this->name),
												array('backend_action', 'delivery_methods'),
												array('sub_action', 'delete_commit'),
												array('id', $id)
											)
										),
					'no_action'		=> window_Close('shop_delivery_method_delete')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Perform delivery method removal
	 */
	private function deleteMethod_Commit() {
		$manager = ShopDeliveryMethodsManager::get_instance();
		$prices_manager = ShopDeliveryMethodPricesManager::get_instance();
		$relations_manager = ShopDeliveryItemRelationsManager::get_instance();
		$id = fix_id($_REQUEST['id']);

		$prices = $prices_manager->get_items(array('id'), array('method' => $id));
		$id_list = array();

		if (count($prices) > 0)
			foreach ($prices as $price)
				$id_list[] = $price->id;

		$manager->delete_items(array('id' => $id));
		$prices_manager->delete_items(array('method' => $id));
		$relations_manager->delete_items(array('price' => $id_list));

		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->set_mapped_module($this->_parent->name);

		$params = array(
					'message'	=> $this->_parent->get_language_constant("message_delivery_method_deleted"),
					'button'	=> $this->_parent->get_language_constant("close"),
					'action'	=> window_Close('shop_delivery_method_delete').";".window_ReloadContent('shop_delivery_methods')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show list of prices for specified method
	 */
	private function showPrices() {
		$manager = ShopDeliveryMethodPricesManager::get_instance();
		$id = fix_id($_REQUEST['id']);

		$params = array(
				'method'	=> $id,
				'link_new' => URL::make_hyperlink(
									$this->_parent->get_language_constant('add_delivery_price'),
									window_Open( // on click open window
										'shop_delivery_price_add',
										370,
										$this->_parent->get_language_constant('title_delivery_method_price_add'),
										true, true,
										URL::make_query(
											'backend_module',
											'transfer_control',
											array('module', $this->name),
											array('backend_action', 'delivery_methods'),
											array('sub_action', 'add_price'),
											array('id', $id)
										)
									)
								)
			);

		$template = new TemplateHandler('delivery_method_prices_list.xml', $this->path.'templates/');

		// register tag handler
		$template->register_tag_handler('cms:delivery_prices', $this, 'tag_DeliveryPricesList');

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show form for adding price to a method
	 */
	private function addPrice() {
		$template = new TemplateHandler('delivery_method_price_add.xml', $this->path.'templates/');

		$params = array(
			'method'		=> fix_id($_REQUEST['id']),
			'form_action'	=> backend_UrlMake($this->name, 'delivery_methods', 'save_price'),
			'cancel_action'	=> window_Close('shop_delivery_price_add')
		);

		$template->set_local_params($params);
		$template->restore_xml();
		$template->parse();
	}

	/**
	 * Show form for changing price
	 */
	private function changePrice() {
		$manager = ShopDeliveryMethodPricesManager::get_instance();
		$id = fix_id($_REQUEST['id']);
		$item = $manager->get_single_item($manager->get_field_names(), array('id' => $id));

		$template = new TemplateHandler('delivery_method_price_change.xml', $this->path.'templates/');

		$params = array(
			'id'			=> $item->id,
			'value'			=> $item->value,
			'method'		=> $item->method,
			'form_action'	=> backend_UrlMake($this->name, 'delivery_methods', 'save_price'),
			'cancel_action'	=> window_Close('shop_delivery_price_change')
		);

		$template->set_local_params($params);
		$template->restore_xml();
		$template->parse();
	}

	/**
	 * Save new price for delivery method
	 */
	private function savePrice() {
		$id = isset($_REQUEST['id']) ? fix_id($_REQUEST['id']) : null;
		$manager = ShopDeliveryMethodPricesManager::get_instance();

		$data = array(
			'value'		=> fix_chars($_REQUEST['value'])
		);

		// method is optional when editing
		if (isset($_REQUEST['method']))
			$data['method'] = fix_id($_REQUEST['method']);

		if (is_null($id)) {
			$manager->insert_item($data);
			$window = 'shop_delivery_price_add';

		} else {
			$manager->update_items($data, array('id' => $id));
			$window = 'shop_delivery_price_change';
		}

		// show message
		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'message'	=> $this->_parent->get_language_constant('message_delivery_price_saved'),
					'button'	=> $this->_parent->get_language_constant('close'),
					'action'	=> window_Close($window).";".window_ReloadContent('shop_delivery_method_prices')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show confirmation form for price removal
	 */
	private function deletePrice() {
		global $language;

		$manager = ShopDeliveryMethodPricesManager::get_instance();
		$id = fix_id($_REQUEST['id']);

		$item = $manager->get_single_item(array('value'), array('id' => $id));

		$template = new TemplateHandler('confirmation.xml', $this->path.'templates/');
		$template->set_mapped_module($this->_parent->name);

		$params = array(
					'message'		=> $this->_parent->get_language_constant("message_delivery_price_delete"),
					'name'			=> $item->value,
					'yes_text'		=> $this->_parent->get_language_constant("delete"),
					'no_text'		=> $this->_parent->get_language_constant("cancel"),
					'yes_action'	=> window_LoadContent(
											'shop_delivery_price_delete',
											URL::make_query(
												'backend_module',
												'transfer_control',
												array('module', $this->name),
												array('backend_action', 'delivery_methods'),
												array('sub_action', 'delete_price_commit'),
												array('id', $id)
											)
										),
					'no_action'		=> window_Close('shop_delivery_price_delete')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Perform price removal
	 */
	private function deletePrice_Commit() {
		$manager = ShopDeliveryMethodPricesManager::get_instance();
		$relations_manager = ShopDeliveryItemRelationsManager::get_instance();
		$id = fix_id($_REQUEST['id']);

		$manager->delete_items(array('id' => $id));
		$relations_manager->delete_items(array('price' => $id));

		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->set_mapped_module($this->_parent->name);

		$params = array(
					'message'	=> $this->_parent->get_language_constant("message_delivery_price_deleted"),
					'button'	=> $this->_parent->get_language_constant("close"),
					'action'	=> window_Close('shop_delivery_price_delete').";".window_ReloadContent('shop_delivery_method_prices')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Handle drawing list of delivery methods
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_DeliveryMethodsList($tag_params, $children) {
		$manager = ShopDeliveryMethodsManager::get_instance();
		$conditions = array();
		$item_id = -1;
		$selected = -1;

		if (isset($tag_params['item']))
			$item_id = fix_id($tag_params['item']);

		if (isset($tag_params['selected']))
			$selected = fix_id($tag_params['selected']);

		// delivery method list needs to be filtered by the items in shooping cart
		if (isset($tag_params['shopping_cart']) && $tag_params['shopping_cart'] == 1) {
			$relations_manager = ShopDeliveryItemRelationsManager::get_instance();
			$prices_manager = ShopDeliveryMethodPricesManager::get_instance();
			$items_manager = ShopItemManager::get_instance();

			$cart = isset($_SESSION['shopping_cart']) ? $_SESSION['shopping_cart'] : array();
			$uid_list = array_keys($cart);

			if (count($uid_list) == 0)
				return;

			// shopping cart contains only UIDs, we need IDs
			$id_list = array();
			$items = $items_manager->get_items(array('id'), array('uid' => $uid_list));

			if (count($items) > 0)
				foreach ($items as $item)
					$id_list[] = $item->id;

			// get item relations to delivery methods
			$relations = $relations_manager->get_items(
								$relations_manager->get_field_names(),
								array('item' => $id_list)
							);

			$price_list = array();
			$price_count = array();

			if (count($relations) > 0)
				foreach ($relations as $relation) {
					$price_list[] = $relation->price;

					if (!array_key_exists($relation->price, $price_count))
						$price_count[$relation->price] = 0;

					// store number of times price is used
					$price_count[$relation->price]++;
				}

			$relations = $prices_manager->get_items(array('id', 'method'), array('id' => $price_list));
			$method_count = array();

			if (count($relations) > 0)
				foreach ($relations as $relation) {
					$key = $relation->method;

					if (!array_key_exists($key, $method_count))
						$method_count[$key] = 0;

					// increase method usage count with number of price usages
					$method_count[$key] += $price_count[$relation->id];
				}

			// We compare number of items with method associated with
			// that item. Methods that have number same as number of items
			// are supported and we include them in list.
			$border_count = count($id_list);
			$valid_methods = array();

			if (count($method_count) > 0)
				foreach ($method_count as $id => $count)
					if ($count == $border_count)
						$valid_methods[] = $id;

			if (count($valid_methods) > 0)
				$conditions['id'] = $valid_methods; else
				$conditions ['id'] = -1;

			// filter by location
			$shop_location = isset($this->_parent->settings['shop_location']) ? $this->_parent->settings['shop_location'] : '';

			if (!empty($shop_location)) {
				$same_country = $shop_location == $_SESSION['buyer']['country'];

				if ($same_country)
					$conditions['domestic'] = 1; else
					$conditions['international'] = 1;
			}
		}

		// get template
		$template = $this->_parent->load_template($tag_params, 'delivery_methods_list_item.xml');
		$template->set_template_params_from_array($children);
		$template->register_tag_handler('cms:price_list', $this, 'tag_DeliveryPricesList');

		// get items from database
		$items = $manager->get_items($manager->get_field_names(), $conditions);

		if (count($items) > 0)
			foreach($items as $item) {
				$params = array(
					'id'					=> $item->id,
					'name'					=> $item->name,
					'international'			=> $item->international,
					'international_char'	=> $item->international ? CHAR_CHECKED : CHAR_UNCHECKED,
					'domestic'				=> $item->domestic,
					'domestic_char'			=> $item->domestic ? CHAR_CHECKED : CHAR_UNCHECKED,
					'item'					=> $item_id,
					'selected'				=> $selected == $item->id ? 1 : 0,
					'item_change'	=> URL::make_hyperlink(
						$this->_parent->get_language_constant('change'),
						window_Open(
							'shop_delivery_method_change', 	// window id
							370,				// width
							$this->_parent->get_language_constant('title_delivery_method_change'), // title
							true, true,
							URL::make_query(
								'backend_module',
								'transfer_control',
								array('module', $this->name),
								array('backend_action', 'delivery_methods'),
								array('sub_action', 'change'),
								array('id', $item->id)
							)
						)
					),
					'item_delete'	=> URL::make_hyperlink(
						$this->_parent->get_language_constant('delete'),
						window_Open(
							'shop_delivery_method_delete', 	// window id
							400,				// width
							$this->_parent->get_language_constant('title_delivery_method_delete'), // title
							false, false,
							URL::make_query(
								'backend_module',
								'transfer_control',
								array('module', $this->name),
								array('backend_action', 'delivery_methods'),
								array('sub_action', 'delete'),
								array('id', $item->id)
							)
						)
					),
					'item_prices'	=> URL::make_hyperlink(
						$this->_parent->get_language_constant('prices'),
						window_Open(
							'shop_delivery_method_prices', 	// window id
							370,				// width
							$this->_parent->get_language_constant('title_delivery_method_prices'), // title
							true, false,
							URL::make_query(
								'backend_module',
								'transfer_control',
								array('module', $this->name),
								array('backend_action', 'delivery_methods'),
								array('sub_action', 'prices'),
								array('id', $item->id)
							)
						)
					)
				);

				$template->set_local_params($params);
				$template->restore_xml();
				$template->parse();
			}
	}

	/**
	 * Handle drawing list of delivery method prices
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_DeliveryPricesList($tag_params, $children) {
		$manager = ShopDeliveryMethodPricesManager::get_instance();
		$conditions = array();
		$relations = array();

		// prepare filtering conditions
		if (isset($tag_params['method']))
			$conditions['method'] = fix_id($tag_params['method']);

		if (isset($_REQUEST['method']))
			$conditions['method'] = fix_id($_REQUEST['method']);

		// get relations with shop item
		if (isset($tag_params['item'])) {
			$relations_manager = ShopDeliveryItemRelationsManager::get_instance();
			$item_id = fix_id($tag_params['item']);

			$raw_relations = $relations_manager->get_items(array('price'), array('item' => $item_id));

			if (count($raw_relations) > 0)
				foreach ($raw_relations as $relation)
					$relations[] = $relation->price;
		}

		// get template
		$template = $this->_parent->load_template($tag_params, 'delivery_method_prices_list_item.xml');
		$template->set_template_params_from_array($children);

		// get items from database
		$items = $manager->get_items($manager->get_field_names(), $conditions);

		if (count($items) > 0)
			foreach ($items as $item) {
				$params = array(
						'id'		=> $item->id,
						'value'		=> $item->value,
						'method'	=> isset($conditions['method']) ? $conditions['method'] : 0,
						'selected'	=> in_array($item->id, $relations) ? 1 : 0,
						'item_change'	=> URL::make_hyperlink(
							$this->_parent->get_language_constant('change'),
							window_Open(
								'shop_delivery_price_change', 	// window id
								370,				// width
								$this->_parent->get_language_constant('title_delivery_method_price_change'), // title
								true, true,
								URL::make_query(
									'backend_module',
									'transfer_control',
									array('module', $this->name),
									array('backend_action', 'delivery_methods'),
									array('sub_action', 'change_price'),
									array('id', $item->id)
								)
							)
						),
						'item_delete'	=> URL::make_hyperlink(
							$this->_parent->get_language_constant('delete'),
							window_Open(
								'shop_delivery_price_delete', 	// window id
								400,				// width
								$this->_parent->get_language_constant('title_delivery_method_price_delete'), // title
								false, false,
								URL::make_query(
									'backend_module',
									'transfer_control',
									array('module', $this->name),
									array('backend_action', 'delivery_methods'),
									array('sub_action', 'delete_price'),
									array('id', $item->id)
								)
							)
						),
					);

				$template->set_local_params($params);
				$template->restore_xml();
				$template->parse();
			}
	}
}

?>
