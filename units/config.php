<?php

/**
 * Main Configuration File
 */

use Core\Cache\Type as CacheType;

// paths
$site_path = 'site/';
$cache_path = $site_path.'cache/';

$system_path = 'system/';
$system_images_path = $system_path.'images/';
$system_styles_path = $system_path.'styles/';
$system_template_path = $system_path.'templates/';
$system_queries_path = $system_path.'queries/';
$system_module_path = 'modules/';

$data_path = $site_path.'data/';
$module_path = $site_path.'modules/';
$template_path = $site_path.'templates/';
$scripts_path = $site_path.'scripts/';
$styles_path = $site_path.'styles/';
$images_path = $site_path.'images/';

// language configuration
$available_languages = array('en');
$default_language = 'en';

// database
$db = null;
$db_type = DatabaseType::MYSQL;
$db_config = array(
		'host' => 'localhost',
		'user' => 'root',
		'pass' => '',
		'name' => 'database'
	);

// cache
$cache_method = CacheType::NONE;
$cache_expire_period = 86400;
$memcached_config = array(
		'host'	=> 'localhost',
		'port'	=> 11211
	);

// Custom configuration for self-hosted Closure compiler. Value
// needs to be in following format:
//     `array('hostname' => '...', 'endpoint' => '...')`.
$closure_compiler_config = null;

// Custom configuration for self-hosted Closure compiler. Value
// needs to be in following format:
//     `array('hostname' => '...', 'endpoint' => '...')`.
$closure_compiler_config = null;

// security
$force_https = false;

// head tag
$include_styles = false;
$optimize_code = false;

// various
$url_rewrite = false;
$url_add_extension = false;

// gravatar global variables
$gravatar_url = 'gravatar.com/avatar/{email_hash}?s={size}&amp;d={default}&amp;r={rating}';
$gravatar_rating = 'x';
$gravatar_default = 'mm';

?>
