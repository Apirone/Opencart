<?php

use Apirone\API\Endpoints\Service;
use Apirone\API\Exceptions\RuntimeException;
use Apirone\API\Exceptions\ValidationFailedException;
use Apirone\API\Exceptions\UnauthorizedException;
use Apirone\API\Exceptions\ForbiddenException;
use Apirone\API\Exceptions\NotFoundException;
use Apirone\API\Exceptions\MethodNotAllowedException;
use Apirone\API\Http\Request;
use Apirone\API\Log\LogLevel;

use Apirone\SDK\Invoice;
use Apirone\SDK\Service\InvoiceQuery;
use Apirone\SDK\Model\Settings;
use Apirone\SDK\Model\Settings\Coin;

require_once(DIR_SYSTEM . 'library/apirone_api/Apirone.php');

require_once(DIR_SYSTEM . 'library/apirone/vendor/autoload.php');

define('PLUGIN_VERSION', '2.0.0');
define('PLUGIN_LOG_FILE_NAME', 'apirone.log');

class ControllerExtensionPaymentApironeMccp extends Controller
{
    private Settings $settings;
    private $data = [];
    private $error = [];

    public function __construct($registry)
    {
        parent::__construct($registry);
        $this->initLogging();
        $this->update();
    }

    /**
     * @return write to log extended info except errors
     * @since 2.0.0
     * @author Valery Yu <vvy1976@gmail.com>
     * @see also in catalog/controller/extension/payment/apirone_mccp.php
     * @internal
     */
    private function isDebug()
    {
        return !isset($this->settings) ? false : !!$this->settings->debug;
    }

    /**
     * Initializes logging
     * @since 2.0.0
     * @author Valery Yu <vvy1976@gmail.com>
     * @see also in catalog/controller/extension/payment/apirone_mccp.php
     * @internal
     */
    private function initLogging()
    {
        try {
            $openCartLogger = new \Log(PLUGIN_LOG_FILE_NAME);

            $logHandler = function($log_level, $message, $context) use ($openCartLogger) {
                if ($log_level == LogLevel::ERROR || $this->isDebug()) {
                    $openCartLogger->write($message.' '.print_r($context));
                }
            };
            Invoice::logger($logHandler);
        }
        catch (Exception $e) {
            $this->log->write($e->getMessage());
        }
    }

    /**
     * Loads main payment settings admin page in response to GET or POST request
     * @api
     */
    public function index()
    {
        $this->load->language('extension/payment/apirone_mccp');
        $this->load->model('extension/payment/apirone_mccp');
        try {
            $this->getSettings();
            $this->data['settings_loaded'] = true;
        }
        catch (\Throwable $e) {
            $this->log->write($e->getMessage());

            $this->error['warning'] = $this->data['error'] = $this->language->get('error_service_not_available');
            $this->setCommonPageData();
            return;
        }
        $account = $this->settings->account;
        $saved_processing_fee = $this->settings->processing_fee;
        $networks = $this->settings->networks;

        $has_errors = false;
        $active_networks = false;
        $networks_update_need = false;
        $coins_update_need = false;

        if (!$this->user->hasPermission('modify', 'extension/payment/apirone_mccp')) {
            $this->data['error'] = $this->language->get('error_permission');
            $has_errors = true;
        }

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            $processing_fee = $this->request->post['apirone_mccp_processing_fee'];
            if ($processing_fee != $saved_processing_fee) {
                $this->settings->processing_fee($processing_fee);
                $networks_update_need = true;
            }
            $coins = [];

            $visible_from_post = $this->request->post['visible'];

            foreach ($networks as $network) {
                $abbr = $network->abbr;
                $address = $this->request->post['address'][$abbr];
                if ($address != $network->address) {
                    $network->address($address);
                    $networks_update_need = true;
                    $coins_update_need = true;
                }
                if (!$address) {
                    continue;
                }
                $coins[] = Coin::init($network);
                $network->policy($processing_fee);

                $tokens = $network->tokens;
                if (!count($tokens)) {
                    continue;
                }
                $state = !empty($visible_from_post) && array_key_exists($abbr, $visible_from_post) && $visible_from_post[$abbr];

                if ($state != $this->getNetworkTokenVisibility($abbr)) {
                    $coins_update_need = true;
                }
                foreach ($tokens as $token) {
                    $abbr = $token->abbr;
                    $state = !empty($visible_from_post) && array_key_exists($abbr, $visible_from_post) && $visible_from_post[$abbr];

                    if ($state != $this->getNetworkTokenVisibility($abbr)) {
                        $coins_update_need = true;
                    }
                    $coins[] = Coin::init($token);
                    $token->policy($processing_fee);
                }
            }
            if ($coins_update_need) {
                $this->settings->coins($coins);
            }
            if ($networks_update_need) {
                $this->settings->saveCurrencies();

                foreach ($networks as $network) {
                    if ($network->hasError()) {
                        if (!$this->data['error']) {
                            $this->data['error'] = sprintf($this->language->get('error_currency_save'), $network->name, $network->error);
                        }
                        $has_errors = true;
                    }
                }
            }
            foreach ($networks as $network) {
                $active_networks = $active_networks || !!$network->address;
            }
        }
        // Set values into template vars
        $this->setValue('merchant');
        $this->setValue('testcustomer');
        $this->setValue('timeout', false, true);
        $this->setValue('processing_fee');
        $this->setValue('factor', false, true);
        $this->setValue('show_in_major');
        $this->setValue('show_with_fee');
        $this->setValue('show_in_fiat');
        $this->setValue('logo');
        $this->setValue('debug');

