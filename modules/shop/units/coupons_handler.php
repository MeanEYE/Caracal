<?php

/**
 * Handler class for coupon related operations.
 */
namespace Modules\Shop\Promotion;

require_once('coupons_manager.php');

use \TemplateHandler as TemplateHandler;


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
			$backend = \backend::getInstance();
			$method_menu = $backend->getMenu('shop_special_offers');

			if (!is_null($method_menu))
				$method_menu->addChild('', new \backend_MenuItem(
									$this->parent->getLanguageConstant('menu_coupons'),
									url_GetFromFilePath($this->path.'images/coupons.svg'),

									window_Open( // on click open window
												'shop_coupons',
												450,
												$this->parent->getLanguageConstant('title_coupons'),
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
			case 'add':
				$this->add_coupon();
				break;

			case 'change':
				$this->change_coupon();
				break;

			case 'save':
				$this->save_coupon();
				break;

			case 'delete':
				$this->delete_coupon();
				break;

			case 'delete_commit':
				$this->delete_coupon_commit();
				break;

			case 'show':
			default:
				$this->show_coupons();
				break;
		}
	}

	/**
	 * Show coupons management form.
	 */
	private function show_coupons() {
		$template = new TemplateHandler('coupon_list.xml', $this->path.'templates/');

		$params = array(
					'link_new' => url_MakeHyperlink(
										$this->parent->getLanguageConstant('add_coupon'),
										window_Open( // on click open window
											'shop_coupon_add',
											430,
											$this->parent->getLanguageConstant('title_coupon_add'),
											true, true,
											backend_UrlMake($this->name, self::SUB_ACTION, 'add')
										)
									)
					);

		// register tag handler
		$template->registerTagHandler('cms:coupon_list', $this, 'tag_CouponList');

		$template->restoreXML();
		$template->setLocalParams($params);
		$template->parse();
	}

	/**
 	 * Show form for adding coupons.
	 */
	private function add_coupon() {
		$template = new TemplateHandler('coupon_add.xml', $this->path.'templates/');
		$template->setMappedModule($this->name);

		$params = array(
					'form_action'	=> backend_UrlMake($this->name, self::SUB_ACTION, 'save'),
					'cancel_action'	=> window_Close('shop_coupon_add')
				);

		$template->restoreXML();
		$template->setLocalParams($params);
		$template->parse();
	}

	/**
	 * Show form for changing coupons.
	 */
	private function change_coupon() {
	}

	/**
	 * Save new or changed coupon data.
	 */
	private function save_coupon() {
		$id = isset($_REQUEST['id']) ? fix_id($_REQUEST['id']) : null;
		$data = array(
				'text_id'     => escape_chars($_REQUEST['text_id']),
				'name'        => $this->parent->getMultilanguageField('name'),
				'has_limit'   => $this->parent->getBooleanField('has_limit') ? 1 : 0,
				'has_timeout' => $this->parent->getBooleanField('has_timeout') ? 1 : 0,
				'limit'       => fix_id($_REQUEST['limit']),
				'timeout'     => escape_chars($_REQUEST['timeout']),
				'discount'    => fix_id($_REQUEST['discount'])
			);

		if (is_null($id)) {
			$window = 'shop_coupon_add';
			$manager->insertData($data);
		} else {
			$window = 'shop_coupon_change';
			$manager->updateData($data,	array('id' => $id));
		}

		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->setMappedModule($this->name);

		$params = array(
					'message'	=> $this->getLanguageConstant('message_coupon_saved'),
					'button'	=> $this->getLanguageConstant('close'),
					'action'	=> window_Close($window).';'.window_ReloadContent('shop_coupons'),
				);

		$template->restoreXML();
		$template->setLocalParams($params);
		$template->parse();
	}

	/**
	 * Show confirmation form before removing coupon.
	 */
	private function delete_coupon() {
	}

	/**
 	 * Perform coupon data removal.
	 */
	private function delete_coupon_commit() {
	}

	/**
	 * Render single coupon tag.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_Coupon($tag_params, $children) {
	}

	/**
	 * Render coupon list tag.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_CouponList($tag_params, $children) {
	}
}

?>
