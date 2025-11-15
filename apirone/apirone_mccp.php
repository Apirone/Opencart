<?php

define('PLUGIN_VERSION', '2.0.0');

define('OC_MAJOR_VERSION', (int) explode('.', VERSION, 2)[0]);

define('USER_TOKEN_KEY', (OC_MAJOR_VERSION > 2 ? 'user_' : '') . 'token');

define('EXTENSIONS_ROUTE', (OC_MAJOR_VERSION > 2 ? 'marketplace' : 'extension') . '/extension');

define('SETTINGS_CODE_PREFIX', OC_MAJOR_VERSION > 2 ? 'payment_' : '');
define('SETTINGS_CODE', SETTINGS_CODE_PREFIX . 'apirone_mccp');
define('SETTING_PREFIX', SETTINGS_CODE . '_');

/**
 * Path for plugin library modules
 */
define('PATH_TO_LIBRARY', OC_MAJOR_VERSION < 4
    ? DIR_SYSTEM . 'library/apirone/'
    : DIR_EXTENSION . 'apirone/system/library/');

/**
 * Path for load plugin models, langs translations, get links
 */
define('PATH_TO_RESOURCES', OC_MAJOR_VERSION < 4
    ? 'extension/payment/apirone_mccp'
    : 'extension/apirone/payment/apirone_mccp');

/**
 * Path for get links for controller routes
 */
define('PATH_FOR_ROUTES', PATH_TO_RESOURCES . (OC_MAJOR_VERSION < 4 ? '/' : '|'));

/**
 * Path for load plugin views
 */
define('PATH_TO_VIEWS', OC_MAJOR_VERSION < 4
    ? 'extension/payment/apirone/apirone_mccp'
    : 'extension/apirone/payment/apirone_mccp');

define('DEFAULT_STATUS_IDS', [
    'created' => 1,
    'partpaid' => 1,
    'paid' => 5,
    'overpaid' => 5,
    'completed' => 5,
    'expired' => 16,
]);

/**
 * Debug output
 * @param mixed $mixed
 * @param string $title
 * @return void
 */
function pa($mixed, $title = '') {
	echo '<pre>' . ($title ? $title . ': ' : '') . "\n";
	print_r(gettype($mixed) !== 'boolean' ? $mixed : ($mixed ? 'true' : 'false'));
	echo '</pre>';
}