        $this->setValue('status', true);
        $this->setValue('geo_zone_id', true);
        $this->setValue('sort_order', true);

        $this->data['apirone_mccp_account'] = $account;

        if (!($active_networks && count($networks)
            && $this->data['apirone_mccp_timeout'] > 0
            && $this->data['apirone_mccp_factor'] > 0
        )) {
            $has_errors = true;
        }
        $has_errors = $has_errors || !!count($this->error);

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            if ($has_errors) {
                $this->data['error'] = $this->language->get('error_warning');
                // No addresses
                if (!$active_networks) {
                    $this->data['error'] = $this->language->get('error_empty_currencies');
                }
                // Payment timeout
                $timeout = $this->data['apirone_mccp_timeout'];
                if ($timeout === 0 || $timeout < 0) {
                    $this->error['apirone_mccp_timeout'] = $this->language->get('error_apirone_mccp_timeout_positive');
                }
                else if (empty($timeout)) {
                    $this->error['apirone_mccp_timeout'] = $this->language->get('error_apirone_mccp_timeout');
                }
                // Invalid payment adjustment factor
                $factor = $this->data['apirone_mccp_factor'];
                if ($factor <= 0 || empty($factor)) {
                    $this->error['apirone_mccp_factor'] = $this->language->get('error_apirone_mccp_factor');
                }
            }
            else {
                // Save settings if post & no errors
                $plugin_data['apirone_mccp_settings'] = $this->settings
                    ->merchant(trim($this->request->post['apirone_mccp_merchant']))
                    ->testcustomer(trim($this->request->post['apirone_mccp_testcustomer']))
                    ->timeout(intval($this->request->post['apirone_mccp_timeout']))
                    ->processing_fee($this->request->post['apirone_mccp_processing_fee'])
                    ->factor(floatval($this->request->post['apirone_mccp_factor']))
                    ->show_in_major(!!$this->request->post['apirone_mccp_show_in_major'])
                    ->show_with_fee(!!$this->request->post['apirone_mccp_show_with_fee'])
                    ->show_in_fiat(!!$this->request->post['apirone_mccp_show_in_fiat'])
                    ->logo(!!$this->request->post['apirone_mccp_logo'])
                    ->debug(!!$this->request->post['apirone_mccp_debug'])
                    ->toJsonString();

                $plugin_data['apirone_mccp_geo_zone_id'] = $this->request->post['apirone_mccp_geo_zone_id'];
                $plugin_data['apirone_mccp_status'] = $this->request->post['apirone_mccp_status'];
                $plugin_data['apirone_mccp_sort_order'] = $this->request->post['apirone_mccp_sort_order'];

                $this->load->model('setting/setting');
                $this->model_setting_setting->editSetting('apirone_mccp', $plugin_data);

                $this->data['success'] = $this->language->get('text_success');
            }
        }
        if (count($networks) > 0) {
            $this->data['networks'] = $this->getNetworksViewModel();
        }
        else {
            $this->data['error'] = $this->language->get('error_cant_get_currencies');
        }
        $this->setCommonPageData();
    }

    /**
     * Set common page data
     * @internal
     */
    protected function setCommonPageData()
    {
        $this->document->setTitle($this->language->get('heading_title'));

        $this->data = array_merge($this->data, $this->load->language('apirone_mccp'));

        $this->load->model('localisation/order_status');
        $this->data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->data['order_statuses'][] = ['order_status_id' => 0, 'name' => $this->language->get('text_missing')];

        $this->load->model('localisation/geo_zone');
        $this->data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $this->getBreadcrumbsAndActions();

        $this->data['apirone_mccp_version'] = PLUGIN_VERSION;
        $this->data['oc_version'] = VERSION;
        $this->data['phpversion'] = phpversion();
        $this->data['errors'] = $this->error;
        $this->data['header'] = $this->load->controller('common/header');
        $this->data['column_left'] = $this->load->controller('common/column_left');
        $this->data['footer'] = $this->load->controller('common/footer');

        if (empty($this->data['apirone_mccp_account'])) {
            $this->data['apirone_mccp_account'] = $this->language->get('text_account_not_exist');
            if (!array_key_exists('error', $this->data)) {
                $this->data['error'] = $this->language->get('error_account_not_exist');
            }
        }

        $this->response->setOutput($this->load->view('extension/payment/apirone/apirone_mccp', $this->data));
    }

    /**
     * Gets existing or creates new plugin settings
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws ValidationFailedException
     * @throws UnauthorizedException
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws MethodNotAllowedException
     * @internal
     */
    protected function getSettings()
    {
        $plugin_data = $this->model_setting_setting->getSetting('apirone_mccp');

        if ($_settings_json = $plugin_data['apirone_mccp_settings']) {
            $_settings = Settings::fromJson($_settings_json);
        }
        if ($_settings && $_settings->account && $_settings->{'transfer-key'}) {
            $this->settings = $_settings;
            return;
        }
        $this->settings = Settings::init()->createAccount();

        $plugin_data['apirone_mccp_settings'] = $this->settings->toJsonString();
        $this->model_setting_setting->editSetting('apirone_mccp', $plugin_data);
    }

    /**
     * @param string $abbr token abbreviation
     * @return bool state of token visibility for given token from settings
     * @internal
     */
    protected function getNetworkTokenVisibility($abbr)
    {
        foreach ($this->settings->coins as $coin) {
            if ($coin->abbr === $abbr) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array Array of networks DTO with keys of networks abbreviations.
     * Each result array item is DTO with icon, name, tooltip, address and tokens array.
     * Each token array item is DTO with icon, visibility state and tooltip.
     * @internal
     */
    protected function getNetworksViewModel()
    {
        foreach ($this->settings->networks as $network) {
            $network_abbr = $network->network;
            $name = $network->name;
            $address = $network->address;
            $testnet = $network->isTestnet();
            $tokens = $network->tokens;
            $has_tokens = count($tokens) > 0;

            $networks_dto[$network_abbr] = $network_dto = new stdClass();

            $network_dto->icon = $network_abbr;
            $network_dto->name = $has_tokens ? sprintf($this->language->get('entry_network_name'), $name) : $name;
            $network_dto->address = $address;
            $network_dto->tooltip = sprintf($this->language->get(!$address ? 'currency_activate_tooltip' : 'currency_deactivate_tooltip'), $name);
            $network_dto->testnet = $testnet;
            $network_dto->error = $network->error;

            if ($testnet) {
                $network_dto->test_tooltip = $this->language->get('text_test_currency_tooltip');
            }
            if (!$has_tokens) {
                continue;
            }
            $tokens_dto = [];

            $tokens_dto[$network_abbr] = $token_dto = new stdClass();

            $token_dto->checkbox_id = 'state_'.$network_abbr;
            $token_dto->icon = $network_abbr;
            $token_dto->name = $alias = strtoupper($name);
            $token_dto->state = $this->getNetworkTokenVisibility($network_abbr);
            $token_dto->tooltip = sprintf($this->language->get('token_tooltip'), $alias);

            foreach ($tokens as $abbr => $token) {
                $tokens_dto[$abbr] = $token_dto = new stdClass();

                $token_dto->checkbox_id = 'state_'.$network_abbr.'_'.$token->token;
                $token_dto->icon = $token->token;
                $token_dto->name = $alias = strtoupper($token->alias);
                $token_dto->state = $this->getNetworkTokenVisibility($abbr);
                $token_dto->tooltip = sprintf($this->language->get('token_tooltip'), $alias);
            }
            $network_dto->tokens = $tokens_dto;
        }
        return $networks_dto;
    }

    protected function validate()
    {
    }

    protected function getBreadcrumbsAndActions()
    {
        $this->data['breadcrumbs'] = [];
        $this->data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], true)
        );
        $this->data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true)
        );
        $this->data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/apirone_mccp', 'token=' . $this->session->data['token'], true)
        );

        $this->data['action'] = $this->url->link('extension/payment/apirone_mccp', 'token=' . $this->session->data['token'], true);

        $this->data['cancel'] = $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true);
    }

    /**
     * Sets value from
     *   POST request (if POST data exists in request)
     *   or common plugin configuration (if $from_config is true)
     *   or specified plugin settings
     * to the template data specified by the key with the suffix specified.\
     * Sets an error if the value is empty but required.
     * @param string $key_suffix key suffix for data
     * @param bool $from_config a value should be obtained from the common plugin configuration
     * @param bool $required non empty value is required
     */
    protected function setValue($key_suffix, $from_config = false, $required = false)
    {
        $key = 'apirone_mccp_' . $key_suffix;

        $this->data[$key] = $value = trim($this->request->post[$key] ?? (
            $from_config
                ? $this->config->get($key)
                : $this->settings->{$key_suffix}
        ));
        if ($required && empty($value)) {
            $this->error[$key] = $this->language->get('error_' . $key);
        }
    }

    /**
     * Install plugin:
     * create account and settings,
     * store settings to DB
     * @api
     */
    public function install()
    {
        $this->load->model('extension/payment/apirone_mccp');
        $this->load->model('setting/setting');

        try {
            $_settings = Settings::init()->createAccount();
        } catch (\Throwable $ignore) {
            $this->load->language('extension/payment/apirone_mccp');
            $this->error['warning'] = $this->language->get('error_service_not_available');
            return;
        }
        $_settings
            ->version(PLUGIN_VERSION)
            ->secret(md5(time() . 'token=' . $this->session->data['token']))
            ->timeout(1800)
            ->processing_fee('percentage')
            ->factor(1.0)
            ->logo(true);

        $this->model_setting_setting->editSetting('apirone_mccp', array(
            // Apirone plugin specific settings
            'apirone_mccp_settings' => $_settings->toJsonString(),
            // OpenCart common plugin settings
            'apirone_mccp_geo_zone_id' => '0',
            'apirone_mccp_status' => '0',
            'apirone_mccp_sort_order' => '0',
        ));

        $this->model_extension_payment_apirone_mccp->install_invoices_table(
            InvoiceQuery::createInvoicesTable(DB_PREFIX));
    }

    /**
     * Uninstall plugin
     * @api
     */
    public function uninstall()
    {
        // do nothing
        // OpenCart automatically removes plugin settings
        // all invoices data in DB and logs remains for history
    }

    /**
     * Updates database structure if version changed
     * @internal
     */
    private function update()
    {
        $this->load->model('setting/setting');
        $plugin_data = $this->model_setting_setting->getSetting('apirone_mccp');
        if (!(
            isset($plugin_data)
            && is_array($plugin_data)
            && count($plugin_data)
        )) {
            // no any plugin data stored, nothing to update
            return;
        }
        $_settings_json = array_key_exists('apirone_mccp_settings', $plugin_data)
            ? $plugin_data['apirone_mccp_settings']
            : false;

        if (!$_settings_json) {
            $version = array_key_exists('apirone_mccp_version', $plugin_data)
                ? $plugin_data['apirone_mccp_version']
                : false;

            if (!($version || array_key_exists('apirone_mccp_account', $plugin_data))) {
                // no valid plugin data stored, nothing to update
                trigger_error('No valid plugin settings', E_USER_WARNING);
                return;
            }
        }
        else {
            $_settings = Settings::fromJson($_settings_json);
            $version = $_settings->version;
            if (!$version) {
                // no version in settings, nothing to update
                trigger_error('No any plugin version in settings, set to current', E_USER_WARNING);
                $version = $this->upd_version(PLUGIN_VERSION);
                return;
            }
        }
        if (!$version) {
            $version = $this->upd_1_0_1__1_1_0();
        }
        if ($version == '1.1.0') {
            $version = $this->upd_version('1.1.1');
        }
        if ($version == '1.1.1') {
            $version = $this->upd_version('1.1.2');
        }
        if ($version == '1.1.2') {
            $version = $this->upd_version('1.1.3');
        }
        if ($version == '1.1.3') {
            $version = $this->upd_version('1.1.4');
        }
        if ($version == '1.1.4') {
            $version = $this->upd_1_1_4__1_2_0();
        }
        if ($version == '1.2.0') {
            $version = $this->upd_version('1.2.1');
        }
        if ($version == '1.2.1') {
            $version = $this->upd_version('1.2.2');
        }
        if ($version == '1.2.2') {
            $version = $this->upd_version('1.2.3');
        }
        if ($version == '1.2.3') {
            $version = $this->upd_version('1.2.4');
        }
        if ($version == '1.2.4') {
            $version = $this->upd_version('1.2.5');
        }
        if ($version == '1.2.5') {
            $version = $this->upd_version('1.2.6');
        }
        if ($version == '1.2.6') {
            $version = $this->upd_1_2_6__2_0_0();
        }
    }

    /**
     * Updates in settings plugin version only
     * @param string $version new plugin version
     * @return string the same new version as in param
     * @internal
     */
    private function upd_version($version)
    {
        $plugin_data = $this->model_setting_setting->getSetting('apirone_mccp');

        if (substr($version, 0, 2) === '1.') {
            $plugin_data['apirone_mccp_version'] = $version;
        }
        else {
            $_settings = Settings::fromJson($plugin_data['apirone_mccp_settings']);
            $_settings->version($version);
            $plugin_data['apirone_mccp_settings'] = $_settings->toJsonString();
        }
        $this->model_setting_setting->editSetting('apirone_mccp', $plugin_data);

        return $version;
    }

    /**
     * Updates database version from 1 to 2
     * @internal
     */
    private function upd_1_2_6__2_0_0()
    {
        $plugin_data = $this->model_setting_setting->getSetting('apirone_mccp');

        $version = '2.0.0';

        $account_serialized = $plugin_data['apirone_mccp_account'];
        if ($account_serialized) {
            $account = unserialize($account_serialized);

            $account_id = $account->account;
            $transfer_key = $account->{'transfer-key'};
        }
        $_settings = $account_id && $transfer_key
            ? Settings::fromExistingAccount($account_id, $transfer_key)
            : Settings::init()->createAccount();

        $_settings
            ->version($version)
            ->secret($plugin_data['apirone_mccp_secret'])
            ->merchant($plugin_data['apirone_mccp_merchantname'])
            ->testcustomer($plugin_data['apirone_mccp_testcustomer'])
            ->timeout(intval($plugin_data['apirone_mccp_timeout']))
            ->processing_fee($plugin_data['apirone_mccp_processing_fee'])
            ->factor(floatval($plugin_data['apirone_mccp_factor']))
            ->logo(true)
            ->debug(!!$plugin_data['apirone_mccp_debug']);

        $coins = [];
        foreach ($_settings->networks as $network) {
            if (!$network->address) {
                continue;
            }
            // address stored for currency
            if (!count($tokens = $network->tokens)) {
                // currency with address has no tokens, add it as visible
                $coins[] = Coin::init($network);
                continue;
            }
            // currency has tokens, add all as visible by default
            foreach ($tokens as $token) {
                $coins[] = Coin::init($token);
            }
        }
        $_settings->coins($coins);

        $this->model_setting_setting->editSetting('apirone_mccp', array(
            // Apirone plugin specific settings
            'apirone_mccp_settings' => $_settings->toJsonString(),
            // OpenCart common plugin settings
            'apirone_mccp_geo_zone_id' => $plugin_data['apirone_mccp_geo_zone_id'],
            'apirone_mccp_status' => $plugin_data['apirone_mccp_status'],
            'apirone_mccp_sort_order' => $plugin_data['apirone_mccp_sort_order'],
        ));

        return $version;
    }

    /**
     * Updates database from very old version
     * @internal
     */
    private function upd_1_1_4__1_2_0()
    {
        $account = unserialize($this->config->get('apirone_mccp_account'));

        $endpoint = '/v2/accounts/' . $account->account;

        $params['transfer-key'] = $account->{'transfer-key'};
        $params['processing-fee-policy'] = 'percentage';

        $currencies = Service::account();

        foreach ($currencies as $currency) {
            $params['currency'] = $currency->abbr;

            Request::patch($endpoint, $params);
        }
        return $this->upd_version('1.2.0');
    }

    /**
     * Updates database from very very old version
     * @internal
     */
    private function upd_1_0_1__1_1_0()
    {
        $current = $this->model_setting_setting->getSetting('apirone_mccp');

        $plugin_data = $current;

        $pending = array_key_exists('apirone_mccp_pending_status_id', $current) ? $current['apirone_mccp_pending_status_id'] : 1;
        $completed = array_key_exists('apirone_mccp_completed_status_id', $current) ? $current['apirone_mccp_completed_status_id'] : 5;
        $voided = array_key_exists('apirone_mccp_voided_status_id', $current) ? $current['apirone_mccp_voided_status_id'] : 16;

        // Add new settings
        $plugin_data['apirone_mccp_version'] = '1.1.0';
        $plugin_data['apirone_mccp_invoice_created_status_id'] = $pending;
        $plugin_data['apirone_mccp_invoice_paid_status_id'] = $pending;
        $plugin_data['apirone_mccp_invoice_partpaid_status_id'] = $pending;
        $plugin_data['apirone_mccp_invoice_overpaid_status_id'] = $pending;
        $plugin_data['apirone_mccp_invoice_completed_status_id'] = $completed;
        $plugin_data['apirone_mccp_invoice_expired_status_id'] = $voided;

        // Remove old settings
        unset($plugin_data['apirone_mccp_status_id']);
        unset($plugin_data['apirone_mccp_pending_status_id']);
        unset($plugin_data['apirone_mccp_voided_status_id']);

        $this->model_setting_setting->editSetting('apirone_mccp', $plugin_data);

        return $plugin_data['apirone_mccp_version'];
    }
}

function pa($mixed, $title = false)
{
    if ($title) {
        echo $title . ':';
    }
    echo '<pre>';
    if (gettype($mixed) == 'boolean') {
        print_r($mixed ? 'true' : 'false');
    }
    else {
        print_r(!is_null($mixed) ? $mixed : 'NULL');
    }
    echo '</pre>';
}
