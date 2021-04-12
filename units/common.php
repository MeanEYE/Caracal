<?php

/**
 * Detect if browser is running on a mobile or desktop device.
 * Please note that this function is already called once and result
 * is stored in global constant _DESKTOP_VERSION. There's no need
 * for you to call this version manually.
 *
 * @return boolean
 */
function get_desktop_version() {
	$desktop_version = true;

	if (isset($_SERVER['HTTP_USER_AGENT'])) {
		$user_agent = strtolower($_SERVER['HTTP_USER_AGENT']);
		$desktop_version = strpos($user_agent, 'mobile') === false;

		// ipad tries to emulate mobile, avoid that
		if (!$desktop_version && strpos($user_agent, 'ipad') !== false)
			$desktop_version = true;
	}

	return $desktop_version;
}

/**
 * Remove illegal characters and tags from input strings to avoid XSS.
 *
 * @param string $string Input string
 * @return string
 * @author MeanEYE
 */
function fix_chars($string, $strip_tags=true) {
	if (!is_array($string)) {
		$string = strip_tags($string);
		$string = str_replace("*","&#42;", $string);
		$string = str_replace(chr(92).chr(34),"&#34;", $string);
		$string = str_replace("\r\n","\n", $string);
		$string = str_replace("\'","&#39;", $string);
		$string = str_replace("'","&#39;", $string);
		$string = str_replace(chr(34),"&#34;", $string);
		$string = str_replace("<", "&lt;", $string);
		$string = str_replace(">", "&gt;", $string);
	} else {
		foreach($string as $key => $value)
			$string[$key] = fix_chars($value);
	}
    return $string;
}

/**
 * Strip tags and escape the rest of the string
 *
 * @param mixed $string
 * @param boolean $strip_tags
 * @return mixed
 */
function escape_chars($string, $strip_tags=true) {
	global $db;

	if (!is_array($string)) {
		// get rid of slashes
		if (version_compare(PHP_VERSION, '7.4.0') <= 0 && get_magic_quotes_gpc())
			$string = stripcslashes($string);

		// remove tags
		if ($strip_tags)
			$string = strip_tags($string);

		// esape the rest of the string
		if ($db->is_active())
			$string = $db->escape_string($string); else
			$string = mysql_real_escape_string($string);

	} else {
		foreach($string as $key => $value)
			$string[$key] = escape_chars($value);
	}

	return $string;
}

/**
 * Prevent potential SQL injection by calling this function brefore
 * using ID value from parameters.
 *
 * @param string $string
 * @return string
 * @author MeanEYE
 */
function fix_id($string) {
	if (is_array($string)) {
		foreach ($string as $key => $value)
			$res[$key] = fix_id($value);

	} else {
		$res = explode(' ', $string);
		$res = preg_replace('/[^\d]*/i', '', $res[0]);

		if (!is_numeric($res)) $res = 0;
	}

	return $res;
}

/**
 * A rollback for fix_chars function. This function should be used to prepare text
 * for editing, when text is entered through web interface. This function replaces
 * <br> with \n and <b> with [b]...
 *
 * @param string $string
 * @return string
 * @author MeanEYE
 */
function unfix_chars($string) {
	if (!is_array($string)) {
		$string = str_replace("&#42;", "*", $string);
		$string = str_replace("&#34;", chr(34), $string);
		$string = str_replace("&#39;", "'", $string);
		$string = str_replace("&#39;", "'", $string);
		$string = str_replace("&lt;", "<", $string);
		$string = str_replace("&gt;", ">", $string);
	} else {
		foreach($string as $key => $value)
			$string[$key] = unfix_chars($value);
	}

    return $string;
}

/**
 * Checks if browser is non-IE
 *
 * @return boolean
 */
function is_browser_ok() {
	$result = true;

	if (isset($_SERVER['HTTP_USER_AGENT'])) {
		$company = strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE') !== false;
		$engine = strpos($_SERVER['HTTP_USER_AGENT'], 'Trident') !== false;

		$result = !($company || $engine);
	}

	return $result;
}

