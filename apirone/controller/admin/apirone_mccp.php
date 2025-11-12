<?php

namespace Apirone\Payment\Controller\Admin;

require_once(((int) explode('.', VERSION, 2)[0] < 4 ? DIR_SYSTEM . 'library/apirone/' : DIR_EXTENSION . 'apirone/system/library/') . 'apirone_mccp.php');
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
        }
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

            $networks_dto[$network_abbr] = $network_dto = new \stdClass();

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

            $tokens_dto[$network_abbr] = $token_dto = new \stdClass();

            $token_dto->checkbox_id = 'state_'.$network_abbr;
            $token_dto->icon = $network_abbr;
            $token_dto->name = $alias = strtoupper($name);
            $token_dto->state = $this->settings->hasCoin($network_abbr);
            $token_dto->tooltip = sprintf($this->language->get('token_tooltip'), $alias);

            foreach ($tokens as $abbr => $token) {
                $tokens_dto[$abbr] = $token_dto = new \stdClass();

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
}
