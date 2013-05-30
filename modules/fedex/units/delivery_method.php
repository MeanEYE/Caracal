<?php

/**
 * FedEx Shop Delivery Method
 *
 * Copyright (c) 2013. by Way2CU
 * Author: Mladen Mijatov
 */


class FedEx_DeliveryMethod extends DeliveryMethod {
	private static $_instance;

	var $package_type = array(
				PackageType::BOX_10			=> 'FEDEX_BOX_10',
				PackageType::BOX_20			=> 'FEDEX_BOX_20',
				PackageType::BOX			=> 'FEDEX_BOX',
				PackageType::ENVELOPE		=> 'FEDEX_ENVELOPE',
				PackageType::PAK			=> 'FEDEX_PAK',
				PackageType::TUBE			=> 'FEDEX_TUBE',
				PackageType::USER_PACKAGING	=> 'YOUR_PACKAGING'
			);

	// different services offered by this class
	const RATE_SERVICE = 0;
	const TRACK_SERVICE = 1;
	const GLOBAL_SHIPPING_SERVICE = 2;
	const PACKAGE_MOVEMENT_SERVICE = 3;

	// protocol definition files
	var $wsdl = array();

	// service string ids
	var $string_id = array();

	// service versions
	var $versions = array();

	protected function __construct($parent) {
		parent::__construct($parent);

		// configure implemented version numbers for each service
		$this->versions = array(
					RATE_SERVICE				=> array(13, 0, 0),
					TRACK_SERVICE				=> array(6, 0, 0),
					GLOBAL_SHIPPING_SERVICE		=> array(1, 0, 0),
					PACKAGE_MOVEMENT_SERVICE	=> array(5, 0, 0),
				);
		
		// form path where protocol definitions are stored
		$wsdl_path = $this->parent->path.'wsdl/';
		$this->wsdl = array (
					RATE_SERVICE				=> $wsdl_path."RateService_v{$this->versions[RATE_SERVICE][0]}.wsdl",
					TRACK_SERVICE				=> $wsdl_path."TrackService_v{$this->versions[TRACK_SERVICE][0]}.wsdl",
					GLOBAL_SHIPPING_SERVICE		=> $wsdl_path."GlobalShipAddressService_v{$this->versions[GLOBAL_SHIPPING_SERVICE][0]}.wsdl",
					PACKAGE_MOVEMENT_SERVICE	=> $wsdl_path."PackageMovementInformationService_v{$this->versions[PACKAGE_MOVEMENT_SERVICE][0]}.wsdl"
				);

		// populate service string ids
		$this->string_id = array(
					RATE_SERVICE				=> 'crs',
					TRACK_SERVICE				=> 'crs',
					GLOBAL_SHIPPING_SERVICE		=> 'crs',
					PACKAGE_MOVEMENT_SERVICE	=> 'crs'
				);

		// register delivery method
		$this->name = 'fedex';
		$this->registerDeliveryMethod();
	}

	/**
	 * Populate user credentials for specified request.
	 *
	 * @param array refference $request
	 */
	private function _populateCredentials(&$request) {
		$key = null;
		$password = null;

		if (isset($this->parent->settings['fedex_key']) && isset($this->parent->settings['fedex_password'])) {
			$key = $this->parent->settings['fedex_key'];
			$password = $this->parent->settings['fedex_password'];
		}

		if (!is_null($key) && !is_null($key)) {
			$request['WebAuthenticationDetail'] = array(
								'UserCredential' => array(
									'Key'		=> $key,
									'Password'	=> $password
								));
		} else {
			throw new Exception('Missing FexEx credentials!');
		}
	}

	/**
	 * Populate client information for specified request.
	 *
	 * @param array referrence $request
	 */
	private function _populateClientDetails(&$request) {
		$account = null;
		$meter = null;

		if (isset($this->parent->settings['account']) && isset($this->parent->settings['meter'])) {
			$account = $this->parent->settings['account'];
			$meter = $this->parent->settings['meter'];
		}

		if (!is_null($account) && !is_null($meter)) {
			$request['ClientDetail'] = array(
								'AccountNumber'	=> $account,
								'MeterNumber'	=> $meter
							);
		} else {
			throw new Exception('Missing client/account information!');
		}
	}

	/**
	 * Populate transaction information for specified request.
	 *
	 * @param array referrence $request
	 * @param string $transaction_id
	 */
	private function _populateTransactionDetails(&$request, $transaction_id) {
		if (is_empty($transaction_id)) 
			throw new Exception('Transaction id can not be empty!');

		$request['TransactionDetail'] = array('CustomerTransactionId' => $transaction_id);
	}

	/**
	 * Populate version information for specified request.
	 *
	 * @param array referrence $request
	 * @param integer $service
	 */
	private function _populateVersionInformation(&$request, $service) {
		if (!array_key_exists($service, $this->wsdl))
			throw new Exception('Unknown service: '.$service);

		$request['Version'] = array(
						'ServiceId'		=> $this->string_id[$service],
						'Major'			=> $this->versions[$service][0],
						'Intermediate'	=> $this->versions[$service][1],
						'Minor'			=> $this->versions[$service][2],
					);
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
	 * Get status of specified delivery. If available multiple statuses
	 * should be provided last item being the current status of delivery.
	 *                                       
	 * @param string $delivery_id            
	 * @return array
	 */
	public function getDeliveryStatus($delivery_id) {
		$result = array();

		return $result;
	}

	/**
	 * Get available delivery types for selected items. Each type needs
	 * to return estimated delivery time, cost and name of service.
	 *
	 * @param array $items
	 * @return array
	 */
	public function getDeliveryTypes($items) {
		$shop = shop::getInstance();
		$debut = $shop->isDebug();
		$result = array();
		$request = array();
		$client = new SoapClient($this->wsdl[RATE_SERVICE], array('trace' => $debug));
		$transaction_id = '';

		// populate request header
		$this->_populateCredentials($request);
		$this->_populateClientDetails($request);
		$this->_populateTransactionDetails($request, $transaction_id);
		$this->_populateVersionInformation($request, RATE_SERVICE);

		// add remaining request information
		$request['ReturnTransitAndCommit'] = true; // request tranzit time and commit data
		$request['RequestedShipment'] = array(
						'RateRequestTypes'	=> 'LIST'
					);

		return $result;
	}

	/**
	 * Get list of supported package types. Items in resulting array must
	 * corespond to constants in PackageType class.
	 *
	 * @return array
	 */
	public function getSupportedPackageTypes() {
		return array(
				PackageType::BOX_10,
				PackageType::BOX_20,
				PackageType::BOX,
				PackageType::ENVELOPE,
				PackageType::PAK,
				PackageType::TUBE,
				PackageType::USER_PACKAGING
			);
	}

	/**
	 * Get special params supported by delivery method which should be
	 * assigned with items in shop. Resulting array needs to contain
	 * array which will contain key-value pairs of localized group names
	 * and values and a second array with available params.
	 *
	 * @return array
	 */
	public function getAvailableParams() {
		$result = array();

		return $result;
	}

	/**
	 * Whether delivery method can be used for international deliveries.
	 *
	 * @return boolean
	 */
	public function isInternational() {
		return true;
	}
}

?>
