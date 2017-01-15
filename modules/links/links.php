<?php

/**
 * Links Module
 *
 * This module provides a number of useful ways of printing and organising
 * links on your web site.
 *
 * Author: Mladen Mijatov
 */
use Core\Module;
use Core\Markdown;

require_once('units/manager.php');
require_once('units/group_manager.php');
require_once('units/membership_manager.php');


class links extends Module {
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

			$links_menu = new backend_MenuItem(
					$this->get_language_constant('menu_links'),
					URL::from_file_path($this->path.'images/icon.svg'),
					'javascript:void(0);',
					$level=5
				);

			$links_menu->addChild('', new backend_MenuItem(
								$this->get_language_constant('menu_links_manage'),
								URL::from_file_path($this->path.'images/manage.svg'),
								window_Open( // on click open window
											'links_list',
											720,
											$this->get_language_constant('title_links_manage'),
											true, true,
											backend_UrlMake($this->name, 'links_list')
										),
								$level=5
							));

			$links_menu->addChild('', new backend_MenuItem(
								$this->get_language_constant('menu_links_groups'),
								URL::from_file_path($this->path.'images/groups.svg'),
								window_Open( // on click open window
											'groups_list',
											500,
											$this->get_language_constant('title_groups_manage'),
											true, true,
											backend_UrlMake($this->name, 'groups_list')
										),
								$level=5
							));

			$links_menu->addChild('', new backend_MenuItem(
								$this->get_language_constant('menu_links_overview'),
								URL::from_file_path($this->path.'images/overview.svg'),
								window_Open( // on click open window
											'links_overview',
											650,
											$this->get_language_constant('title_links_overview'),
											true, true,
											backend_UrlMake($this->name, 'overview')
										),
								$level=6
							));

