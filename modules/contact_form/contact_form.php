<?php

/**
 * Contact Form
 *
 * This contact form provides multiple ways of contacting user. It can be
 * used WITHOUT database connection or with it.
 *
 * Author: Mladen Mijatov
 */
require_once('units/smtp.php');


class contact_form extends Module {
	private static $_instance;
	private $_invalid_params = array(
						'section', 'action', 'PHPSESSID', '__utmz', '__utma',
						'__utmc', '__utmb', '_', 'subject', 'MAX_FILE_SIZE', '_rewrite'
					);

	/**
	 * Constructor
	 */
	protected function __construct() {
		global $section;
		
		parent::__construct(__FILE__);
		
		// register backend
		if ($section == 'backend' && class_exists('backend')) {
			$backend = backend::getInstance();

			$contact_menu = new backend_MenuItem(
					$this->getLanguageConstant('menu_contact'),
					url_GetFromFilePath($this->path.'images/icon.png'),
					'javascript:void(0);',
					$level=5
				);
			
			$contact_menu->addChild('', new backend_MenuItem(
								$this->getLanguageConstant('menu_settings'),
								url_GetFromFilePath($this->path.'images/settings.png'),

								window_Open( // on click open window
											'contact_form_settings',
											400,
											$this->getLanguageConstant('title_settings'),
											true, true,
											backend_UrlMake($this->name, 'settings_show')
										),
								$level=5
							));	

			$backend->addMenu($this->name, $contact_menu);
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
	 * Transfers control to module functions
	 *
	 * @param array $params
	 * @param array $children
	 */
	public function transferControl($params, $children) {
		// global control actions
		if (isset($params['action']))
			switch ($params['action']) {
				case 'send_from_xml':
					$this->sendFromXML($params, $children);
					break;

				case 'send_from_ajax':
					$this->sendFromAJAX();
					break;
					
				default:
					break;
			}

		// global control actions
		if (isset($params['backend_action']))
			switch ($params['backend_action']) {
				case 'settings_show':
					$this->showSettings();
					break;

				case 'settings_save':
					$this->saveSettings();
					break;
					
				default:
					break;
			}
	}

	/**
	 * Event triggered upon module initialization
	 */
	public function onInit() {
		$this->saveSetting('use_smtp', 0);
		$this->saveSetting('sender_name', '');
		$this->saveSetting('sender_address', 'sample@email.com');
		$this->saveSetting('recipient_name', '');
		$this->saveSetting('recipient_address', 'sample@email.com');
		$this->saveSetting('recipient_subject', 'Caracal contact email');
		$this->saveSetting('smtp_server', 'smtp.gmail.com');
		$this->saveSetting('smtp_port', '465');
		$this->saveSetting('use_ssl', 1);
		$this->saveSetting('save_copy', 0);
		$this->saveSetting('save_location', '');
	}

	/**
	 * Event triggered upon module deinitialization
	 */
	public function onDisable() {
		global $db;
	}

	/**
	 * Function that tries to figgure out if human is sending the data.
	 *
	 * @param boolean $strict Whether to be strict when checking for bots
	 * @return boolean
	 */
	public function detectBots($strict=false) {
		$result = false;

		// every browser sets user agent field, absence of one almost
		// always means user submitting data is actually a bot
		if (empty($_SERVER['HTTP_USER_AGENT']))
			$result = true;

		// most of modern browsers set referer field, however it's possible
		// for this field not to be set by some browsers when submitting form
		if ($strict && empty($_SERVER['HTTP_REFERER']))
			$result = true;

		return $result;
	}

	/**
	 * Process mail sending request issued by template parser
	 *
	 * @param array $params
	 * @param array $children
	 */
	public function sendFromXML($params, $children) {
		$to = "";
		$subject = "";
		$fields = array();
		$template_params = array();
		$headers = array();
		$message_success = null;
		$message_error = null;
		$attachments = array();

		foreach($children as $param)
			switch ($param->tagName) {
				case 'to':
					$to = $param->tagData;
					$template_params['_to'] = $to;
					break;

				case 'subject':
					$subject = $param->tagData;
					$subject = $this->generateSubjectField($subject, $fields);
					$template_params['_subject'] = $subject;
					break;

				case 'from':
					if (array_key_exists('name', $param->tagAttrs))
						$name = $param->tagAttrs['name']; else
						$name = $param->tagData;

					$address = $param->tagData;
					$headers['From'] = $this->generateAddressField($name, $address);
					$template_params['_from'] = "{$param->tagAttrs['name']} <{$param->tagData}>";
					break;

				case 'fields':
					foreach($param->tagChildren as $field) {
						$fields[$field->tagData] = isset($_REQUEST[$field->tagAttrs['name']]) ? fix_chars($_REQUEST[$field->tagAttrs['name']]) : '';
						$template_params[$field->tagAttrs['name']] = isset($_REQUEST[$field->tagAttrs['name']]) ? fix_chars($_REQUEST[$field->tagAttrs['name']]) : '';
					}

					break;

				case 'message_success':
					$message_success = $param->tagChildren;
					break;

				case 'message_error':
					$message_error = $param->tagChildren;
					break;
			}

		$headers['X-Mailer'] = "Cracal-Framework/1.0";

		// if address is not specified by the XML, check for system setting
		if (empty($to) && isset($this->settings['recipient_address'])) {
			$to = $this->settings['recipient_address'];
			$template_params['_to'] = $to;
		}
	
		// attach speficied files
		if (count($_FILES) > 0)
			foreach($_FILES as $name => $data) {
				$temp_name = $data['tmp_name'];
				$name = $data['name'];
				$attachments[$temp_name] = $name;
			}

		if ($this->_sendMail($to, $subject, $headers, $fields, $attachments)) {
			// message successfuly sent
			if (!is_null($message_success)) {
				$template = new TemplateHandler();
				$template->setMappedModule($this->name);
				$template->setLocalParams($template_params);
				$template->parse($message_success);
			}
		} else {
			// error sending
			if (!is_null($message_error)) {
				$template = new TemplateHandler();
				$template->setMappedModule($this->name);
				$template->setLocalParams($template_params);
				$template->parse($message_error);
			}
		}
	}

	/**
	 * Send contact form data using AJAX request
	 */
	public function sendFromAJAX($skip_message=False) {
		$result = array(
					'error'		=> false,
					'message'	=> ''
				);
		$attachments = array();

		if (isset($this->settings['recipient_address'])) {
			$to = $this->settings['recipient_address'];
			$fields = array();
			$headers = array(
							'X-Mailer'	=> "Cracal-Framework/1.0"
						);

			// allow setting subject in request but sanitize heavily
			if (isset($_REQUEST['subject'])) {
				$subject = fix_chars($_REQUEST['subject']);
				$subject = str_replace(array('/', '\\', '.'), '-', $subject);

			} else {
				$subject = $this->settings['recipient_subject'];
			}

			// attach speficied files
			if (count($_FILES) > 0)
				foreach($_FILES as $name => $data) {
					$temp_name = $data['tmp_name'];
					$name = $data['name'];
					$attachments[$temp_name] = $name;
				}

			// prepare sender
			$name = $this->settings['sender_name'];
			$address = $this->settings['sender_address'];
			$headers['From'] = $this->generateAddressField($name, $address);

			foreach($_REQUEST as $key => $value)
				if (!in_array($key, $this->_invalid_params))
					$fields[$key] = fix_chars($value);

			// format subject
			$subject = $this->generateSubjectField($subject, $fields);

			// try sending message
			if ($this->_sendMail($to, $subject, $headers, $fields, $attachments)) {
				// message successfuly sent
				$result['message'] = $this->getLanguageConstant('message_sent');

			} else {
				// error sending
				$result['error'] = true;
				$result['message'] = $this->getLanguageConstant('message_error');
			}

		} else {
			$result['error'] = true;
			$result['message'] = $this->getLanguageConstant('message_error_no_address');
		}

		if (!$skip_message)
			print json_encode($result);
	}
	
	/**
	 * Send mail from different module with specified parameters
	 * 
	 * @param string $to
	 * @param string $subject
	 * @param string $text_body
	 * @param string $html_body
	 * @param string $bcc
	 *
	 * TODO: Simplify this code.
	 */
	public function sendFromModule($to, $subject, $text_body, $html_body, $bcc=null) {
		$headers = array();
		$headers['X-Mailer'] = "Cracal-Framework/1.0";

		// prepare recipient
		if ($to == '' || is_null($to)) 
			$to = $this->settings['recipient_address'];
		
		// prepare subject
		if ($subject == '' || is_null($subject))
			$subject = $this->settings['recipient_subject'];
		
		$subject = $this->generateSubjectField($subject);
		
		// prepare sender
		$name = $this->settings['sender_name'];
		$address = $this->settings['sender_address'];
		$headers['From'] = $this->generateAddressField($name, $address);

		// add bcc if specified setting bcc = -1 will use from 
		// field effectively sending copy of email to sender
		if (!is_null($bcc)) 
			if (is_numeric($bcc) && $bcc == -1)
				$headers['Bcc'] = $headers['From']; else
				$headers['Bcc'] = $bcc;
		
		// create boundary string
		$boundary = md5(time().'--cms--'.(rand() * 10000));
		$headers['Content-Type'] = "multipart/alternative; boundary={$boundary}";
		
		// create mail body
		if (!empty($html_body)) {
			// make plain text body
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
			$body .= "Content-Transfer-Encoding: base64\r\n\r\n";
			$body .= base64_encode($text_body)."\r\n";
	
			// make html body
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Type: text/html; charset=UTF-8\r\n";
			$body .= "Content-Transfer-Encoding: base64\r\n\r\n";
			$body .= base64_encode($html_body)."\r\n";
	
			// make ending boundary
			$body .= "--{$boundary}--\r\n";
			
		} else {
			// no HTML specified, use plain text
			$body = "\r\n".$text_body;
		}		
		
		// get headers string
		$headers_string = $this->_makeHeaders($headers);

		if (!$this->detectBots())
			if ($this->settings['use_smtp']) {
				$smtp = new SMTP();
				$smtp->set_server(
							$this->settings['smtp_server'],
							$this->settings['smtp_port'],
							$this->settings['use_ssl']
						);
				
				if ($this->settings['smtp_authenticate'])
					$smtp->set_credentials(
								$this->settings['smtp_username'],
								$this->settings['smtp_password']
							);

				$smtp->set_sender($this->settings['sender_address']);

				// add recipients
				$recipient_list = explode(',', $to);
				foreach($recipient_list as $address)
					$smtp->add_recipient($address);

				$smtp->set_subject($subject);
				$result = $smtp->send($headers_string, $body);

			} else {
				// send mail using PHP function
				$result = mail($to, $subject, $body, $headers_string);
			}
		
		return $result;
	}

	/**
	 * Generate address string
	 *
	 * @param array/string $name
	 * @param array/string $address
	 * @return string
	 */
	public function generateAddressField($name, $address) {
		$result = '';

		if (is_array($name)) {
			// generate from multiple addresses
			$temp = array();

			for ($i = 0; $i < count($address); $i++) {
				$name_value = isset($name[$i]) ? $name[$i] : '';
				$temp[] = $this->generateAddressField($name_value, $address[$i]);
			}

			$result = implode(',', $temp);

		} else {
			// generate from single address
			if (!empty($name)) {
				$name = $this->encodeString($name);
				$result = "{$name} <{$address}>";
			} else {
				$result = $address;
			}
		}

		return $result;
	}

	/**
	 * Generate subject using specified template.
	 *
	 * @param string $template
	 * @param array $fields
	 */
	public function generateSubjectField($template, $fields=array()) {
		$keys = array_keys($fields);
		$values = array_values($fields);

		// preformat keys for replacement
		foreach ($keys as $index => $key)
			$keys[$index] = "%{$key}%";

		// replace field place holders with values
		$subject = str_replace($keys, $values, $template);

		return $this->encodeString($subject);
	}

	/**
	 * Create UTF-8 base64 encoded string.
	 *
	 * @param string $string
	 * @return string
	 */
	public function encodeString($string) {
		return "=?utf-8?B?".base64_encode($string)."?=";
	}

	/**
	 * Perform send email
	 *
	 * @param string $to
	 * @param string $subject
	 * @param array $headers
	 * @param array $fields
	 * @param array $attachments
	 * @return boolean
	 */
	private function _sendMail($to, $subject, $headers, $fields, $attachments=array()) {
		global $data_path;

		$result = false;

		// generate boundary string
		$boundary = md5(time().'--global--'.(rand() * 10000));

		// add content type to headers
		if (count($attachments) == 0)
			$headers['Content-Type'] = "multipart/alternative; boundary={$boundary}"; else
			$headers['Content-Type'] = "multipart/mixed; boundary={$boundary}";

		// make body and headers
		$body = $this->_makeBody($fields, $boundary, $attachments);
		$headers_string = $this->_makeHeaders($headers);

		if (!$this->detectBots()) {
			if ($this->settings['use_smtp']) {
				// send email using SMTP
				$smtp = new SMTP();
				$smtp->set_server(
							$this->settings['smtp_server'],
							$this->settings['smtp_port'],
							$this->settings['use_ssl']
						);
				
				if ($this->settings['smtp_authenticate'])
					$smtp->set_credentials(
								$this->settings['smtp_username'],
								$this->settings['smtp_password']
							);

				$smtp->set_sender($this->settings['sender_address']);

				// add recipients
				$recipient_list = explode(',', $to);
				foreach($recipient_list as $address)
					$smtp->add_recipient($address);

				$smtp->set_subject($subject);
				$result = $smtp->send($headers_string, $body);

			} else {
				// send email using built-in function
				$result = mail($to, $subject, $body, $headers_string);
			}

			// store email after sending
			if (isset($this->settings['save_location']) && !empty($this->settings['save_location']))
				$location = $this->settings['save_location']; else
				$location = _BASEPATH.'/'.$data_path;

			$save_copy = isset($this->settings['save_copy']) ? $this->settings['save_copy'] : false;
			$location_okay = is_writable($location);

			if ($result && $save_copy && $location_okay) {
				$timestamp = strftime('%c');
				$file_name = $location."/{$subject} - {$timestamp}.eml";

				$data = "To: {$to}\r\n";
				$data .= "Subject: {$subject}\r\n";
				$data .= $headers_string."\r\n\r\n".$body;

				// try to open file for saving email
				$handle = fopen($file_name, 'w');
				
				if ($handle !== false) {
					// save email content to specifed file
					fwrite($handle, $data);
					fclose($handle);

				} else {
					// log error
					trigger_error("Unable to open file for saving: {$file_name}", E_USER_WARNING);
				}
			
			} else if (!$location_okay) {
				// log problem with destination directory
				trigger_error("Directory is not writable! Unable to save email to: {$location}", E_USER_WARNING);
			}
		}

		return $result;
	}

	/**
	 * Create header string from specified array
	 *
	 * @param array $headers
	 * @return string
	 */
	private function _makeHeaders($headers) {
		$result = array();

		foreach ($headers as $key => $value)
			$result[] = "{$key}: {$value}";

		return join("\r\n", $result);
	}

	/**
	 * Create message body from fields
	 *
	 * @param array $fields
	 * @param string $boundary
	 * @param array $attachments Associative array of attachments
	 * @return string
	 *
	 * Example:
	 * 		$this->_makeBody(
	 * 					array(
	 * 						'field' => 'value'
	 * 					),
	 * 					'some-string',
	 * 					array(
	 * 						'/var/tmp/somefile-0000.tmp' => 'archive.zip'
	 * 					)
	 * 				);
	 */
	private function _makeBody($fields, $boundary, $attachments=array()) {
		$result = "";
		$content_boundary = md5(time().'--content--'.(rand() * 10000));

		// add global boundary
		$result .= "--{$boundary}\r\n";
		$result .= "Content-Type: multipart/alternative; boundary={$content_boundary}\r\n\r\n";

		// make plain text body
		$result .= "--{$content_boundary}\r\n";
		$result .= "Content-Type: text/plain; charset=UTF-8\r\n";
		$result .= "Content-Transfer-Encoding: base64\r\n\r\n";
		$result .= base64_encode($this->makePlainBody($fields))."\r\n";

		// make html body
		$result .= "--{$content_boundary}\r\n";
		$result .= "Content-Type: text/html; charset=UTF-8\r\n";
		$result .= "Content-Transfer-Encoding: base64\r\n\r\n";
		$result .= base64_encode($this->makeHtmlBody($fields))."\r\n";
		$result .= "--{$content_boundary}--\r\n";

		// attach files
		if (count($attachments) > 0)
			foreach ($attachments as $file => $name)
				$result .= $this->makeAttachment($file, $name, $boundary);

		// make ending boundary
		$result .= "--{$boundary}--\r\n";

		return $result;
	}

	/**
	 * Generate plain text message body from specified fields.
	 *
	 * @param array $fields
	 * @return string
	 */
	public function makePlainBody($fields) {
		$result = "";
		$max_length = 0;

		foreach($fields as $name => $value)
			if (strlen($name) > $max_length) $max_length = strlen($name);

		foreach($fields as $name => $value)
			$result .= $name.str_repeat(" ", $max_length-strlen($name)).": {$value}\r\n";

		return $result;
	}

	/**
	 * Generate HTML body containing table with specified fields.
	 *
	 * @param array $fields
	 * @return string
	 */
	public function makeHtmlBody($fields) {
		$is_rtl = MainLanguageHandler::getInstance()->isRTL();
		$direction = $is_rtl ? 'direction: rtl;' : 'direction: ltr;';
		$result = '<table width="100%" cellspacing="0" cellpadding="5" border="1" frame="box" rules="rows">';

		foreach($fields as $name => $value)
			if ($is_rtl) 
				$result .= '<tr><td valign="top" style="'.$direction.'">'.$value.'</td><td valign="top" style="'.$direction.'"><b>'.$name.'</b></td></tr>'; else
				$result .= '<tr><td valign="top" style="'.$direction.'"><b>'.$name.'</b></td><td valign="top" style="'.$direction.'">'.$value.'</td></tr>';

		$result .= '</table>';

		return $result;
	}

	/**
	 * Create string for file to be attached to body of email.
	 *
	 * @param string $file_name Location of file to be attached
	 * @param string $attachment_name Name of file appearing in email
	 * @param string $boundary Boundary string used to separate content
	 * @return string
	 */
	public function makeAttachment($file_name, $attachment_name, $boundary) {
		$result = '';
		$attachment_name = $this->encodeString($attachment_name);

		if (file_exists($file_name)) {
			$data = file_get_contents($file_name);
			$data = base64_encode($data);

			// get file mime type
			$handle = finfo_open(FILEINFO_MIME_TYPE);
			$mime_type = finfo_file($handle, $file_name);
			finfo_close($handle);

			// create result
			$result = "--{$boundary}\r\n";
			$result .= "Content-Type: {$mime_type}; charset=US-ASCII; name=\"{$attachment_name}\"\r\n";
			$result .= "Content-Disposition: attachment; filename=\"{$attachment_name}\"\r\n";
			$result .= "Content-Transfer-Encoding: base64\r\n\r\n";
			$result .= $data."\r\n";
		}

		return $result;
	}
	
	/**
	 * Show settings form
	 */
	private function showSettings() {
		$template = new TemplateHandler('settings.xml', $this->path.'templates/');
		$template->setMappedModule($this->name);

		$params = array(
						'form_action'	=> backend_UrlMake($this->name, 'settings_save'),
						'cancel_action'	=> window_Close('contact_form_settings')
					);

		$template->restoreXML();
		$template->setLocalParams($params);
		$template->parse();
	}

	/**
	 * Save settings
	 */
	private function saveSettings() {
		// grab parameters
		$use_smtp = isset($_REQUEST['use_smtp']) && ($_REQUEST['use_smtp'] == 'on' || $_REQUEST['use_smtp'] == '1') ? 1 : 0;
		$use_ssl = isset($_REQUEST['use_ssl']) && ($_REQUEST['use_ssl'] == 'on' || $_REQUEST['use_ssl'] == '1') ? 1 : 0;
		$save_copy = isset($_REQUEST['save_copy']) && ($_REQUEST['save_copy'] == 'on' || $_REQUEST['save_copy'] == '1') ? 1 : 0;

		$params = array(
			'sender_name', 'sender_address', 'recipient_name', 'recipient_address', 'recipient_subject',
			'smtp_server', 'smtp_port', 'smtp_authenticate', 'smtp_username', 'smtp_password', 'save_location'
		);

		// save settings
		foreach($params as $param) {
			$value = fix_chars($_REQUEST[$param]);
			$this->saveSetting($param, $value);
		}

		$this->saveSetting('use_smtp', $use_smtp);
		$this->saveSetting('use_ssl', $use_ssl);
		$this->saveSetting('save_copy', $save_copy);

		// show message
		$template = new TemplateHandler('message.xml', $this->path.'templates/');
		$template->setMappedModule($this->name);

		$params = array(
					'message'	=> $this->getLanguageConstant('message_saved'),
					'button'	=> $this->getLanguageConstant('close'),
					'action'	=> window_Close('contact_form_settings')
				);

		$template->restoreXML();
		$template->setLocalParams($params);
		$template->parse();
	}
}
