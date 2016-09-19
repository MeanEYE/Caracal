<?php

/**
 * Page Switch
 *
 * Author: Mladen Mijatov
 */

class PageSwitch {
	private $param_name = 'page';
	private $current_page = 1;
	private $max_pages = 9;
	private $per_page = 10;
	private $total_items = 0;
	private $url_params = array();
	private $invalid_params = array('PHPSESSID');

	/**
	 * Constructor
	 *
	 * @param string $param_name
	 */
	public function __construct($param_name=null) {
		if (!is_null($param_name))
			$this->param_name = $param_name;

		if (isset($_REQUEST[$this->param_name]))
			$this->current_page = fix_id($_REQUEST[$this->param_name]);
	}

	/**
	 * Set base URL to be used in links. You can provide additional
	 * parameters as array key pairs.
	 *
	 * @param string $params
	 */
	public function setURL($params) {
		$this->url_params = $params;
		$this->fixParams();
	}

	/**
	 * Set base URL from current
	 */
	public function setCurrentAsBaseURL() {
		$this->url_params = $_REQUEST;
		$this->fixParams();
	}

	/**
	 * Set number of items per page
	 *
	 * @param integer $number
	 */
	public function setItemsPerPage($number) {
		$this->per_page = $number;
	}

	/**
	 * Set total number of items
	 */
	public function setTotalItems($number) {
		$this->total_items = $number;
	}

	/**
	 * Set maximum number of pages to be displayed
	 *
	 * @param integer $number
	 */
	public function setMaxPages($number) {
		$this->max_pages = $number;
	}

	/**
	 * Return filter paramters for item manager
	 * @return integer/array
	 */
	public function getFilterParams() {
		if ($this->current_page == 1)
			$result = $this->per_page; else
			$result = array(
						($this->current_page - 1) * $this->per_page,
						$this->per_page
					);

		return $result;
	}

	/**
	 * Page switcher tag handler
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_Pages($tag_params, $children) {
		$template = $this->load_template($tag_params, 'page_switch_page.xml');
		$template->set_template_params_from_array($children);

		// calculate number of total pages
		$total_pages = ceil($this->total_items / $this->per_page);

		if ($total_pages == 0)
			$total_pages = 1;

		// determine start and ending, we never want
		// to show more than 10 pages at a time
		if ($total_pages > $this->max_pages) {
			if ($this->current_page - $this->max_pages < 1) {
				// we are at the beginning of page list
				$start = 1;

			} else if ($this->current_page + $this->max_pages > $total_pages) {
				// we are at the end of page list
				$start = $total_pages - $this->max_pages;

			} else {
				// we are in the middle
				$start = $this->current_page - floor($this->max_pages / 2);
			}

			$end = $start + $this->max_pages;

		} else {
			// we only have a handful of pages, no need for calculation
			$start = 1;
			$end = $total_pages;
		}

		// parse template
		for ($page = $start; $page <= $end; $page++) {
			$url_params = array_merge(
							$this->url_params,
							array($this->param_name => $page)
						);

			$params = array(
					'number'	=> $page,
					'link'		=> url_MakeFromArray($url_params),
					'class'		=> $page == $this->current_page ? 'active' : 'normal'
				);

			$template->set_local_params($params);
			$template->restore_xml();
			$template->parse();
		}
	}

	/**
	 * Page switcher tag handler
	 *
	 * @param array $tag_params
	 * @param array $children
	 */
	public function tag_PageSwitch($tag_params, $children) {
		// create template handler
		$template = $this->load_template($tag_params, 'page_switch.xml');
		$template->set_template_params_from_array($children);
		$template->register_tag_handler('_pages', $this, 'tag_Pages');

		// calculate number of total pages
		$total_pages = ceil($this->total_items / $this->per_page);

		if ($total_pages == 0)
			$total_pages = 1;

		// prepare param arrays
		$params_first = array_merge(
							$this->url_params,
							array($this->param_name => 1)
						);
		$params_last = array_merge(
							$this->url_params,
							array($this->param_name => $total_pages)
						);
		$params_next = array_merge(
							$this->url_params,
							array($this->param_name => $this->current_page + 1)
						);
		$params_previous = array_merge(
							$this->url_params,
							array($this->param_name => $this->current_page - 1)
						);

		// create links for pages
		if ($this->current_page > 1) {
			$link_first = url_MakeFromArray($params_first);
			$link_previous = url_MakeFromArray($params_previous);

		} else {
			$link_first = 'javascript: void(0);';
			$link_previous = 'javascript: void(0);';
		}

		if ($this->current_page < $total_pages) {
			$link_last = url_MakeFromArray($params_last);
			$link_next = url_MakeFromArray($params_next);

		} else {
			$link_last = 'javascript: void(0);';
			$link_next = 'javascript: void(0);';
		}

		// prepare template params
		$params = array(
				'total_pages'		=> $total_pages,
				'items_per_page'	=> $this->per_page,
				'total_items'		=> $this->total_items,
				'current_page'		=> $this->current_page,
				'link_next'			=> $link_next,
				'link_previous'		=> $link_previous,
				'link_first'		=> $link_first,
				'link_last'			=> $link_last
			);

		// parse template
		$template->set_local_params($params);
		$template->restore_xml();
		$template->parse();
	}

	/**
	 * Remove unneeded params
	 */
	private function fixParams() {
		// remove current page param from the list
		if (array_key_exists($this->param_name, $this->url_params))
			unset($this->url_params[$this->param_name]);

		if (array_key_exists('_rewrite', $this->url_params))
			unset($this->url_params['_rewrite']);

		// go through the list of invalid params and remove them
		foreach ($this->url_params as $key => $value) {
			if (in_array($key, $this->invalid_params))
				unset($this->url_params[$key]);
		}
	}
}