			$backend->addMenu($this->name, $links_menu);
		}
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
	public function transfer_control($params, $children) {
		// global control actions
		switch ($params['action']) {
			case 'show':
				$this->tag_Link($params, $children);
				break;

			case 'show_link_list':
				$this->tag_LinkList($params, $children);
				break;

			case 'show_group':
				$this->tag_Group($params, $children);
				break;

			case 'show_group_list':
				$this->tag_GroupList($params, $children);
				break;

			case 'json_link':
				$this->json_Link();
				break;

			case 'json_link_list':
				$this->json_LinkList();
				break;

			case 'json_group_list':
				$this->json_GroupList();
				break;

			case 'redirect':
				$this->redirectLink();
				break;

			default:
				break;
		}

		// global control actions
		if (isset($params['backend_action']))
			switch ($params['backend_action']) {
				case 'links_list':
					$this->showList();
					break;

				case 'links_add':
					$this->addLink();
					break;

				case 'links_change':
					$this->changeLink();
					break;

				case 'links_save':
					$this->saveLink();
					break;

				case 'links_delete':
					$this->deleteLink();
					break;

				case 'links_delete_commit':
					$this->deleteLink_Commit();
					break;

				case 'groups_list':
					$this->showGroups();
					break;

				case 'groups_add':
					$this->addGroup();
					break;

				case 'groups_change':
					$this->changeGroup();
					break;

				case 'groups_save':
					$this->saveGroup();
					break;

				case 'groups_delete':
					$this->deleteGroup();
					break;

				case 'groups_delete_commit':
					$this->deleteGroup_Commit();
					break;

				case 'groups_links':
					$this->groupLinks();
					break;

				case 'groups_links_save':
					$this->groupLinksSave();
					break;

				case 'overview':
					$this->showOverview();
					break;

				default:
					break;
			}
	}

	/**
	 * Event triggered upon module initialization
	 */
	public function initialize() {
		global $db;

		// get list of languages
		$list = Language::get_languages(false);

		// create main table for links
		$sql = "
			CREATE TABLE IF NOT EXISTS `links` (
				`id` INT NOT NULL AUTO_INCREMENT,
				`text_id` VARCHAR(32) NOT NULL,";

		foreach($list as $language)
			$sql .= "`text_{$language}` VARCHAR(50) NOT NULL DEFAULT '',";

		foreach($list as $language)
			$sql .= "`description_{$language}` TEXT NOT NULL ,";

		$sql .= "
				`url` VARCHAR(255) NOT NULL,
				`external` TINYINT NOT NULL DEFAULT '1',
				`sponsored` TINYINT NOT NULL DEFAULT '0',
				`display_limit` INT NOT NULL DEFAULT '0',
				`sponsored_clicks` INT NOT NULL DEFAULT '0',
				`total_clicks` INT NOT NULL DEFAULT '0',
				`image` INT,
				PRIMARY KEY (`id`),
				KEY `index_by_sponsored` (`sponsored`),
				KEY `index_by_text_id` (`text_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create table for link groups
		$sql = "
			CREATE TABLE IF NOT EXISTS `link_groups` (
				`id` INT NOT NULL AUTO_INCREMENT,";

		foreach($list as $language)
			$sql .= "`name_{$language}` VARCHAR(50) NOT NULL DEFAULT '',";

		$sql .= "
				`text_id` VARCHAR(32) NOT NULL,
				PRIMARY KEY (`id`),
				INDEX (`text_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);

		// create table for link membership
		$sql = "
			CREATE TABLE IF NOT EXISTS `link_membership` (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`link` int(11) NOT NULL,
				`group` int(11) NOT NULL,
				PRIMARY KEY (`id`),
				KEY `group` (`group`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;";
		$db->query($sql);
	}

	/**
	 * Event triggered upon module deinitialization
	 */
	public function cleanup() {
		global $db;

		$tables = array('links', 'link_groups', 'link_membership');
		$db->drop_tables($tables);
	}

	/**
	 * Show links window
	 */
	private function showList() {
		$template = new TemplateHandler('links_list.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'link_new'		=> window_OpenHyperlink(
										$this->get_language_constant('add'),
										'links_add', 600,
										$this->get_language_constant('title_links_add'),
										true, false,
										$this->name,
										'links_add'
									),
					'link_groups'	=> URL::make_hyperlink(
										$this->get_language_constant('groups'),
										window_Open( // on click open window
											'groups_list',
											500,
											$this->get_language_constant('title_groups_manage'),
											true, true,
											backend_UrlMake($this->name, 'groups_list')
										)
									),
					'link_overview'	=> URL::make_hyperlink(
										$this->get_language_constant('overview'),
										window_Open( // on click open window
											'links_overview',
											650,
											$this->get_language_constant('title_links_overview'),
											true, true,
											backend_UrlMake($this->name, 'links_overview')
										)
									)
					);

		$template->register_tag_handler('cms:link_list', $this, 'tag_LinkList');
		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show content of a form used for creation of new `link` object
	 */
	private function addLink() {
		$template = new TemplateHandler('links_add.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'with_images'	=> ModuleHandler::is_loaded('gallery'),
					'form_action'	=> backend_UrlMake($this->name, 'links_save'),
					'cancel_action'	=> window_Close('links_add')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show content of a form in editing state for sepected `link` object
	 */
	private function changeLink() {
		$id = fix_id($_REQUEST['id']);
		$manager = \Modules\Links\Manager::get_instance();

		$item = $manager->get_single_item($manager->get_field_names(), array('id' => $id));

		$template = new TemplateHandler('links_change.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'id'               => $item->id,
					'text'             => $item->text,
					'description'      => $item->description,
					'text_id'          => $item->text_id,
					'url'              => $item->url,
					'external'         => $item->external,
					'sponsored'        => $item->sponsored,
					'display_limit'    => $item->display_limit,
					'sponsored_clicks' => $item->sponsored_clicks,
					'form_action'      => backend_UrlMake($this->name, 'links_save'),
					'cancel_action'    => window_Close('links_change')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Save changes existing (or new) to `link` object and display result
	 */
	private function saveLink() {
		$id = isset($_REQUEST['id']) ? fix_id($_REQUEST['id']) : null;
		$manager = \Modules\Links\Manager::get_instance();

		$data = array(
				'text' 			=> $this->get_multilanguage_field('text'),
				'description' 	=> $this->get_multilanguage_field('description'),
				'text_id'		=> fix_chars($_REQUEST['text_id']),
				'url' 			=> escape_chars($_REQUEST['url']),
				'external' 		=> $this->get_boolean_field('external') ? 1 : 0,
				'sponsored' 	=> $this->get_boolean_field('sponsored') ? 1 : 0,
				'display_limit'	=> fix_id($_REQUEST['display_limit']),
			);

		$gallery_addon = '';

		// if images are in use and specified
		if (ModuleHandler::is_loaded('gallery') && isset($_FILES['image'])) {
			$gallery = gallery::get_instance();
			$gallery_manager = GalleryManager::get_instance();

			$result = $gallery->createImage('image');

			if (!$result['error']) {
				$image_data = array(
							'title'			=> $data['text'],
							'visible'		=> 0,
							'protected'		=> 1
						);

				$gallery_manager->update_items($image_data, array('id' => $result['id']));

				$data['image'] = $result['id'];
				$gallery_addon = ';'.window_ReloadContent('gallery_images');
			}
		}

		if (!is_null($id)) {
			$manager->update_items($data, array('id' => $id));
			$window_name = 'links_change';
		} else {
			$manager->insert_item($data);
			$window_name = 'links_add';
		}

		// load message template
		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		// prepare parameters for template
		$params = array(
					'message' => $this->get_language_constant('message_link_saved'),
					'button'  => $this->get_language_constant('close'),
					'action'  => window_Close($window_name).';'.
						window_ReloadContent('links_list').';'.
						window_ReloadContent('links_overview').$gallery_addon
				);

		// show template
		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Present user with confirmation dialog before removal of specified `link` object
	 */
	private function deleteLink() {
		global $language;

		$id = fix_id($_REQUEST['id']);
		$manager = \Modules\Links\Manager::get_instance();

		// get item from the database
		$item = $manager->get_single_item(array('text'), array('id' => $id));

		// load template
		$template = new TemplateHandler('confirmation.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		// prepare parameters for template
		$params = array(
					'message'    => $this->get_language_constant('message_link_delete'),
					'name'       => $item->text[$language],
					'yes_text'   => $this->get_language_constant('delete'),
					'no_text'    => $this->get_language_constant('cancel'),
					'yes_action' => window_LoadContent(
											'links_delete',
											URL::make_query(
												'backend_module',
												'transfer_control',
												array('module', $this->name),
												array('backend_action', 'links_delete_commit'),
												array('id', $id)
											)
										),
					'no_action'  => window_Close('links_delete')
				);

		// show template
		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Remove specified `link` object and inform user about operation status
	 */
	private function deleteLink_Commit() {
		$id = fix_id($_REQUEST['id']);
		$manager = \Modules\Links\Manager::get_instance();
		$membership_manager = \Modules\Links\MembershipManager::get_instance();
		$gallery_addon = '';

		// if we used image with this, we need to remove that too
		if (ModuleHandler::is_loaded('gallery')) {
			$item = $manager->get_single_item($manager->get_field_names(), array('id' => $id));

			if (is_object($item) && !empty($item->image)) {
				$gallery_manager = GalleryManager::get_instance();
				$gallery_manager->delete_items(array('id' => $item->image));
			}

			$gallery_addon = ';'.window_ReloadContent('gallery_images');
		}

		// remove data from the database
		$manager->delete_items(array('id' => $id));
		$membership_manager->delete_items(array('link' => $id));

		// load message template
		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		// prepare parameters for template
		$params = array(
					'message'	=> $this->get_language_constant('message_link_deleted'),
					'button'	=> $this->get_language_constant('close'),
					'action'	=> window_Close('links_delete').';'.window_ReloadContent('links_list').$gallery_addon
				);

		// show template
		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show link groups management window
	 */
	private function showGroups() {
		$template = new TemplateHandler('groups_list.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'link_new' => window_OpenHyperlink(
										$this->get_language_constant('create_group'),
										'groups_add', 400,
										$this->get_language_constant('title_groups_create'),
										true, false,
										$this->name,
										'groups_add'
									),
					);

		$template->register_tag_handler('cms:group_list', $this, 'tag_GroupList');

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Create new group form
	 */
	private function addGroup() {
		$template = new TemplateHandler('groups_add.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'form_action'	=> backend_UrlMake($this->name, 'groups_save'),
					'cancel_action'	=> window_Close('groups_add')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Group rename form
	 */
	private function changeGroup() {
		$id = fix_id($_REQUEST['id']);
		$manager = \Modules\Links\GroupManager::get_instance();

		$item = $manager->get_single_item($manager->get_field_names(), array('id' => $id));

		// show message
		$template = new TemplateHandler('groups_change.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'id'			=> $item->id,
					'name'			=> $item->name,
					'text_id'		=> $item->text_id,
					'form_action'	=> backend_UrlMake($this->name, 'groups_save'),
					'cancel_action'	=> window_Close('groups_change')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Insert or save group data
	 */
	private function saveGroup() {
		$id = isset($_REQUEST['id']) ? fix_id($_REQUEST['id']) : null;
		$manager = \Modules\Links\GroupManager::get_instance();

		$data = array(
				'name'    => $this->get_multilanguage_field('name'),
				'text_id' => escape_chars($_REQUEST['text_id'])
			);

		if (!is_null($id)) {
			$manager->update_items($data, array('id' => $id));
			$window_name = 'groups_change';
			$message = $this->get_language_constant('message_group_renamed');

		} else {
			$manager->insert_item($data);
			$window_name = 'groups_add';
			$message = $this->get_language_constant('message_group_created');
		}

		// show message
		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'message'	=> $message,
					'button'	=> $this->get_language_constant('close'),
					'action'	=> window_Close($window_name).';'.window_ReloadContent('groups_list')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Delete group confirmation dialog
	 */
	private function deleteGroup() {
		global $language;

		$id = fix_id($_REQUEST['id']);
		$manager = \Modules\Links\GroupManager::get_instance();

		$item = $manager->get_single_item(array('name'), array('id' => $id));

		$template = new TemplateHandler('confirmation.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'message'		=> $this->get_language_constant('message_group_delete'),
					'name'			=> $item->name[$Language],
					'yes_text'		=> $this->get_language_constant('delete'),
					'no_text'		=> $this->get_language_constant('cancel'),
					'yes_action'	=> window_LoadContent(
											'groups_delete',
											URL::make_query(
												'backend_module',
												'transfer_control',
												array('module', $this->name),
												array('backend_action', 'groups_delete_commit'),
												array('id', $id)
											)
										),
					'no_action'		=> window_Close('groups_delete')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Delete group from the system
	 */
	private function deleteGroup_Commit() {
		$id = fix_id($_REQUEST['id']);
		$manager = \Modules\Links\GroupManager::get_instance();
		$membership_manager = \Modules\Links\MembershipManager::get_instance();

		$manager->delete_items(array('id' => $id));
		$membership_manager->delete_items(array('group' => $id));

		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'message'	=> $this->get_language_constant('message_group_deleted'),
					'button'	=> $this->get_language_constant('close'),
					'action'	=> window_Close('groups_delete').';'.window_ReloadContent('groups_list')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Print a form containing all the links within a group
	 */
	private function groupLinks() {
		$group_id = fix_id($_REQUEST['id']);

		$template = new TemplateHandler('groups_links.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'group'			=> $group_id,
					'form_action'	=> backend_UrlMake($this->name, 'groups_links_save'),
					'cancel_action'	=> window_Close('groups_links')
				);

		$template->register_tag_handler('cms:group_links', $this, 'tag_GroupLinks');
		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Save link group memberships
	 */
	private function groupLinksSave() {
		$group = fix_id($_REQUEST['group']);
		$membership_manager = \Modules\Links\MembershipManager::get_instance();

		// fetch all ids being set to specific group
		$link_ids = array();
		foreach ($_REQUEST as $key => $value) {
			if (substr($key, 0, 8) == 'link_id_' && $value == 1)
				$link_ids[] = fix_id(substr($key, 8));
		}

		// remove old memberships
		$membership_manager->delete_items(array('group' => $group));

		// save new memberships
		foreach ($link_ids as $id)
			$membership_manager->insert_item(array(
											'link'	=> $id,
											'group'	=> $group
										));

		// display message
		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		$params = array(
					'message'	=> $this->get_language_constant('message_group_links_updated'),
					'button'	=> $this->get_language_constant('close'),
					'action'	=> window_Close('groups_links')
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Show sponsored link overview
	 */
	private function showOverview() {
		// display message
		$template = new TemplateHandler('overview_list.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);
		$template->register_tag_handler('cms:link_list', $this, 'tag_LinkList');

		$params = array(
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Record click cound and redirect to given page
	 */
	private function redirectLink() {
		$link_id = fix_id($_REQUEST['id']);
		$manager = \Modules\Links\Manager::get_instance();

		$link = $manager->get_single_item($manager->get_field_names(), array('id' => $link_id));

		if (is_object($link)) {
			$url = $link->url;
			$data = array();

			// update click count
			$data['total_clicks'] = $link->total_clicks + 1;
			if ($link->sponsored == 1)
				$data['sponsored_clicks'] = $link->sponsored_clicks + 1;
			$manager->update_items($data, array('id' => $link_id));

			// redirect browser
			header('Location: '.$url, true, 302);
		}
	}

	/**
	 * Tag handler for links in group editor mode
	 *
	 * @param array $params
	 * @param array $children
	 */
	public function tag_GroupLinks($params, $children) {
		if (!isset($params['group'])) return;

		$group = fix_id($params['group']);
		$link_manager = \Modules\Links\Manager::get_instance();
		$membership_manager = \Modules\Links\MembershipManager::get_instance();

		$memberships = $membership_manager->get_items(
												array('link'),
												array('group' => $group)
											);

		$link_ids = array();
		if (count($memberships) > 0)
			foreach($memberships as $membership)
				$link_ids[] = $membership->link;

		$links = $link_manager->get_items(array('id', 'text', 'sponsored'), array());

		$template = new TemplateHandler('groups_links_item.xml', $this->path.'templates/');
		$template->set_mapped_module($this->name);

		if (count($links) > 0)
			foreach($links as $link) {
				$params = array(
								'id'				=> $link->id,
								'in_group'			=> in_array($link->id, $link_ids) ? 1 : 0,
								'text'				=> $link->text,
								'sponsored_character' => ($link->sponsored == '1') ? CHAR_CHECKED : CHAR_UNCHECKED,
							);

				$template->restore_xml();
				$template->set_local_params($params);
				$template->parse();
			}
	}

	/**
	 * Tag handler for `link` object
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_Link($tag_params, $children) {
		$conditions = array();
		$manager = \Modules\Links\Manager::get_instance();

		// get parameters
		if (isset($tag_params['id']))
			$conditions['id'] = fix_id($tag_params['id']);

		if (isset($tag_params['text_id']))
			$conditions['text_id'] = fix_chars($tag_params['text_id']);

		// get items from the database
		$item = $manager->get_single_item($manager->get_field_names(), $conditions);

		if (!is_object($item))
			return;

		// load template
		$template = $this->load_template($tag_params, 'links_item.xml');
		$template->set_mapped_module($this->name);

		// calculate display progress
		if (($item->sponsored_clicks >= $item->display_limit) || ($item->display_limit == 0)) {
			$percent = 100;

		} else {
			$percent = round(($item->sponsored_clicks / $item->display_limit) * 100, 0);
			if ($percent > 100)
				$percent = 100;
		}

		// get thumbnail image if exists
		$image = null;
		$thumbnail = null;

		if (ModuleHandler::is_loaded('gallery')) {
			$gallery = gallery::get_instance();
			$gallery_manager = GalleryManager::get_instance();

			if (is_numeric($item->image)) {
				$image_item = $gallery_manager->get_single_item(
													$gallery_manager->get_field_names(),
													array('id' => $item->image)
												);

				if (is_object($image_item)) {
					$image = $gallery->getImageURL($image_item);
					$thumbnail = $gallery->getThumbnailURL($image_item);
				}
			}
		}

		$params = array(
					'id'                  => $item->id,
					'text'                => $item->text,
					'description'         => $item->description,
					'text_id'             => $item->text_id,
					'url'                 => $item->url,
					'redirect_url'        => URL::make_query($this->name, 'redirect', array('id', $item->id)),
					'external'            => $item->external,
					'external_character'  => ($item->external == '1') ? CHAR_CHECKED : CHAR_UNCHECKED,
					'sponsored'           => $item->sponsored,
					'sponsored_character' => ($item->sponsored == '1') ? CHAR_CHECKED : CHAR_UNCHECKED,
					'display_limit'       => $item->display_limit,
					'display_percent'     => $percent,
					'sponsored_clicks'    => $item->sponsored_clicks,
					'total_clicks'        => $item->total_clicks,
					'image'               => $image,
					'thumbnail'           => $thumbnail,
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Tag handler for printing link lists
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_LinkList($tag_params, $children) {
		$manager = \Modules\Links\Manager::get_instance();
		$group_manager = \Modules\Links\GroupManager::get_instance();
		$membership_manager = \Modules\Links\MembershipManager::get_instance();
		$conditions = array();
		$order_by = array('id');
		$order_asc = true;

		// save some CPU time by getting this early
		if (ModuleHandler::is_loaded('gallery')) {
			$use_images = true;
			$gallery = gallery::get_instance();
			$gallery_manager = GalleryManager::get_instance();

		} else {
			$use_images = false;
		}

		if (isset($tag_params['sponsored']) && $tag_params['sponsored'] == '1')
			$conditions['sponsored'] = 1;

		if (isset($tag_params['group'])) {
			if (is_numeric($tag_params['group'])) {
				// we already have id of a group
				$group = fix_id($tag_params['group']);

			} else {
				// specified group is text id
				$text_id = fix_chars($tag_params['group']);
				$raw_group = $group_manager->get_single_item(array('id'), array('text_id' => $text_id));

				if (is_object($raw_group))
					$group = $raw_group->id; else
					return;
			}

			$items = $membership_manager->get_items(
												array('link'),
												array('group' => $group)
											);

			$item_list = array();

			if (count($items) > 0) {
				foreach($items as $item)
					$item_list[] = $item->link;
			} else {
				return;  // no items were found in group, nothing to show
			}

			$conditions['id'] = $item_list;
		}

		if (isset($tag_params['order_by']))
			$order_by = explode(',', fix_chars($tag_params['order_by']));

		if (isset($tag_params['random']) && $tag_params['random'] == 1)
			$order_by = array('RAND()');

		if (isset($tag_params['order_asc']))
			$order_asc = $tag_params['order_asc'] == 1 ? true : false;

		// get links
		$items = $manager->get_items(
								$manager->get_field_names(),
								$conditions,
								$order_by,
								$order_asc
							);

		$template = $this->load_template($tag_params, 'links_item.xml');
		$template->set_template_params_from_array($children);
		$template->register_tag_handler('cms:link', $this, 'tag_Link');
		$template->register_tag_handler('cms:link_group', $this, 'tag_LinkGroupList');

		// give the ability to limit number of links to display
		if (isset($tag_params['limit']) && !is_null($items))
			$items = array_slice($items, 0, fix_id($tag_params['limit']), true);

		// make sure list contains items
		if (count($items) == 0)
			return;

		// generate output
		foreach ($items as $item) {
			// calculate display progress
			if (($item->sponsored_clicks >= $item->display_limit) || ($item->display_limit == 0)) {
				$percent = 100;
			} else {
				$percent = round(($item->sponsored_clicks / $item->display_limit) * 100, 0);
				if ($percent > 100) $percent = 100;
			}

			// if gallery is loaded
			$image = '';
			$thumbnail = '';
			if ($use_images && !empty($item->image)) {
				$image_item = $gallery_manager->get_single_item($gallery_manager->get_field_names(), array('id' => $item->image));

				if (is_object($image_item)) {
					$image = $gallery->getImageURL($image_item);
					$thumbnail = $gallery->getThumbnailURL($image_item);
				}
			}

			$params = array(
						'id'                  => $item->id,
						'text'                => $item->text,
						'description'         => $item->description,
						'text_id'             => $item->text_id,
						'url'                 => $item->url,
						'redirect_url'        => URL::make_query($this->name, 'redirect', array('id', $item->id)),
						'external'            => $item->external,
						'external_character'  => ($item->external == '1') ? CHAR_CHECKED : CHAR_UNCHECKED,
						'sponsored'           => $item->sponsored,
						'sponsored_character' => ($item->sponsored == '1') ? CHAR_CHECKED : CHAR_UNCHECKED,
						'display_limit'       => $item->display_limit,
						'display_percent'     => $percent,
						'sponsored_clicks'    => $item->sponsored_clicks,
						'total_clicks'        => $item->total_clicks,
						'image'               => $image,
						'thumbnail'           => $thumbnail,
						'item_change'         => URL::make_hyperlink(
												$this->get_language_constant('change'),
												window_Open(
													'links_change', 	// window id
													600,				// width
													$this->get_language_constant('title_links_change'), // title
													false, false,
													URL::make_query(
														'backend_module',
														'transfer_control',
														array('module', $this->name),
														array('backend_action', 'links_change'),
														array('id', $item->id)
													)
												)
											),
						'item_delete'         => URL::make_hyperlink(
												$this->get_language_constant('delete'),
												window_Open(
													'links_delete', 	// window id
													400,				// width
													$this->get_language_constant('title_links_delete'), // title
													false, false,
													URL::make_query(
														'backend_module',
														'transfer_control',
														array('module', $this->name),
														array('backend_action', 'links_delete'),
														array('id', $item->id)
													)
												)
											),
						'item_open'           => URL::make_hyperlink(
												$this->get_language_constant('open'),
												$item->url,
												'', '',
												'_blank'
											),
					);

			$template->restore_xml();
			$template->set_local_params($params);
			$template->parse();
		}
	}

	/**
	 * Tag handler for printing link group
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_Group($tag_params, $children) {
		if (!isset($tag_params['id'])) return;

		// get parameters
		if (isset($tag_params['id']))
			$conditions['id'] = fix_id($tag_params['id']);

		if (isset($tag_params['text_id']))
			$conditions['text_id'] = fix_chars($tag_params['text_id']);

		// save some CPU time by getting this early
		if (ModuleHandler::is_loaded('gallery')) {
			$use_images = true;
			$gallery = gallery::get_instance();
			$gallery_manager = GalleryManager::get_instance();
		} else {
			$use_images = false;
		}

		// get manager instances
		$manager = \Modules\Links\GroupManager::get_instance();
		$link_manager = \Modules\Links\Manager::get_instance();
		$membership_manager = \Modules\Links\MembershipManager::get_instance();

		// get matching group
		$item = $manager->get_single_item($manager->get_field_names(), $conditions);

		// load template
		$template = $this->load_template($tag_params, 'group.xml');
		$template->register_tag_handler('cms:link', $this, 'tag_Link');
		$template->register_tag_handler('cms:link_list', $this, 'tag_LinkList');

		if (!is_object($item))
			return;

		$thumbnail = '';

		if ($use_images) {
			$first_link_id = $membership_manager->get_item_value('link', array('group' => $item->id));

			// we have some links assigned to the group, get thumbnail
			if (!empty($first_link_id)) {
				$image_id = $link_manager->get_item_value('image', array('id' => $first_link_id));

				if (!empty($image_id)) {
					$image = $gallery_manager->get_single_item($gallery_manager->get_field_names(), array('id' => $image_id));
					$thumbnail = $gallery->getThumbnailURL($image);
				}
			}
		}

		$params = array(
					'id'		=> $item->id,
					'name'		=> $item->name,
					'text_id'	=> $item->text_id,
					'thumbnail'	=> $thumbnail,
				);

		$template->restore_xml();
		$template->set_local_params($params);
		$template->parse();
	}

	/**
	 * Tag handler for printing link groups
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_GroupList($tag_params, $children) {
		$conditions = array();
		$manager = \Modules\Links\GroupManager::get_instance();
		$link_manager = \Modules\Links\Manager::get_instance();
		$membership_manager = \Modules\Links\MembershipManager::get_instance();

		// save some CPU time by getting this early
		if (ModuleHandler::is_loaded('gallery')) {
			$use_images = true;
			$gallery = gallery::get_instance();
			$gallery_manager = GalleryManager::get_instance();

		} else {
			$use_images = false;
		}

		// get parameters
		if (isset($tag_params['id']))
			$conditions['id'] = fix_id(explode(',', $tag_params['id']));

		if (isset($tag_params['id']))
			$conditions['text_id'] = fix_chars(explode(',', $tag_params['id']));

		// get groups from database
		$items = $manager->get_items(
								$manager->get_field_names(),
								$conditions,
							);

		$template = $this->load_template($tag_params, 'groups_item.xml');
		$template->register_tag_handler('cms:link', $this, 'tag_Link');
		$template->register_tag_handler('cms:link_list', $this, 'tag_LinkList');

		if (count($items) == 0)
			return;

		foreach ($items as $item) {
			$thumbnail = '';

			if ($use_images) {
				$first_link_id = $membership_manager->get_item_value('link', array('group' => $item->id));

				// we have some links assigned to the group, get thumbnail
				if (!empty($first_link_id)) {
					$image_id = $link_manager->get_item_value('image', array('id' => $first_link_id));

					if (!empty($image_id)) {
						$image = $gallery_manager->get_single_item($gallery_manager->get_field_names(), array('id' => $image_id));
						$thumbnail = $gallery->getThumbnailURL($image);
					}
				}
			}

			$params = array(
						'id'          => $item->id,
						'name'        => $item->name,
						'text_id'     => $item->text_id,
						'thumbnail'   => $thumbnail,
						'item_change' => URL::make_hyperlink(
												$this->get_language_constant('change'),
												window_Open(
													'groups_change', 	// window id
													400,				// width
													$this->get_language_constant('title_groups_change'), // title
													false, false,
													URL::make_query(
														'backend_module',
														'transfer_control',
														array('module', $this->name),
														array('backend_action', 'groups_change'),
														array('id', $item->id)
													)
												)
											),
						'item_delete' => URL::make_hyperlink(
												$this->get_language_constant('delete'),
												window_Open(
													'groups_delete', 	// window id
													400,				// width
													$this->get_language_constant('title_groups_delete'), // title
													false, false,
													URL::make_query(
														'backend_module',
														'transfer_control',
														array('module', $this->name),
														array('backend_action', 'groups_delete'),
														array('id', $item->id)
													)
												)
											),
						'item_links'  => URL::make_hyperlink(
												$this->get_language_constant('links'),
												window_Open(
													'groups_links', 	// window id
													400,				// width
													$this->get_language_constant('title_groups_links'), // title
													false, false,
													URL::make_query(
														'backend_module',
														'transfer_control',
														array('module', $this->name),
														array('backend_action', 'groups_links'),
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
	 * Get single link through AJAX request.
	 */
	private function json_Link() {
		$conditions = array();
		$order_by = array('id');
		$order_asc = true;
		$manager = \Modules\Links\Manager::get_instance();
		$result = array(
					'error'			=> true,
					'item'			=> array()
				);

		// get conditions
		if (isset($_REQUEST['id']))
			$conditions['id'] = fix_id($_REQUEST['id']);

		if (isset($_REQUEST['order_by']))
			$order_by = explode(',', fix_chars($_REQUEST['order_by']));

		if (isset($_REQUEST['order_asc']))
			$order_asc = $_REQUEST['order_asc'] == 1;

		// get link from the database
		$item = $manager->get_single_item($manager->get_field_names(), $conditions, $order_by, $order_asc);

		// make sure link exists
		if (is_null($item)) {
			print json_encode($result);
			return;
		}

		// prepare response
		if (is_object($item)) {
			$image_url = null;
			if (ModuleHandler::is_loaded('gallery'))
				$image_url = gallery::getImageById($item->image);

			$result['error'] = false;
			$result['item'] = array(
								'id'               => $item->id,
								'text'             => $item->text,
								'description'      => Markdown::parse($item->description),
								'url'              => $item->url,
								'redirect_url'     => URL::make_query($this->name, 'redirect', array('id', $item->id)),
								'external'         => $item->external,
								'sponsored'        => $item->sponsored,
								'display_limit'    => $item->display_limit,
								'sponsored_clicks' => $item->sponsored_clicks,
								'total_clicks'     => $item->total_clicks,
								'image'            => $image_url
							);
		}

		print json_encode($result);
	}

	/**
	 * Create JSON object containing links with specified characteristics
	 */
	private function json_LinkList() {
		$groups = array();
		$conditions = array();

		$manager = \Modules\Links\Manager::get_instance();
		$group_manager = \Modules\Links\GroupManager::get_instance();
		$membership_manager = \Modules\Links\MembershipManager::get_instance();

		$limit = isset($tag_params['limit']) ? fix_id($tag_params['limit']) : null;
		$order_by = isset($tag_params['order_by']) ? explode(',', fix_chars($tag_params['order_by'])) : array('id');
		$order_asc = isset($tag_params['order_asc']) && $tag_params['order_asc'] == 'yes' ? true : false;

		if (isset($_REQUEST['random']) && $_REQUEST['random'] == 1)
			$order_by = array('RAND()');

		if (isset($_REQUEST['group'])) {
			$group_list = explode(',', fix_chars($_REQUEST['group']));

			$list = $group_manager->get_items(array('id'), array('text_id' => $group_list));

			if (count($list) > 0)
				foreach ($list as $list_item)
					$groups[] = $list_item->id;
		}

		if (isset($_REQUEST['group_id']))
			$groups = array_merge($groups, fix_id(explode(',', $_REQUEST['group_id'])));

		if (isset($_REQUEST['sponsored'])) {
			$sponsored = $_REQUEST['sponsored'] == 'yes' ? 1 : 0;
			$conditions['sponsored'] = $sponsored;
		}

		// fetch ids for specified groups
		if (!empty($groups)) {
			$list = $membership_manager->get_items(array('link'), array('group' => $groups));

			$id_list = array();
			if (count($list) > 0) {
				foreach ($list as $list_item)
					$id_list[] = $list_item->link;

			} else {
				// in case no members of specified group were found, ensure no items are retrieved
				$id_list = '-1';
			}

			$conditions['id'] = $id_list;
		}

		// save some CPU time by getting this early
		$items = $manager->get_items(
							$manager->get_field_names(),
							$conditions,
							$order_by,
							$order_asc,
							$limit
						);

		$result = array(
					'error'			=> false,
					'error_message'	=> '',
					'items'			=> array()
				);

		$gallery_present = ModuleHandler::is_loaded('gallery');

		if (count($items) > 0) {
			foreach ($items as $item) {
				$image_url = null;
				if ($gallery_present && !is_null($item->image))
					$image_url = gallery::getImageById($item->image);

				$result['items'][] = array(
									'id'               => $item->id,
									'text'             => $item->text,
									'url'              => $item->url,
									'redirect_url'     => URL::make_query($this->name, 'redirect', array('id', $item->id)),
									'external'         => $item->external,
									'sponsored'        => $item->sponsored,
									'display_limit'    => $item->display_limit,
									'sponsored_clicks' => $item->sponsored_clicks,
									'total_clicks'     => $item->total_clicks,
									'image'            => $image_url
								);
			}
		}

		print json_encode($result);
	}

	/**
	 * Create JSON object containing group items
	 */
	private function json_GroupList() {
		$groups = array();
		$conditions = array();

		$limit = isset($tag_params['limit']) ? fix_id($tag_params['limit']) : null;
		$order_by = isset($tag_params['order_by']) ? explode(',', fix_chars($tag_params['order_by'])) : array('id');
		$order_asc = isset($tag_params['order_asc']) && $tag_params['order_asc'] == 'yes' ? true : false;

		$manager = \Modules\Links\GroupManager::get_instance();

		$items = $manager->get_items($manager->get_field_names(), $conditions, $order_by, $order_asc, $limit);

		$result = array(
					'error'			=> false,
					'error_message'	=> '',
					'items'			=> array()
				);

		if (count($items) > 0) {
			foreach ($items as $item)
				$result['items'][] = array(
									'id'		=> $item->id,
									'name'		=> $item->name
								);
		} else {
		}

		print json_encode($result);
	}
}

?>
