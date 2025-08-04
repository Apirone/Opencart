<?php

use Apirone\API\Exceptions\RuntimeException;
use Apirone\API\Exceptions\ValidationFailedException;
use Apirone\API\Exceptions\UnauthorizedException;
use Apirone\API\Exceptions\ForbiddenException;
use Apirone\API\Exceptions\NotFoundException;
use Apirone\API\Exceptions\MethodNotAllowedException;

use Apirone\SDK\Invoice;
use Apirone\SDK\Service\InvoiceQuery;
use Apirone\SDK\Model\Settings;

require_once(DIR_SYSTEM . 'library/apirone_api/Apirone.php');

require_once(DIR_SYSTEM . 'library/apirone_vendor/autoload.php');

define('PLUGIN_VERSION', '2.0.0');
define('PLUGIN_LOG_FILE_NAME', 'apirone.log');

class ControllerExtensionPaymentApironeMccp extends Controller
{
    private $error = [];

    public function __construct($registry)
    {
        parent::__construct($registry);

        $this->initLogging();
    }

    /**
     * Initializes logging
     * @since 2.0.0
     * @author Valery Yu <vvy1976@gmail.com>
     * @internal
     */
    private function initLogging()
    {
        try {
            $openCartLogger = new \Log(PLUGIN_LOG_FILE_NAME);

            $logHandler = function($message) use ($openCartLogger) {
                $openCartLogger->write($message);
            };
            Invoice::logger($logHandler);
        }
        catch (Exception $e) {
            $this->log->write($e->getMessage());
        }
    }

    /**
     * Reads plugin log for display in log aria
     * @since 2.0.0
     * @author Valery Yu <vvy1976@gmail.com>
     * @internal
     */
    private function setDataLogField(&$data)
    {
        $log_full_path = DIR_LOGS . PLUGIN_LOG_FILE_NAME;
        try {
            $log_content = \file_get_contents($log_full_path);
        } catch (\Throwable $th) {
            $log_content = $th->getMessage();
        }
        $data['text_apirone_log'] = $log_content === false
            ? 'Can not read ' . $log_full_path
            : $log_content;
    }

    /**
     * Loads main payment settings admin page in response to GET or POST request
     * @api
     */
    public function index()
    {
        $this->update();

        $this->load->language('extension/payment/apirone_mccp');
        $this->load->model('extension/payment/apirone_mccp');

        $data = [];
        try {
            $_settings = $this->getSettings();
            $data['settings_loaded'] = true;
        } catch (\Throwable $ignore) {
            $this->error['warning'] = $data['error'] = $this->language->get('error_service_not_available');
            $this->setCommonPageData($data);
            return;
        }
        $account = $_settings->account;
        $saved_processing_fee = $_settings->getMeta('processing-fee');
        $currenciesMapByNetworks = $this->getCurrenciesMapByNetworks($_settings);

        $errors_count = 0;
        $active_currencies = false;
        $currencies_update_need = false;

        if (!$this->user->hasPermission('modify', 'extension/payment/apirone_mccp')) {
            $data['error'] = $this->language->get('error_permission');
            $errors_count++;
        }

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            $processing_fee = $this->request->post['apirone_mccp_processing_fee'];
            if ($processing_fee != $saved_processing_fee) {
                $_settings->addMeta('processing-fee', $processing_fee);
                $currencies_update_need = true;
            }
            foreach ($currenciesMapByNetworks as $network => $network_currencies) {
                $address = $this->request->post['address'][$network];

                foreach ($network_currencies as $abbr => $currency) {
                    $visible_from_post = $this->request->post['visible'];
                    $state = !empty($visible_from_post) && array_key_exists($abbr, $visible_from_post) && $visible_from_post[$abbr];

                    if ($address != $currency->address) {
                        $currency->address = $address;
                        $currencies_update_need = true;
                    }
                    if ($state != $this->getNetworkTokenVisibility($_settings, $abbr)) {
                        $this->setNetworkTokenVisibility($_settings, $abbr, $state);
                        $currencies_update_need = true;
                    }
                    $currency->policy = $processing_fee;
                }
            }
            if ($currencies_update_need) {
                $_settings->saveCurrencies();

                foreach ($_settings->currencies as $currency) {
                    $active_currencies = $active_currencies && !!$currency->address;

                    if ($currency->hasError()) {
                        if (!$data['error']) {
                            $data['error'] = sprintf($this->language->get('error_currency_save'), $currency->name, $currency->error);
                        }
                        $errors_count++;
                    }
                }
                $currenciesMapByNetworks = $this->getCurrenciesMapByNetworks($_settings);
            }
        }
        // Set values into template vars
        $this->setValue($_settings, $data, 'merchantname');
        $this->setValue($_settings, $data, 'testcustomer');
        $this->setValue($_settings, $data, 'timeout', false, true);
        $this->setValue($_settings, $data, 'processing_fee');
        $this->setValue($_settings, $data, 'factor', false, true);
        $this->setValue($_settings, $data, 'debug');

