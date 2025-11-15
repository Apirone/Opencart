<?php

namespace Opencart\Admin\Controller\Extension\Apirone\Payment;

require_once(DIR_EXTENSION . 'apirone/system/library/apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'controller/admin/apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'vendor/autoload.php');

use Apirone\Payment\Controller\Admin\ControllerExtensionPaymentApironeMccpAdmin;

// class must be named as plugin
class ApironeMccp extends ControllerExtensionPaymentApironeMccpAdmin
{
    /**
     * Loads main payment settings admin page in response to GET or POST request\
     * OpenCart required
     */
    public function index(): void
    {
        $this->load->language(PATH_TO_RESOURCES);

        $this->settings = $this->model->getSettings();
        if (!$this->settings) {
            $this->errorResponse('error_service_not_available');
            return;
        }
        $this->data['apirone_mccp_account'] = $this->settings->account;

        try {
            $networks = $this->settings->networks;
        } catch (\Throwable $ignore) {
            $this->errorResponse('error_cant_get_currencies');
            return;
        }
        if (!count($networks)) {
            $this->errorResponse('error_cant_get_currencies');
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

        $post_data = null;
        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            $post_data = $this->request->post;

            $processing_fee = $post_data['apirone_mccp_processing_fee'];
            if ($processing_fee != $saved_processing_fee) {
                $this->settings->processing_fee($processing_fee);
                $networks_update_need = true;
            }
            $coins = [];

            $visible_from_post = $post_data['visible'];
            $address_from_post = $post_data['address'];

            foreach ($networks as $network) {
                $abbr = $network->abbr;
                $address = $address_from_post[$abbr];
                if ($address != $network->address) {
                    $network->address($address);
                    $networks_update_need = true;
                    $coins_update_need = true;
                }
                if (!$address) {
                    continue;
                }
                // TODO: is currency network in tokens array?
                $coins[] = $abbr;
                $network->policy($processing_fee);

                $tokens = $network->tokens;
                if (!count($tokens)) {
                    // TODO: is currency network in tokens array?
                    // $coins[] = $abbr;
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
                    $coins[] = $abbr;
                    $token->policy($processing_fee);
                }
            }
            if ($coins_update_need) {
                $this->settings->coins($coins);
            }
            if ($networks_update_need) {
                $this->settings->saveCurrencies();

                foreach ($networks as $network) {
                    $has_errors = $has_errors || $network->hasError();
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
            $post_response = [];
            if ($has_errors) {
                $post_response['error']['warning'] = $this->language->get('error_warning');
                // No addresses
                if (!$active_networks) {
                    $post_response['error']['warning'] = $this->language->get('error_empty_currencies');
                }
                // Wrong addresses
                foreach ($networks as $network) {
                    if ($network->hasError()) {
                        $post_response['error']['address_' . $network->abbr] = sprintf($this->language->get('error_currency_save'), $network->name, $network->error);
                    }
                }
                // Payment timeout
                $timeout = $this->data['apirone_mccp_timeout'];
                if ($timeout === 0 || $timeout < 0) {
                    $post_response['error']['timeout'] = $this->language->get('error_apirone_mccp_timeout_positive');
                }
                elseif (empty($timeout)) {
                    $post_response['error']['timeout'] = $this->language->get('error_apirone_mccp_timeout');
                }
                // Invalid payment adjustment factor
                $factor = $this->data['apirone_mccp_factor'];
                if ($factor <= 0 || empty($factor)) {
                    $post_response['error']['factor'] = $this->language->get('error_apirone_mccp_factor');
                }
            }
            else {
                // Save settings if post & no errors

                $_status_ids = [];
                foreach (array_keys(DEFAULT_STATUS_IDS) as $apirone_status) {
                    $_status_ids[$apirone_status] = intval($post_data['apirone_mccp_invoice_'.$apirone_status.'_status_id']);
                }
                $plugin_data[SETTING_PREFIX . 'settings'] = $this->settings
                    ->merchant(trim($post_data['apirone_mccp_merchant']))
                    ->testcustomer(trim($post_data['apirone_mccp_testcustomer']))
                    ->timeout(intval($post_data['apirone_mccp_timeout']))
                    ->processing_fee($post_data['apirone_mccp_processing_fee'])
                    ->with_fee(!!$post_data['apirone_mccp_with_fee'])
                    ->factor(floatval($post_data['apirone_mccp_factor']))
                    ->logo(!!$post_data['apirone_mccp_logo'])
                    ->debug(!!$post_data['apirone_mccp_debug'])
                    ->status_ids($_status_ids)
                    ->toJsonString();

                $plugin_data[SETTING_PREFIX . 'geo_zone_id'] = $post_data['apirone_mccp_geo_zone_id'];
                $plugin_data[SETTING_PREFIX . 'status'] = $post_data['apirone_mccp_status'];
                $plugin_data[SETTING_PREFIX . 'sort_order'] = $post_data['apirone_mccp_sort_order'];

                $this->load->model('setting/setting');
                $this->model_setting_setting->editSetting(SETTINGS_CODE, $plugin_data);

                $post_response['success'] = $this->language->get('text_success');
            }
            $this->postResponse($post_response);
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

    protected function postResponse($data): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($data));
    }

    protected function errorPostResponse($error_message_key): void
    {
        $post_response['error']['warning'] = $this->language->get($error_message_key);
        $this->postResponse($post_response);
    }

    protected function errorResponse($error_message_key): void
    {
        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            $this->errorPostResponse($error_message_key);
            return;
        }
        $this->setErrorPageData($error_message_key);
    }
}
