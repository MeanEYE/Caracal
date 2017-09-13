<?php

/**
 * Action Class
 *
 * This class provides easy way of registering and managing
 * actions in modules. It provides basic functions to avoid
 * unnecessary work with flow of control.
 *
 * This class will check for access rights for currently
 * logged in user before transfering control to specified module.
 */

namespace Core;


class Action {
	protected $module;
	protected $name;
	protected $sub_action;
	protected $callable;

	const PARAMS_NONE = 0;
	const PARAMS_XML = 1;

	/**
	 * Create new backend action for specified module and name.
	 * Callable parameter specifies which function will be called
	 * when transfering control.
	 *
	 * @param string $module
	 * @param string $name
	 * @param string/array $callable
	 */
	public function __construct($module, $name, $callable) {
		$this->module = $module;
		$this->name = $name;
		$this->callable = $callable;
		$this->sub_action = null;
	}

	/**
	 * Set sub-action for this action.
	 *
	 * @param string $name;
	 */
	public function set_subaction($name) {
		$this->sub_action = $name;
	}

	/**
	 * Check if currently logged in user has permission to call this action.
	 *
	 * @return boolean
	 */
	public function has_permission() {
		$result = false;

		return $result;
	}

	/**
	 * Return callable for this action.
	 *
	 * @return string/array
	 */
	public function get_callable() {
		return $this->callable;
	}

	/**
	 * Generate URL based on configured data.
	 *
	 * @return string
	 */
	public function get_url() {
		if (!$this->has_permission())
			return '';

		$params = array(
				'transfer_control',  // action
				'backend_module',  // section
				array('backend_action', $this->name),
				array('module', $this->module)
			);

		if (!is_null($this->sub_action))
			$params['sub_action'] = $this->sub_action;

		$result = call_user_func_array('url_Make', $params);
		return $result;
	}
}

?>
