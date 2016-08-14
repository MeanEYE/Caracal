<?php

/**
 * Shop Referrals Support
 *
 * Copyright (c) 2013. by Way2CU
 * Author: Mladen Mijatov
 */
use Core\Module;

require_once('units/affiliate_manager.php');
require_once('units/referrals_manager.php');


class affiliates extends Module {
	private static $_instance;

	/**
	 * Constructor
	 */
	protected function __construct() {
		global $section;

		parent::__construct(__FILE__);

		// register backend
		if ($section == 'backend' && ModuleHandler::is_loaded('backend')) {
			$backend = backend::get_instance();

			$referrals_menu = new backend_MenuItem(
					$this->get_language_constant('menu_affiliates'),
					URL::from_file_path($this->path.'images/icon.svg'),
					'javascript:void(0);',
					$level=5
				);
			$referrals_menu->addChild('', new backend_MenuItem(
								$this->get_language_constant('menu_manage_affiliates'),
								URL::from_file_path($this->path.'images/affiliates.svg'),

								window_Open( // on click open window
											'affiliates',
											700,
											$this->get_language_constant('title_affiliates'),
											true, true,
											backend_UrlMake($this->name, 'affiliates')
										),
								$level=10
							));
			$referrals_menu->addChild('', new backend_MenuItem(
								$this->get_language_constant('menu_referral_urls'),
								URL::from_file_path($this->path.'images/referrals.svg'),

								window_Open( // on click open window
											'referrals',
											750,
											$this->get_language_constant('title_referrals'),
											true, true,
											backend_UrlMake($this->name, 'referrals')
										),
								$level=4
							));
			$referrals_menu->addChild('', new backend_MenuItem(
								$this->get_language_constant('menu_information'),
								URL::from_file_path($this->path.'images/information.svg'),

								window_Open( // on click open window
											'affiliate_information',
											400,
											$this->get_language_constant('title_affiliate_information'),
											true, true,
											backend_UrlMake($this->name, 'information')
										),
								$level=4
							));

			$backend->addMenu($this->name, $referrals_menu);
		}

		if (isset($_REQUEST['affiliate']) && $section != 'backend' && $section != 'backend_module')
			$this->createReferral();
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
	public function transfer_control($params = array(), $children = array()) {
		// global control actions
		if (isset($params['action']))
			switch ($params['action']) {
				default:
					break;
			}

		// global control actions
		if (isset($params['backend_action']))
			switch ($params['backend_action']) {
				case 'affiliates':
					$this->showAffiliates();
					break;

				case 'affiliate_add':
					$this->addAffiliate();
					break;

				case 'affiliate_change':
					$this->changeAffiliate();
					break;

				case 'affiliate_save':
					$this->saveAffiliate();
					break;

				case 'affiliate_delete':
					$this->deleteAffiliate();
					break;

				case 'affiliate_delete_commit':
					$this->deleteAffiliate_Commit();
					break;

				case 'referrals':
					$this->showReferrals();
					break;

				case 'information':
					$this->showAffiliateInformation();
					break;

				default:
					break;
			}
	}

	/**
	 * Event triggered upon module initialization
	 */
	public function on_init() {
		global $db;

		$sql = "CREATE TABLE IF NOT EXISTS `affiliates` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
					`uid` varchar(30) NOT NULL,
					`name` varchar(50) NOT NULL,
					`user` int(11) NOT NULL,
					`clicks` int(11) NOT NULL DEFAULT '0',
					`conversions` int(11) NOT NULL DEFAULT '0',
					`active` tinyint(1) NOT NULL DEFAULT '1',
					`default` tinyint(1) NOT NULL DEFAULT '0',
					PRIMARY KEY (`id`),
					INDEX(`uid`),
					INDEX(`user`),
					INDEX(`default`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=1 ;";
		$db->query($sql);

		$sql = "CREATE TABLE IF NOT EXISTS `affiliate_referrals` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
					`affiliate` int(11) NOT NULL,
					`url` varchar(255) NOT NULL,
					`landing` varchar(255) NOT NULL,
					`transaction` int(11) NOT NULL,
					`conversion` tinyint(1) NOT NULL,
					PRIMARY KEY (`id`),
					INDEX(`affiliate`),
					INDEX(`url`),
					INDEX(`landing`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=1 ;";
		$db->query($sql);
	}

	/**
	 * Event triggered upon module deinitialization
	 */
	public function on_disable() {
		global $db;

		$tables = array('affiliates', 'affiliate_referrals');
		$db->drop_tables($tables);
	}

	/**
	 * Show list of affiliates
	 */
	private function showAffiliates() {
		if ($_SESSION['level'] < 10)
			die('Access denied!');

		$template = new TemplateHandler('list.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'link_new'		=> window_OpenHyperlink(
										$this->get_language_constant('new_affiliate'),
										'affiliates_new', 370,
										$this->get_language_constant('title_affiliates_add'),
										true, false,
										$this->name,
										'affiliate_add'
									),
					);

		$template->register_tag_handler('_affiliate_list', $this, 'tag_AffiliateList');
		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show form for adding new affiliate.
	 */
	private function addAffiliate() {
		if ($_SESSION['level'] < 10)
			die('Access denied!');

		$template = new TemplateHandler('add.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		// connect tag handlers
		$user_manager = UserManager::get_instance();
		$template->register_tag_handler('_user_list', $user_manager, 'tag_UserList');

		// generate UID
		$uid = uniqid();

		$params = array(
					'uid'			=> $uid,
					'form_action'	=> backend_UrlMake($this->name, 'affiliate_save'),
					'cancel_action'	=> window_Close('affiliates_new')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();

	}

	/**
	 * Show form for changing existing affiliate.
	 */
	private function changeAffiliate() {
		if ($_SESSION['level'] < 10)
			die('Access denied!');

		$id = fix_id($_REQUEST['id']);
		$manager = AffiliatesManager::get_instance();

		$item = $manager->get_single_item($manager->get_field_names(), array('id' => $id));

		if (is_object($item)) {
			$template = new TemplateHandler('change.xml', $this->path.'templates/');
			$template->set_mapped_module($this->name);

			// connect tag handlers
			$user_manager = UserManager::get_instance();
			$template->register_tag_handler('_user_list', $user_manager, 'tag_UserList');

			$params = array(
						'id'			=> $item->id,
						'uid'			=> $item->uid,
						'name'			=> $item->name,
						'user'			=> $item->user,
						'active'		=> $item->active,
						'default'		=> $item->default,
						'form_action'	=> backend_UrlMake($this->name, 'affiliate_save'),
						'cancel_action'	=> window_Close('affiliates_change')
					);

			$template->restore_xml();
			$template->set_local_params($params);
			$template->parse();
		}
	}

	/**
	 * Save new or changed affiliate data.
	 */
	private function saveAffiliate() {
		if ($_SESSION['level'] < 10)
			die('Access denied!');

		$manager = AffiliatesManager::get_instance();

		$id = isset($_REQUEST['id']) ? fix_id($_REQUEST['id']) : null;
		$uid = escape_chars($_REQUEST['uid']);
		$user = fix_id($_REQUEST['user']);
		$name = fix_chars($_REQUEST['name']);
		$active = $this->get_boolean_field('active') ? 1 : 0;
		$default = $this->get_boolean_field('default') ? 1 : 0;

		$data = array(
				'name'		=> $name,
				'user'		=> $user,
				'active'	=> $active,
				'default'	=> $default
			);

		$existing_items = $manager->get_items(array('id'), array('uid' => $uid));

		if (is_null($id)) {
			if (count($existing_items) > 0 || empty($uid)) {
				// there are items with existing UID, show error
				$message = 'message_affiliate_not_unique';

			} else {
				// affiliate ID is unique, proceed
				$message = 'message_affiliate_saved';

				$data['uid'] = $uid;
				$manager->insert_item($data);
			}

			$window = 'affiliates_new';

		} else {
			// update existing record
			$window = 'affiliates_change';
			$message = 'message_affiliate_saved';
			$manager->update_items($data, array('id' => $id));
		}

		// show message
		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'message'	=> $this->get_language_constant($message),
					'button'	=> $this->get_language_constant('close'),
					'action'	=> window_Close($window).";".window_ReloadContent('affiliates'),
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show confirmation from before deleting affiliate.
	 */
	private function deleteAffiliate() {
		if ($_SESSION['level'] < 10)
			die('Access denied!');

		$id = fix_id($_REQUEST['id']);
		$manager = AffiliatesManager::get_instance();

		$item = $manager->get_single_item(array('name'), array('id' => $id));

		$template = new TemplateHandler('confirmation.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'message'		=> $this->get_language_constant("message_affiliate_delete"),
					'name'			=> $item->name,
					'yes_text'		=> $this->get_language_constant("delete"),
					'no_text'		=> $this->get_language_constant("cancel"),
					'yes_action'	=> window_LoadContent(
											'affiliates_delete',
											URL::make_query(
												'backend_module',
												'transfer_control',
												array('module', $this->name),
												array('backend_action', 'affiliate_delete_commit'),
												array('id', $id)
											)
										),
					'no_action'		=> window_Close('affiliates_delete')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Perform affiliate removal.
	 */
	private function deleteAffiliate_Commit() {
		if ($_SESSION['level'] < 10)
			die('Access denied!');

		$id = fix_id($_REQUEST['id']);
		$manager = AffiliatesManager::get_instance();
		$referrals_manager = AffiliateReferralsManager::get_instance();

		$manager->delete_items(array('id' => $id));
		$referrals_manager->delete_items(array('affiliate' => $id));

		// show message
		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'message'	=> $this->get_language_constant("message_affiliate_deleted"),
					'button'	=> $this->get_language_constant("close"),
					'action'	=> window_Close('affiliates_delete').";".window_ReloadContent('affiliates')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show referalls for current affiliate
	 */
	private function showReferrals() {
		$template = new TemplateHandler('referral_list.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		if (isset($_REQUEST['group_by']) && $_REQUEST['group_by'] == 'landing')
			$column = $this->get_language_constant('column_landing'); else
			$column = $this->get_language_constant('column_url');

		$params = array(
				'column_group_by'	=> $column,
			);

		$template->register_tag_handler('_referral_list', $this, 'tag_ReferralList');
		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show affiliate information.
	 */
	private function showAffiliateInformation() {
		global $url_rewrite;

		$manager = AffiliatesManager::get_instance();
		$user_id = $_SESSION['uid'];
		$affiliate = $manager->get_single_item($manager->get_field_names(), array('user' => $user_id));

		if (is_object($affiliate)) {
			$template = new TemplateHandler('information.xml', $this->path.'templates/');
			$template->set_mapped_module($this->name);

			if ($affiliate->clicks > 0)
				$rate = round((100 * $affiliate->conversions) / $affiliate->clicks, 2); else
				$rate = 0;

			$params = array(
					'uid'			=> $affiliate->uid,
					'name'			=> $affiliate->name,
					'clicks'		=> $affiliate->clicks,
					'conversions'	=> $affiliate->conversions,
					'rate'			=> $rate,
					'url_rewrite'	=> $url_rewrite ? 'true' : 'false',
					'cancel_action'	=> window_Close('affiliate_information')
				);

			$template->restore_xml();
			$template->set_local_params($params);
			$template->parse();
		}
	}

	/**
	 * Register new referral
	 *
	 * @return boolean
	 */
	private function createReferral() {
		$result = false;

		$manager = AffiliatesManager::get_instance();
		$referrals_manager = AffiliateReferralsManager::get_instance();

		// prepare data
		$uid = fix_chars($_REQUEST['affiliate']);
		$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
		$base_url = URL::get_base();
		$landing = url_MakeFromArray($_REQUEST);
		$landing = mb_substr($landing, 0, mb_strlen($base_url));

		// get affiliate
		$affiliate = $manager->get_single_item($manager->get_field_names(), array('uid' => $uid));

		// if affiliate code is not valid, assign to default affiliate
		if (!is_object($affiliate))
			$affiliate = $manager->get_single_item($manager->get_field_names(), array('default' => 1));

		// if affiliate exists, update
		if (is_object($affiliate) && !is_null($referer)) {
			$referral_data = array(
						'url'			=> $referer,
						'landing'		=> $landing,
						'affiliate'		=> $affiliate->id,
						'conversion'	=> 0
					);

			$referrals_manager->insert_item($data);
			$id = $referrals_manager->get_inserted_id();
			$_SESSION['referral_id'] = $id;

			// increase referrals counter
			$manager->update_items(
						array('clicks' => '`clicks` + 1'),
						array('id' => $affiliate->id)
					);

			$result = true;
		}

		return result;
	}

	/**
	 * Mark a referral as conversion.
	 */
	public function convertReferral($id) {
		$manager = AffiliatesManager::get_instance();
		$referrals_manager = AffiliateReferralsManager::get_instance();

		// get referral entry by specified id
		$referral = $referrals_manager->get_single_item(
								$referrals_manager->get_field_names(),
								array('id' => $id)
							);

		// referral entry is valid, update affiliate and referral record
		if (is_object($referral)) {
			$manager->update_items(
						array('conversions' => '`conversions` + 1'),
						array('id' => $referral->affiliate)
					);
			$referrals_manager->update_items(
						array('conversion' => 1),
						array('id' => $referral->id)
					);
		}
	}

	/**
	 * Tag handler for affiliate list.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_AffiliateList($tag_params, $children) {
		$manager = AffiliatesManager::get_instance();
		$conditions = array();

		// get items from database
		$items = $manager->get_items($manager->get_field_names(), $conditions);

		// load template
		$template = $this->load_template($tag_params, 'list_item.xml');
		$template->set_template_params_from_array($children);

		// parse template
		if (count($items) > 0)
			foreach ($items as $item) {
				if ($item->clicks > 0)
					$rate = round((100 * $item->conversions) / $item->clicks, 2); else
					$rate = 0;

				$params = array(
						'id'			=> $item->id,
						'uid'			=> $item->uid,
						'name'			=> $item->name,
						'clicks'		=> $item->clicks,
						'conversions'	=> $item->conversions,
						'rate'			=> $rate,
						'item_change'	=> URL::make_hyperlink(
												$this->get_language_constant('change'),
												window_Open(
													'affiliates_change', 	// window id
													370,				// width
													$this->get_language_constant('title_affiliates_change'), // title
													false, false,
													URL::make_query(
														'backend_module',
														'transfer_control',
														array('module', $this->name),
														array('backend_action', 'affiliate_change'),
														array('id', $item->id)
													)
												)
											),
						'item_delete'	=> URL::make_hyperlink(
												$this->get_language_constant('delete'),
												window_Open(
													'affiliates_delete', 	// window id
													400,				// width
													$this->get_language_constant('title_affiliates_delete'), // title
													false, false,
													URL::make_query(
														'backend_module',
														'transfer_control',
														array('module', $this->name),
														array('backend_action', 'affiliate_delete'),
														array('id', $item->id)
													)
												)
											),
					);

				$template->restore_xml();
				$template->set_local_params($params);
				$template->parse();
			}
	}

	/**
	 * Handle drawing referral list tag.
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_ReferralList($tag_params, $children) {
		$manager = AffiliateReferralsManager::get_instance();
		$conditions = array();
	}
}

?>
