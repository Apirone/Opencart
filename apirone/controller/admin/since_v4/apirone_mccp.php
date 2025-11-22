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

        if ($this->request->server['REQUEST_METHOD'] == 'POST' && !$this->user->hasPermission('modify', PATH_TO_RESOURCES)) {
            $this->errorResponse('error_permission');
            return;
        }
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
        $checkValuesResults = $this->checkAndSetValues();
        $has_errors = $checkValuesResults['has_errors'];
        $active_networks = $checkValuesResults['active_networks'];

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
                $this->saveSettingsFromPostData();
                $post_response['success'] = $this->language->get('text_success');
            }
            $this->postResponse($post_response);
            return;
        }
        $this->setPageData();
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
