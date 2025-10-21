<?php

define('OC_MAJOR_VERSION', (int) explode('.', VERSION, 2)[0]);

define('USER_TOKEN_KEY', (OC_MAJOR_VERSION > 2 ? 'user_' : '') . 'token');

define('EXTENSIONS_ROUTE', (OC_MAJOR_VERSION > 2 ? 'marketplace' : 'extension') . '/extension');

define('PLUGIN_VERSION', '2.0.0');

define('SETTINGS_CODE_PREFIX', OC_MAJOR_VERSION > 2 ? 'payment_' : '');
define('SETTINGS_CODE', SETTINGS_CODE_PREFIX . 'apirone_mccp');
define('SETTING_PREFIX', SETTINGS_CODE . '_');

/**
 * Path for load plugin models, langs translations, get links
 */
define('PATH_TO_RESOURCES', OC_MAJOR_VERSION > 3 ? 'extension/apirone/payment/apirone_mccp' : 'extension/payment/apirone_mccp');

/**
 * Path for load plugin views
 */
define('PATH_TO_VIEWS', OC_MAJOR_VERSION > 3 ? 'extension/apirone/payment/apirone_mccp' : 'extension/payment/apirone/apirone_mccp');

define('DEFAULT_STATUS_IDS', [
    'created' => 1,
    'partpaid' => 1,
    'paid' => 5,
    'overpaid' => 5,
    'completed' => 5,
    'expired' => 16,
]);