/**
 * Function which returns boolean value denoting if system should enforce
 * HTTPS protocol and redirect user to new page. Value is determined based
 * on couple factors but it does allow user to override the value.
 *
 * @return boolean
 */
function should_force_https() {
	global $force_https;

	if (in_array(_DOMAIN, array('127.0.0.1', '127.0.1.1', 'localhost')))
		return false;

	$forced_sections = array('backend', 'backend_module');
	$forced_section = isset($_REQUEST['section']) && in_array($_REQUEST['section'], $forced_sections);
	return !_SECURE && ($force_https || $forced_section);
}

/**
 * Return abbreviated string containing specified number of words ending
 * given string. If number of words in string is lower than limit whole
 * string is returned. This function is effective for all languages
 * that use space character. This function will not work on Mandarian,
 * Korean, Japanese and other laguages using the same formation. Hebrew
 * text will work properly but you might need to reverse string before
 * calling the function.
 *
 * @param string $str
 * @param integer $limit
 * @param string $end_char
 * @return string
 * @author MeanEYE
 */
function limit_words($text, $limit = 100, $end_char = '&#8230;') {
	$result = $text;
	$encoding = mb_detect_encoding($text);

	if (mb_strlen($text, $encoding) > $limit)
		$result = mb_substr($text, 0, $limit, $encoding).$end_char;

	return $result;
}

/**
 * Reverse UTF8 text and leave numbers intact. This function was
 * made to compensate pre CS5 flash string handling with embeded fonts.
 *
 * @param string $str
 * @param boolean $revers_numbers
 * @return string
 */
function utf8_strrev($str, $reverse_numbers=false) {
	preg_match_all('/./us', $str, $ar);
	if ($reverse_numbers)
		return join('',array_reverse($ar[0]));
	else {
		$temp = array();
		foreach ($ar[0] as $value) {
			if (is_numeric($value) && !empty($temp[0]) && is_numeric($temp[0])) {
				foreach ($temp as $key => $value2) {
					if (is_numeric($value2))
						$pos = ($key + 1);
					else break;
				}
				$temp2 = array_splice($temp, $pos);
				$temp = array_merge($temp, array($value), $temp2);
			} else array_unshift($temp, $value);
		}
		return implode('', $temp);
	}
}

/**
 * Hard wrap UTF8 string to specified width.
 *
 * @param string $string
 * @param integer $width
 * @param string $break
 * @param boolean $cut
 * @return string
 */
function utf8_wordwrap($string, $width=75, $break="\n", $cut=false) {
	if ($cut) {
		// Match anything 1 to $width chars long followed by whitespace or EOS,
		// otherwise match anything $width chars long
		$search = '/(.{1,'.$width.'})(?:\s|$)|(.{'.$width.'})/uS';
		$replace = '$1$2'.$break;

	} else {
		// Anchor the beginning of the pattern with a lookahead
		// to avoid crazy backtracking when words are longer than $width
		$search = '/(?=\s)(.{1,'.$width.'})(?:\s|$)/uS';
		$replace = '$1'.$break;
	}

	return preg_replace($search, $replace, $string);
}

/**
 * Simple function that provides Google generated QR codes
 * Refer to:
 * 		http://code.google.com/apis/chart/types.html#qrcodes
 * 		http://code.google.com/p/zxing/wiki/BarcodeContents
 *
 * @param string $url
 * @param integer $size
 * @return string
 */
function get_qr_image($uri, $size=100, $error_correction="L") {
	$url = rawurlencode($uri);
	$result = "http://chart.apis.google.com/chart?".
				"chld={$error_correction}|1&amp;".
				"chs={$size}x{$size}&amp;".
				"cht=qr&amp;chl={$url}&amp;".
				"choe=UTF-8";

	return $result;
}

?>
