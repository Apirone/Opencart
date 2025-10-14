<?php

use Apirone\API\Exceptions\RuntimeException;
use Apirone\API\Exceptions\ValidationFailedException;
use Apirone\API\Exceptions\UnauthorizedException;
use Apirone\API\Exceptions\ForbiddenException;
use Apirone\API\Exceptions\NotFoundException;
use Apirone\API\Exceptions\MethodNotAllowedException;
use Apirone\API\Log\LoggerWrapper;

use Apirone\SDK\Invoice;
use Apirone\SDK\Service\InvoiceQuery;
use Apirone\SDK\Model\Settings;
use Apirone\SDK\Model\Settings\Coin;

require_once(DIR_SYSTEM . 'library/apirone/vendor/autoload.php');

define('PLUGIN_VERSION', '2.0.0');
define('PLUGIN_LOG_FILE_NAME', 'apirone.log');
define('DEFAULT_STATUS_IDS', [
    'created' => 1,
    'partpaid' => 1,
    'paid' => 5,
    'overpaid' => 5,
    'completed' => 5,
    'expired' => 16,
]);

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
     * Loads main payment settings admin page in response to GET or POST request\
     * OpenCart required
     */
    public function index()
    {
        $this->load->language('extension/payment/apirone_mccp');
        try {
            $this->getSettings();
            $this->data['settings_loaded'] = true;
        }
        catch (Exception $e) {
            LoggerWrapper::error($e->getMessage());
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
                $coins[$abbr] = Coin::init($network);
                $network->policy($processing_fee);

                $tokens = $network->tokens;
                if (!count($tokens)) {
                    continue;
                }
                $state = !empty($visible_from_post) && array_key_exists($abbr, $visible_from_post) && $visible_from_post[$abbr];

                if ($state != $this->settings->hasCoin($abbr)) {
                    $coins_update_need = true;
                }
                foreach ($tokens as $token) {
                    $abbr = $token->abbr;
                    $state = !empty($visible_from_post) && array_key_exists($abbr, $visible_from_post) && $visible_from_post[$abbr];

                    if ($state != $this->settings->hasCoin($abbr)) {
                        $coins_update_need = true;
                    }
                    $coins[$abbr] = Coin::init($token);
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
        $this->setValue('with_fee');
        $this->setValue('factor', false, true);
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

                $_status_ids = [];
                foreach (array_keys(DEFAULT_STATUS_IDS) as $apirone_status) {
                    $_status_ids[$apirone_status] = intval($this->request->post['apirone_mccp_invoice_'.$apirone_status.'_status_id']);
                }
                $plugin_data['payment_apirone_mccp_settings'] = $this->settings
                    ->merchant(trim($this->request->post['apirone_mccp_merchant']))
                    ->testcustomer(trim($this->request->post['apirone_mccp_testcustomer']))
                    ->timeout(intval($this->request->post['apirone_mccp_timeout']))
                    ->processing_fee($this->request->post['apirone_mccp_processing_fee'])
                    ->with_fee(!!$this->request->post['apirone_mccp_with_fee'])
                    ->factor(floatval($this->request->post['apirone_mccp_factor']))
                    ->logo(!!$this->request->post['apirone_mccp_logo'])
                    ->debug(!!$this->request->post['apirone_mccp_debug'])
                    ->status_ids($_status_ids)
                    ->toJsonString();

                $plugin_data['payment_apirone_mccp_geo_zone_id'] = $this->request->post['apirone_mccp_geo_zone_id'];
                $plugin_data['payment_apirone_mccp_status'] = $this->request->post['apirone_mccp_status'];
                $plugin_data['payment_apirone_mccp_sort_order'] = $this->request->post['apirone_mccp_sort_order'];

                $this->model_setting_setting->editSetting('payment_apirone_mccp', $plugin_data);

                $this->data['success'] = $this->language->get('text_success');
            }
        }
        if (!count($networks)) {
            $this->data['error'] = $this->language->get('error_cant_get_currencies');
            $this->setCommonPageData();
            return;
        }
        $this->data['networks'] = $this->getNetworksViewModel();

        $this->data['apirone_mccp_invoice_status_ids'] = (array)$this->settings->status_ids;

        $apirone_status_labels = [];
        foreach (array_keys(DEFAULT_STATUS_IDS) as $apirone_status) {
            $apirone_status_labels[$apirone_status] = $this->language->get('entry_invoice_'.$apirone_status);
        }
        $this->data['apirone_status_labels'] = $apirone_status_labels;

        $this->load->model('localisation/order_status');
        $this->data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->data['order_statuses'][] = ['order_status_id' => 0, 'name' => $this->language->get('text_missing')];

        $this->setCommonPageData();
    }

    /**
     * Set common page data
     */
    protected function setCommonPageData()
    {
        $this->document->setTitle($this->language->get('heading_title'));

        $this->data = array_merge($this->data, $this->load->language('apirone_mccp'));

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
     */
    protected function getSettings()
    {
        $this->load->model('setting/setting');
        $plugin_data = $this->model_setting_setting->getSetting('payment_apirone_mccp');

        $_settings = false;
        if (key_exists('payment_apirone_mccp_settings', $plugin_data) && ($_settings_json = $plugin_data['payment_apirone_mccp_settings'])) {
            $_settings = Settings::fromJson($_settings_json);
        }
        if ($_settings && $_settings->account && $_settings->{'transfer-key'}) {
            $this->settings = $_settings;
            return;
        }
        $this->settings = Settings::init()->createAccount();

        $plugin_data['payment_apirone_mccp_settings'] = $this->settings->toJsonString();
        $this->model_setting_setting->editSetting('payment_apirone_mccp', $plugin_data);
    }

    /**
     * @return array Array of networks DTO with keys of networks abbreviations.
     * Each result array item is DTO with icon, name, tooltip, address and tokens array.
     * Each token array item is DTO with icon, visibility state and tooltip.
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
            $token_dto->state = $this->settings->hasCoin($network_abbr);
            $token_dto->tooltip = sprintf($this->language->get('token_tooltip'), $alias);

            foreach ($tokens as $abbr => $token) {
                $tokens_dto[$abbr] = $token_dto = new stdClass();

                $token_dto->checkbox_id = 'state_'.$network_abbr.'_'.$token->token;
                $token_dto->icon = $token->token;
                $token_dto->name = $alias = strtoupper($token->alias);
                $token_dto->state = $this->settings->hasCoin($abbr);
                $token_dto->tooltip = sprintf($this->language->get('token_tooltip'), $alias);
            }
            $network_dto->tokens = $tokens_dto;
        }
        return $networks_dto;
    }

    /**
     * Validates plugin data\
     * OpenCart required
     */
    protected function validate()
    {
        // do nothing
    }

    protected function getBreadcrumbsAndActions()
    {
        $this->data['breadcrumbs'] = [];
        $this->data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );
        $this->data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        );
        $this->data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/apirone_mccp', 'user_token=' . $this->session->data['user_token'], true)
        );

        $this->data['action'] = $this->url->link('extension/payment/apirone_mccp', 'user_token=' . $this->session->data['user_token'], true);

        $this->data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
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
                ? $this->config->get('payment_'.$key)
                : $this->settings->{$key_suffix}
        ) ?? '');
        if ($required && empty($value)) {
            $this->error[$key] = $this->language->get('error_' . $key);
        }
    }

    /**
     * Install plugin:
     * create account and settings,
     * store settings to DB\
     * OpenCart required
     */
    public function install()
    {
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
            ->logo(true)
            ->status_ids(DEFAULT_STATUS_IDS);

        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('payment_apirone_mccp', array(
            // Apirone plugin specific settings
            'payment_apirone_mccp_settings' => $_settings->toJsonString(),
            // OpenCart common plugin settings
            'payment_apirone_mccp_geo_zone_id' => '0',
            'payment_apirone_mccp_status' => '0',
            'payment_apirone_mccp_sort_order' => '0',
        ));

        $this->load->model('extension/payment/apirone_mccp');
        $this->model_extension_payment_apirone_mccp->install_invoices_table(
            InvoiceQuery::createInvoicesTable(DB_PREFIX));
    }

    /**
     * Uninstall plugin\
     * OpenCart required
     */
    public function uninstall()
    {
        // do nothing
        // OpenCart automatically removes plugin settings
        // all invoices data in DB and logs remains for history
    }
}
