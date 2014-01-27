<?php

/**
 * PayPal Payment Method Integration
 *
 * Copyright (c) 2013. by Way2CU
 * Author: Mladen Mijatov
 */


class PayPal_Express extends PaymentMethod {
	private static $_instance;

	private $url = 'https://www.paypal.com/cgi-bin/webscr';
	private $sandbox_url = 'https://www.sandbox.paypal.com/cgi-bin/webscr';

	/**
	 * Transaction type
	 * @var array
	 */
	private $type = array(
				'cart'	=> TransactionType::SHOPPING_CART,
			);

	/**
	 * Transaction status
	 * @var array
	 */
	private $status = array(
				'Pending'	=> TransactionStatus::PENDING,
				'Completed'	=> TransactionStatus::COMPLETED,
				'Denied'	=> TransactionStatus::DENIED,
			);

	/**
	 * Constructor
	 */
	protected function __construct($parent) {
		global $section;

		parent::__construct($parent);
		
		// register payment method
		$this->name = 'paypal_express';
		$this->registerPaymentMethod();

		// connect signal handler
		shop::getInstance()->connectEvent('before-checkout', 'beforeCheckout', $this);
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
	 * Whether this payment method is able to provide user information
	 * @return boolean
	 */
	public function provides_information() {
		return true;
	}

	/**
	 * If recurring payments are supported by this payment method.
	 * @return boolean
	 */
	public function supports_recurring() {
		return true;
	}

	/**
	 * Return URL for checkout form
	 * @return string
	 */
	public function get_url() {
		return $this->url;
	}

	/**
	 * Get display name of payment method
	 * @return string
	 */
	public function get_title() {
		return $this->parent->getLanguageConstant('express_method_title');
	}

	/**
	 * Get icon URL for payment method
	 * @return string
	 */
	public function get_icon_url() {
		return url_GetFromFilePath($this->parent->path.'images/icon.png');
	}

	/**
	 * Get image URL for payment method
	 * @return string
	 */
	public function get_image_url() {
		return url_GetFromFilePath($this->parent->path.'images/express_image.png');
	}

	/**
	 * Get list of plans for recurring payments.
	 * @return array
	 */
	public function get_recurring_plans() {
		$result = array();
		$conditions = array();
		$manager = PayPal_PlansManager::getInstance();

		// get items from database
		$items = $manager->getItems($manager->getFieldNames(), $conditions);

		// populate result array
		if (count($items) > 0)
			foreach($items as $item) {
			}

		return $result;
	}

	/**
	 * Get account from parent
	 *
	 * @return string
	 */
	private function _getAccount() {
		if (array_key_exists('account', $this->parent->settings))
			$account = $this->parent->settings['account']; else
			$account = 'seller_1322054168_biz@gmail.com';

		return $account;
	}

	/**
	 * Make new payment form with specified items and return
	 * boolean stating the success of initial payment process.
	 * 
	 * @param array $data
	 * @param array $billing_information
	 * @param array $items
	 * @param string $return_url
	 * @param string $cancel_url
	 * @return string
	 */
	public function new_payment($data, $billing_information, $items, $return_url, $cancel_url) {
		global $language;

		$account = $this->_getAccount();

		// prepare basic parameters
		$params = array(
				'cmd'			=> '_cart',
				'upload'		=> '1',
				'business'		=> $account,  // paypal merchant account email
				'currency_code'	=> $data['currency'],
				'weight_unit'	=> 'kgs',
				'lc'			=> $language,
				'return'		=> $return_url,
				'cancel_return'	=> $cancel_url,
			);

		// prepare items for checkout
		$item_count = count($items);
		for ($i = 1; $i <= $item_count; $i++) {
			$item = array_shift($items);

			$params["item_name_{$i}"] = $item['name'][$language];
			$params["item_number_{$i}"] = $item['uid'];
			$params["item_description_{$i}"] = $item['description'];
			$params["amount_{$i}"] = $item['price'];
			$params["quantity_{$i}"] = $item['count'];
			$params["tax_{$i}"] = $item['price'] * ($item['tax'] / 100);
			$params["weight_{$i}"] = $item['weight'];
		}

		// create HTML form
		$result = '';

		foreach ($params as $key => $value)
			$result .= "<input type=\"hidden\" name=\"{$key}\" value=\"{$value}\">";

		return $result;
	}

	/**
	 * Make new recurring payment based on named plan.
	 *
	 * @param string $plan_name
	 * @param array $billing_information
	 * @param string $return_url
	 * @param string $cancel_url
	 * @return string
	 */
	public function new_recurring_payment($plan_name, $billing_information, $return_url, $cancel_url) {
	}

	/**
	 * Before checking out redirect user.
	 *
	 * @param string $return_url
	 * @param string $cancel_url
	 */
	public function beforeCheckout($return_url, $cancel_url) {
		global $language;

		$fields = array();
		$request_id = 0;
		$recurring_plan = isset($_SESSION['recurring_plan']) ? $_SESSION['recurring_plan'] : null;

		// add recurring payment plan
		if (!is_null($recurring_plan)) {
			$manager = PayPal_PlansManager::getInstance();
			$shop = shop::getInstance();
			$plan = $manager->getSingleItem($manager->getFieldNames(), array('text_id' => $recurring_plan));

			if (is_object($plan)) {
				// prepare fields for initial negotiation
				$fields["PAYMENTREQUEST_{$request_id}_AMT"] = $plan->price;
				$fields["PAYMENTREQUEST_{$request_id}_CURRENCYCODE"] = $shop->getDefaultCurrency();
				$fields["PAYMENTREQUEST_{$request_id}_DESC"] = $plan->name[$language];
				$fields["PAYMENTREQUEST_{$request_id}_INVNUM"] = '';  // transaction id
				$fields["PAYMENTREQUEST_{$request_id}_PAYMENTACTION"] = 'Authorization';

				// add one time payment
				if ($plan->setup_price > 0) {
					$request_id++;
					$fields["PAYMENTREQUEST_{$request_id}_AMT"] = $plan->setup_price;
					$fields["PAYMENTREQUEST_{$request_id}_CURRENCYCODE"] = $shop->getDefaultCurrency();
					$fields["PAYMENTREQUEST_{$request_id}_DESC"] = $this->parent->getLanguageConstant('api_setup_fee');
					$fields["PAYMENTREQUEST_{$request_id}_INVNUM"] = '';  // transaction id
					$fields["PAYMENTREQUEST_{$request_id}_PAYMENTACTION"] = 'Sale';
				}
			}
		}

		// TODO: Add other shop items.

		// store return URL in session
		$_SESSION['paypal_redirect_url'] = $return_url;

		// add regular fields
		$fields['NOSHIPPING'] = 1;
		$fields['REQCONFIRMSHIPPING'] = 0;
		$fields['ALLOWNOTE'] = 0;
		$fields['BILLINGTYPE'] = 'RecurringPayments';
		$fields['RETURNURL'] = url_Make('express_return', $this->parent->name);
		$fields['CANCELURL'] = $cancel_url;

		// generate name-value pair string for sending
		$response = PayPal_Helper::callAPI(PayPal_Helper::METHOD_SetExpressCheckout, $fields);

		if (strcasecmp($response['ACK'], 'success') || strcasecmp($response['ACK'], 'successwithwarning')) {
			$token = $response['TOKEN'];
			PayPal_Helper::redirect(PayPal_Helper::COMMAND_ExpressCheckout, $token);

		} else {
			// report error
			$error_code = urldecode($response['L_ERRORCODE0']);
			$error_long = urldecode($response['L_LONGMESSAGE0']);

			trigger_error("PayPal_Express: ({$error_code}) - {$error_long}", E_USER_ERROR);
		}

		return true;
	}

	/**
	 * Handle callback from PayPal site.
	 */
	public function handleCallback() {
		$token = escape_chars($_REQUEST['token']);
		$_SESSION['paypal_token'] = $token;

		if (isset($_SESSION['paypal_redirect_url'])) {
			$url = $_SESSION['paypal_redirect_url'];
			unset($_SESSION['paypal_redirect_url']);

			// redirect with 
			header('Location: '.$url, true, 302);
		}
	}
	
	/**
	 * Handle verification received from payment gateway
	 * and return boolean denoting success of complete payment.
	 * 
	 * @return boolean
	 */
	public function verify_payment() {
		$result = false;

		// prepare response data
		$strip = get_magic_quotes_gpc();
		$response = "cmd=_notify-validate";

		foreach ($_POST as $key => $value) {
			if ($strip)	$value = stripslashes($value);
			$value = urlencode($value);

			$response .= "&{$key}={$value}";
		}

		// validate with paypal.com this transaction
		$header = "POST /cgi-bin/webscr HTTP/1.0\r\n";
		$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$header .= "Content-Length: " . strlen($response) . "\r\n\r\n";
		$socket = fsockopen('ssl://www.paypal.com', 443, $error_number, $error_string, 30);

		if ($socket) {
			// send request
			fputs($socket, $header.$response);

			// get response from server
			$response = fgets($socket);

			// set result
			$result = ($_POST['receiver_email'] == $this->_getAccount()) && strcmp($response, 'VERIFIED');
		}

		fclose($socket);

		return $result;
	}
	
	/**
	 * Get items from data
	 * 
	 * @return array
	 */
	public function get_items() {
		$result = array();
		$item_count = fix_id($_POST['num_cart_items']);

		for ($i = 1; $i < $item_count + 1; $i++) {
			$result[] = array(
					'uid'		=> fix_chars($_POST["item_number{$i}"]),
					'quantity'	=> fix_id($_POST["quantity{$i}"]),
					'price'		=> escape_chars($_POST["mc_gross_{$i}"]),
					'tax'		=> 0
				);
		}

		return $result;
	}
	
	/**
	 * Get transaction information from data
	 * 
	 * @return array
	 */
	public function get_transaction_info() {
		$type = array_key_exists($_POST['txn_type'], $this->type) ? $this->type[$_POST['txn_type']] : TransactionType::SHOPPING_CART;
		$status = array_key_exists($_POST['payment_status'], $this->status) ? $this->status[$_POST['payment_status']] : TransactionStatus::DENIED;

		$result = array(
				'id'		=> fix_chars($_POST['txn_id']),
				'type'		=> $type,
				'status'	=> $status,
				'custom'	=> isset($_POST['custom']) ? fix_chars($_POST['custom']) : ''
			);
		
		return $result;
	}
	
	/**
	 * Get payment infromation from data
	 * 
	 * @return array
	 */
	public function get_payment_info() {
		$result = array(
				'tax'		=> escape_chars($_POST['tax']),
				'fee'		=> escape_chars($_POST['mc_fee']),
				'gross'		=> escape_chars($_POST['mc_gross']),
				'handling'	=> escape_chars($_POST['mc_handling']),
				'shipping'	=> escape_chars($_POST['mc_shipping']),
				'currency'	=> escape_chars($_POST['mc_currency'])
			);

		return $result;
	}
	
}