        $this->setValue($_settings, $data, 'status', true);
        $this->setValue($_settings, $data, 'geo_zone_id', true);
        $this->setValue($_settings, $data, 'sort_order', true);

        $data['apirone_mccp_account'] = $account;

        if (!$active_currencies || $data['apirone_mccp_timeout'] <= 0 || $data['apirone_mccp_factor'] <= 0 || count($currenciesMapByNetworks) == 0) {
            $errors_count++;
        }
        $errors_count += count($this->error);

        // Save settings if post & no errors
        $this->load->model('setting/setting');

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            if ($errors_count == 0) {
                $plugin_data['apirone_mccp_settings'] = $_settings->toJsonString();

                $plugin_data['apirone_mccp_geo_zone_id'] = $this->request->post['apirone_mccp_geo_zone_id'];
                $plugin_data['apirone_mccp_status'] = $this->request->post['apirone_mccp_status'];
                $plugin_data['apirone_mccp_sort_order'] = $this->request->post['apirone_mccp_sort_order'];

                $this->model_setting_setting->editSetting('apirone_mccp', $plugin_data);

                $data['success'] = $this->language->get('text_success');
            }
            else {
                $data['error'] = $this->language->get('error_warning');
                // No addresses
                if (!$active_currencies) {
                    $data['error'] = $this->language->get('error_empty_currencies');
                }
                // Payment timeout
                if($data['apirone_mccp_timeout'] <= 0) {
                    $this->error['apirone_mccp_timeout'] = $this->language->get('error_apirone_mccp_timeout_positive');
                }
                if($data['apirone_mccp_timeout'] === '') {
                    $this->error['apirone_mccp_timeout'] = $this->language->get('error_apirone_mccp_timeout');
                }
                if($data['apirone_mccp_factor'] <= 0 || empty($data['apirone_mccp_factor'])) {
                    $this->error['apirone_mccp_factor'] = $this->language->get('error_apirone_mccp_factor');
                }
            }
        }

        if (count($currenciesMapByNetworks) > 0) {
            $data['networks'] = $this->getNetworksDTO($_settings, $currenciesMapByNetworks);
        }
        else {
            $data['error'] = $this->language->get('error_cant_get_currencies');
        }

        $this->setCommonPageData($data);
    }

    /**
     * Set common page data
     * @param array &$data reference to array of page data
     * @internal
     */
    protected function setCommonPageData(&$data) {
        $this->document->setTitle($this->language->get('heading_title'));

        $data = array_merge($data, $this->load->language('apirone_mccp'));

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['order_statuses'][] = ['order_status_id' => 0, 'name' => $this->language->get('text_missing')];

        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $this->getBreadcrumbsAndActions($data);

        $data['apirone_mccp_version'] = PLUGIN_VERSION;
        $data['oc_version'] = VERSION;
        $data['phpversion'] = phpversion();
        $data['errors'] = $this->error;
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        if (empty($data['apirone_mccp_account'])) {
            $data['apirone_mccp_account'] = $this->language->get('text_account_not_exist');
            if (!array_key_exists('error', $data)) {
                $data['error'] = $this->language->get('error_account_not_exist');
            }
        }

        $this->setDataLogField($data);

        $this->response->setOutput($this->load->view('extension/payment/apirone_mccp', $data));
    }

    /**
     * Gets existing or creates new plugin settings
     * @return Settings
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
        // TODO: why it does not works?
        // $_settings_json = $this->config->get('apirone_mccp_settings');

        $_settings_json = $this->model_setting_setting->getSetting('apirone_mccp')['apirone_mccp_settings'];

        $_settings = $_settings_json ? Settings::fromJson($_settings_json) : false;

        if (!($_settings && $_settings->account && $_settings->{'transfer-key'})) {
            $_settings = Settings::init()->createAccount();

            $plugin_data['apirone_mccp_settings'] = $_settings->toJsonString();
            $this->model_setting_setting->editSetting('apirone_mccp', $plugin_data);
        }
        return $_settings;
    }

    /**
     * @param Settings &$_settings reference to plugin settings object
     * @return array Array of networks with keys of networks abbreviations.
     * Each result array item is array of currencies with keys of abbreviations.
     * @internal
     */
    protected function getCurrenciesMapByNetworks(&$_settings)
    {
        $map = [];
        foreach ($_settings->currencies as $currency) {
            $abbr = $currency->abbr;

            $currency_obj = new stdClass();
            $currency_obj->abbr = $abbr;
            $currency_obj->network = $currency->network;
            $currency_obj->name = $currency->name;
            $currency_obj->alias = $currency->alias;
            $currency_obj->address = $currency->address;
            $currency_obj->testnet = $currency->isTestnet();
            $currency_obj->error = $currency->error;

            $map[$currency->network][$abbr] = $currency_obj;
        }
        return $map;
    }

    /**
     * @param Settings &$_settings reference to plugin settings object
     * @param string $abbr currency abbreviation
     * @return bool state of token visibility for given currency abbreviation from settings
     * @internal
     */
    protected function getNetworkTokenVisibility(&$_settings, $abbr)
    {
        return !!$_settings->getMeta($abbr);
    }

    /**
     * Sets the state of token visibility for currency with the given abbreviation to settings
     * @param Settings &$_settings reference to plugin settings object
     * @param string $abbr currency abbreviation
     * @param bool $value new state of currency token visibility
     * @internal
     */
    protected function setNetworkTokenVisibility(&$_settings, $abbr, $value)
    {
        if ($value) {
            $_settings->addMeta($abbr, 'on');
        }
        else {
            $_settings->deleteMeta($abbr);
        }
    }

    /**
     * Sets the state of token visibility defaults to settings
     * @param Settings &$_settings reference to plugin settings object
     * @internal
     */
    protected function setDefaultNetworksTokensVisibility(&$_settings)
    {
        foreach ($this->getCurrenciesMapByNetworks($_settings) as $network_currencies) {
            if (count($network_currencies) > 1) {
                foreach ($network_currencies as $currency) {
                    $this->setNetworkTokenVisibility($_settings, $currency->abbr, true);
                }
            }
        }
    }

    /**
     * @param Settings &$_settings reference to plugin settings object
     * @param array &$networks reference to networks map from `{@link getCurrenciesMapByNetworks()}`
     * @return array Array of networks DTO with keys of networks abbreviations.
     * Each result array item is DTO with icon, name, tooltip, address and tokens array.
     * Each token array item is DTO with icon, visibility state and tooltip.
     * @internal
     */
    protected function getNetworksDTO(&$_settings, &$networks)
    {
        $networks_dto = [];
        foreach ($networks as $network => $network_currencies) {
            $network_currencies_count = count($network_currencies);
            if (!$network_currencies_count) {
                continue;
            }
            $first_currency = $network_currencies[array_key_first($network_currencies)];
            $has_tokens = $network_currencies_count > 1;

            $abbr = $first_currency->abbr;
            $name = $first_currency->name;
            $address = $first_currency->address;
            $testnet = $first_currency->testnet;

            $networks_dto[$network] = $network_dto = new stdClass();

            $network_dto->icon = str_replace('@', '_', $abbr);
            $network_dto->name = $has_tokens ? sprintf($this->language->get('entry_network_name'), $name) : $name;
            $network_dto->address = $address;
            $network_dto->tooltip = sprintf($this->language->get(!$address ? 'currency_activate_tooltip' : 'currency_deactivate_tooltip'), $name);
            $network_dto->testnet = $testnet;
            $network_dto->error = $first_currency->error;

            if ($testnet) {
                $network_dto->test_tooltip = $this->language->get('text_test_currency_tooltip');
            }
            if (!$has_tokens) {
                continue;
            }
            $tokens = [];
            foreach ($network_currencies as $abbr => $currency) {
                $tokens[$abbr] = $token_dto = new stdClass();

                $token_dto->icon = str_replace('@', '_', $abbr);
                $token_dto->name = $alias = strtoupper($currency->alias);
                $token_dto->state = $this->getNetworkTokenVisibility($_settings, $abbr);
                $token_dto->tooltip = sprintf($this->language->get('token_tooltip'), $alias);

                if ($currency->testnet) {
                    $token_dto->test_tooltip = $this->language->get('text_test_currency_tooltip');
                }
            }
            $network_dto->tokens = $tokens;
        }
        // trigger_error('networks_dto:'.json_encode($networks_dto), E_USER_NOTICE);
        return $networks_dto;
    }

    protected function validate()
    {
    }

    protected function getBreadcrumbsAndActions(&$data)
    {
        $data['breadcrumbs'] = [];
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/apirone_mccp', 'token=' . $this->session->data['token'], true)
        );

        $data['action'] = $this->url->link('extension/payment/apirone_mccp', 'token=' . $this->session->data['token'], true);

        $data['cancel'] = $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true);
    }

    protected function setValue(&$_settings, &$data, $key_suffix, $from_config = false, $required = false)
    {
        $key = 'apirone_mccp_' . $key_suffix;

        $data[$key] = $value = $this->request->post[$key] ?? (
            $from_config
                ? $this->config->get($key)
                : $_settings->getMeta($key_suffix)
        );
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
            ->addMeta('version', PLUGIN_VERSION)
            ->addMeta('secret', md5(time() . 'token=' . $this->session->data['token']))
            ->addMeta('merchant', '')
            ->addMeta('testcustomer', '')
            ->addMeta('timeout', 1800)
            ->addMeta('processing-fee', 'percentage')
            ->addMeta('factor', 1)
            ->addMeta('logo', true)
            ->addMeta('debug', false);

        $this->setDefaultNetworksTokensVisibility($_settings);

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
            $version = $_settings->getMeta('version');
            if (!$version) {
                // no version in settings meta, nothing to update
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
        // TODO: remove after SDK fix in root of Settings object
        // title
        // merchant
        // merchant-url
        // timeout
        // factor
        // backlink
        // qr-only
        // logo
        // debug
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
            $_settings->addMeta('version', $version);
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
            ->addMeta('version', $version)
            ->addMeta('secret', $plugin_data['apirone_mccp_secret'])
            ->addMeta('merchant', $plugin_data['apirone_mccp_merchantname'])
            ->addMeta('testcustomer', $plugin_data['apirone_mccp_testcustomer'])
            ->addMeta('timeout', intval($plugin_data['apirone_mccp_timeout']))
            ->addMeta('processing-fee', $plugin_data['apirone_mccp_processing_fee'])
            ->addMeta('factor', intval($plugin_data['apirone_mccp_factor']))
            ->addMeta('logo', true)
            ->addMeta('debug', !!$plugin_data['apirone_mccp_debug']);

        $this->setDefaultNetworksTokensVisibility($_settings);

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
        $items = \ApironeApi\Apirone::currencyList();
        $endpoint = '/v2/accounts/' . $account->account;

        foreach ($items as $item) {
            $params['transfer-key'] = $account->{'transfer-key'};
            $params['currency'] = $item->abbr;
            $params['processing-fee-policy'] = 'percentage';

            \ApironeApi\Request::execute('patch', $endpoint, $params, true);
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

        $data = $current;

        $pending = array_key_exists('apirone_mccp_pending_status_id', $current) ? $current['apirone_mccp_pending_status_id'] : 1;
        $completed = array_key_exists('apirone_mccp_completed_status_id', $current) ? $current['apirone_mccp_completed_status_id'] : 5;
        $voided = array_key_exists('apirone_mccp_voided_status_id', $current) ? $current['apirone_mccp_voided_status_id'] : 16;

        // Add new settings
        $data['apirone_mccp_version'] = '1.1.0';
        $data['apirone_mccp_invoice_created_status_id'] = $pending;
        $data['apirone_mccp_invoice_paid_status_id'] = $pending;
        $data['apirone_mccp_invoice_partpaid_status_id'] = $pending;
        $data['apirone_mccp_invoice_overpaid_status_id'] = $pending;
        $data['apirone_mccp_invoice_completed_status_id'] = $completed;
        $data['apirone_mccp_invoice_expired_status_id'] = $voided;

        // Remove old settings
        unset($data['apirone_mccp_status_id']);
        unset($data['apirone_mccp_pending_status_id']);
        unset($data['apirone_mccp_voided_status_id']);

        $this->model_setting_setting->editSetting('apirone_mccp', $data);

        return $data['apirone_mccp_version'];
    }
}
