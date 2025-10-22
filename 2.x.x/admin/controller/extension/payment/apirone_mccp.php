<?php

require_once(DIR_SYSTEM . 'library/apirone/apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'vendor/autoload.php');

use \Apirone\SDK\Model\Settings;
use \Apirone\SDK\Model\Settings\Coin;

// the class name formation matters
class ControllerExtensionPaymentApironeMccp extends Controller
{
    private ?Settings $settings = null;
    private ?Proxy $model = null;
    private $data = [];
    private $error = [];

    public function __construct(Registry $registry)
    {
        parent::__construct($registry);

        $this->load->model(PATH_TO_RESOURCES);
        $this->model = $this->model_extension_payment_apirone_mccp;
        $this->model->initLogger();
    }

    /**
     * Loads main payment settings admin page in response to GET or POST request\
     * OpenCart required
     */
    public function index(): void
    {
        $this->load->language(PATH_TO_RESOURCES);

        $this->settings = $this->model->getSettings();
        if (!$this->settings) {
            $this->error['warning'] = $this->data['error'] = $this->language->get('error_service_not_available');
            $this->setCommonPageData();
            return;
        }
        $this->data['apirone_mccp_account'] = $this->settings->account;

        try {
            $networks = $this->settings->networks;
        } catch (\Throwable $ignore) {
            $this->error['warning'] = $this->data['error'] = $this->language->get('error_cant_get_currencies');
            $this->setCommonPageData();
            return;
        }
        $this->data['settings_loaded'] = true;

        $has_errors = false;
        $active_networks = false;
        $networks_update_need = false;
        $coins_update_need = false;

        if (!$this->user->hasPermission('modify', PATH_TO_RESOURCES)) {
            $this->data['error'] = $this->language->get('error_permission');
            $has_errors = true;
        }

        $saved_processing_fee = $this->settings->processing_fee;

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
                $plugin_data[SETTING_PREFIX . 'settings'] = $this->settings
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

                $plugin_data[SETTING_PREFIX . 'geo_zone_id'] = $this->request->post['apirone_mccp_geo_zone_id'];
                $plugin_data[SETTING_PREFIX . 'status'] = $this->request->post['apirone_mccp_status'];
                $plugin_data[SETTING_PREFIX . 'sort_order'] = $this->request->post['apirone_mccp_sort_order'];

                $this->load->model('setting/setting');
                $this->model_setting_setting->editSetting(SETTINGS_CODE, $plugin_data);

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
    protected function setCommonPageData(): void
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

        $this->response->setOutput($this->load->view(PATH_TO_VIEWS, $this->data));
    }

    /**
     * @return array Array of networks DTO with keys of networks abbreviations.
     * Each result array item is DTO with icon, name, tooltip, address and tokens array.
     * Each token array item is DTO with icon, visibility state and tooltip.
     */
    protected function getNetworksViewModel(): array
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

    protected function getBreadcrumbsAndActions(): void
    {
        $user_token_param = USER_TOKEN_KEY . '=' . $this->session->data[USER_TOKEN_KEY];

        $home_url = $this->url->link('common/dashboard', $user_token_param, true);
        $extensions_url = $this->url->link(EXTENSIONS_ROUTE, $user_token_param . '&type=payment', true);
        $apirone_mccp_url = $this->url->link(PATH_TO_RESOURCES, $user_token_param, true);

        $this->data['breadcrumbs'] = [];
        $this->data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $home_url
        );
        $this->data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $extensions_url
        );
        $this->data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $apirone_mccp_url
        );
        $this->data['action'] = $apirone_mccp_url;
        $this->data['cancel'] = $extensions_url;
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
    protected function setValue(string $key_suffix, bool $from_config = false, bool $required = false): void
    {
        $key = 'apirone_mccp_' . $key_suffix;

        $this->data[$key] = $value = trim($this->request->post[$key] ?? (
            $from_config
                ? $this->config->get(SETTINGS_CODE_PREFIX . $key)
                : $this->settings->{$key_suffix}
        ) ?? '');
        if ($required && empty($value)) {
            $this->error[$key] = $this->language->get('error_' . $key);
        }
    }

    /**
     * Install plugin
     * OpenCart required
     */
    public function install(): void
    {
        if (!$this->model->install()) {
            $this->load->language(PATH_TO_RESOURCES);
            $this->error['warning'] = $this->language->get('error_service_not_available');
        }
    }
}
