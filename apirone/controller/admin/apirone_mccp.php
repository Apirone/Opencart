<?php

namespace Apirone\Payment\Controller\Admin;

require_once((version_compare(VERSION, 4, '<')
    ? DIR_SYSTEM . 'library/apirone/'
    : DIR_EXTENSION . 'apirone/system/library/'
) . 'apirone_mccp.php');

require_once(PATH_TO_LIBRARY . 'controller/apirone_mccp.php');

class ControllerExtensionPaymentApironeMccpAdmin extends \Apirone\Payment\Controller\ControllerExtensionPaymentApironeMccpCommon
{
    protected array $data = [];
    protected array $error = [];

    /**
     * Install plugin\
     * OpenCart required
     */
    public function install(): void
    {
        if (!$this->model->install()) {
            $this->load->language(PATH_TO_RESOURCES);
            $this->error['warning'] = $this->language->get('error_service_not_available');
            return;
        }
        $this->setOrderHistoryEvent();
    }

    /**
     * Uninstall plugin\
     * OpenCart required
     */
    public function uninstall(): void
    {
        $this->model->uninstall();
        $this->clearOrderHistoryEvent();
    }

    /**
     * @return array Array of networks DTO with keys of networks abbreviations.
     * Each result array item is DTO with icon, name, tooltip, address and tokens array.
     * Each token array item is DTO with icon, visibility state and tooltip.
     */
    protected function getNetworksViewModel(): array
    {
        $coins = $this->settings->coins;

        foreach ($this->settings->networks as $network) {
            $network_abbr = $network->network;
            $name = $network->name;
            $address = $network->address;
            $testnet = $network->isTestnet();
            $tokens = $network->tokens;
            $has_tokens = count($tokens) > 0;

            $networks_dto[$network_abbr] = $network_dto = new \stdClass();

            $network_dto->icon = $network_abbr;
            $network_dto->name = $has_tokens ? sprintf($this->language->get('entry_network_name'), $name) : $name;
            $network_dto->address = $address;
            $network_dto->tooltip = sprintf($this->language->get($address ? 'currency_deactivate_tooltip' : 'currency_activate_tooltip'), $name);
            $network_dto->testnet = $testnet;
            $network_dto->error = $network->error;

            if ($testnet) {
                $network_dto->test_tooltip = $this->language->get('text_test_currency_tooltip');
            }
            if (!$has_tokens) {
                continue;
            }
            $tokens_dto = [];

            $tokens_dto[$network_abbr] = $token_dto = new \stdClass();

            $token_dto->checkbox_id = 'state_'.$network_abbr;
            $token_dto->icon = $network_abbr;
            $token_dto->name = $alias = strtoupper($name);
            $token_dto->state = $address && in_array($network_abbr, $coins);
            $token_dto->tooltip = sprintf($this->language->get('token_tooltip'), $alias);

            foreach ($tokens as $abbr => $token) {
                $tokens_dto[$abbr] = $token_dto = new \stdClass();

                $token_dto->checkbox_id = 'state_'.$network_abbr.'_'.$token->token;
                $token_dto->icon = $token->token;
                $token_dto->name = $alias = strtoupper($token->alias);
                $token_dto->state = $address && in_array($abbr, $coins);
                $token_dto->tooltip = sprintf($this->language->get('token_tooltip'), $alias);
            }
            $network_dto->tokens = $tokens_dto;
        }
        return $networks_dto;
    }

    /**
     * @param string $key
     * @return mixed value from POST request data with the given key if it exists or null
     */
    protected function getPostValue(string $key)
    {
        $post_data = $this->request->post;

        return empty($post_data) || !array_key_exists($key, $post_data)
            ? null
            : $post_data[$key];
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

        $this->data[$key] = $value = trim($this->getPostValue($key) ?? (
            $from_config
                ? $this->config->get(SETTINGS_CODE_PREFIX . $key)
                : $this->settings->{$key_suffix}
        ) ?? '');

        if ($required && empty($value)) {
            $this->error[$key] = $this->language->get('error_' . $key);
        }
    }

    /**
     * Checks and sets values into template vars
     * @return ?array networks errors
     */
    protected function checkAndSetValues(): ?array
    {
        $this->data['settings_loaded'] = true;

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

        $saved_processing_fee = $this->settings->processing_fee;
        $networks = $this->settings->networks;
     
        if ($this->request->server['REQUEST_METHOD'] != 'POST') {
            return null;
        }
        $processing_fee = $this->getPostValue('apirone_mccp_processing_fee');
        if ($processing_fee != $saved_processing_fee) {
            $this->settings->processing_fee($processing_fee);
        }
        $coins = [];

        $address_from_post = $this->getPostValue('address');
        $visible_from_post = $this->getPostValue('visible');

        foreach ($networks as $network) {
            $abbr = $network->abbr;

            $address = !empty($address_from_post) && array_key_exists($abbr, $address_from_post)
                ? trim($address_from_post[$abbr])
                : null;
            $network->address($address);
            if (!$address) {
                continue;
            }
            $network->policy($processing_fee);

            if (!count($network->tokens)) {
                $coins[] = $abbr;
                continue;
            }
            foreach (array_merge([$abbr], array_keys($network->tokens)) as $abbr) {
                if (!empty($visible_from_post) && array_key_exists($abbr, $visible_from_post) && $visible_from_post[$abbr]) {
                    $coins[] = $abbr;
                }
            }
        }
        $this->settings->coins($coins);

        return $this->settings->saveNetworks();
    }

    /**
     * Saves settings from post data.
     * Fields that not passed validation are not changed, but an error is filled for them.
     * The valid settings values are saved anyway.
     */
    protected function saveSettingsFromPostData(): void
    {
        $_status_ids = [];
        foreach (array_keys(DEFAULT_STATUS_IDS) as $apirone_status) {
            $_status_ids[$apirone_status] = intval($this->getPostValue('apirone_mccp_invoice_'.$apirone_status.'_status_id'));
        }
        $this->settings
            ->merchant(trim($this->getPostValue('apirone_mccp_merchant')))
            ->testcustomer(trim($this->getPostValue('apirone_mccp_testcustomer')))
            ->processing_fee($this->getPostValue('apirone_mccp_processing_fee'))
            ->with_fee(!!$this->getPostValue('apirone_mccp_with_fee'))
            ->logo(!!$this->getPostValue('apirone_mccp_logo'))
            ->debug(!!$this->getPostValue('apirone_mccp_debug'))
            ->status_ids($_status_ids);

        if (!array_key_exists('apirone_mccp_timeout', $this->error)) {
            if ($this->data['apirone_mccp_timeout'] >= 0) {
                $this->settings->timeout(intval($this->getPostValue('apirone_mccp_timeout')));
            }
            else {
                $this->error['apirone_mccp_timeout'] = $this->language->get('error_apirone_mccp_timeout_positive');
            }
        }
        if (!array_key_exists('apirone_mccp_factor', $this->error)) {
            if ($this->data['apirone_mccp_factor'] > 0) {
                $this->settings->factor(floatval($this->getPostValue('apirone_mccp_factor')));
            }
            else {
                $this->error['apirone_mccp_factor'] = $this->language->get('error_apirone_mccp_factor');
            }
        }
        $plugin_data[SETTING_PREFIX . 'settings'] = $this->settings->toJsonString();
        $plugin_data[SETTING_PREFIX . 'geo_zone_id'] = $this->getPostValue('apirone_mccp_geo_zone_id');
        $plugin_data[SETTING_PREFIX . 'status'] = $this->getPostValue('apirone_mccp_status');
        $plugin_data[SETTING_PREFIX . 'sort_order'] = $this->getPostValue('apirone_mccp_sort_order');

        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting(SETTINGS_CODE, $plugin_data);
    }

    /**
     * Set plugin page data
     */
    protected function setPageData(): void
    {
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

    protected function setErrorPageData($error_message_key): void
    {
        $this->error['warning'] = $this->data['error'] = $this->language->get($error_message_key);
        $this->setCommonPageData();
    }

	private function loadEventModel() {
        $prefix = (OC_MAJOR_VERSION < 3 ? 'extension' : 'setting');
		$this->load->model($prefix . '/event');
        return $this->{'model_' . $prefix . '_event'};
    }

	private function setOrderHistoryEvent(): void
    {
        $model = $this->loadEventModel();

        foreach(EVENTS_DEFS as $event_def) {
            $code = $event_def['code'];
            $trigger = $event_def['trigger_prefix'] . (OC_MAJOR_VERSION < 4 ? 'getOrderHistories' : 'getHistories') . '/after';
            $action = PATH_TO_RESOURCES . (OC_MAJOR_VERSION < 4 ? '/' : '.') . 'afterGetHistories';

            if (OC_MAJOR_VERSION < 3 && $model->getEvent($code, $trigger, $action)
                || OC_MAJOR_VERSION >= 3 && $model->getEventByCode($code)
            ) {
                continue;
            }
            if (OC_MAJOR_VERSION < 4) {
                $model->addEvent($code, $trigger, $action);
                continue;
            }
            $model->addEvent(array(
                'code' => $code,
                'description' => '',
                'trigger' => $trigger,
                'action' => $action,
                'status' => true,
                'sort_order' => 1,
            ));
    	}
	}

    private function clearOrderHistoryEvent(): void
    {
		$model = $this->loadEventModel();

        foreach(EVENTS_DEFS as $event_def) {
            $code = $event_def['code'];

            if (OC_MAJOR_VERSION < 3) {
                $model->deleteEvent($code);
                continue;
            }
            $model->deleteEventByCode($code);
        }
	}
}
