<?php

require_once(DIR_SYSTEM . 'library/apirone/apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'controller/admin/apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'vendor/autoload.php');

use Apirone\Payment\Controller\Admin\ControllerExtensionPaymentApironeMccpAdmin;

// the class name formation matters
class ControllerExtensionPaymentApironeMccp extends ControllerExtensionPaymentApironeMccpAdmin
{
    /**
     * Loads main payment settings admin page in response to GET or POST request\
     * OpenCart required
     */
    public function index(): void
    {
        $this->load->language(PATH_TO_RESOURCES);

        if ($this->request->server['REQUEST_METHOD'] == 'POST' && !$this->user->hasPermission('modify', PATH_TO_RESOURCES)) {
            $this->setErrorPageData('error_permission');
            return;
        }
        $this->settings = $this->model->getSettings();
        if (!$this->settings) {
            $this->setErrorPageData('error_service_not_available');
            return;
        }
        $this->data['apirone_mccp_account'] = $this->settings->account;

        try {
            $networks = $this->settings->networks;
        } catch (\Throwable $ignore) {
            $this->setErrorPageData('error_cant_get_currencies');
            return;
        }
        if (!count($networks)) {
            $this->setErrorPageData('error_cant_get_currencies');
            return;
        }
        $checkValuesResults = $this->checkAndSetValues();
        $has_errors = $checkValuesResults['has_errors'];
        $active_networks = $checkValuesResults['active_networks'];

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            if ($has_errors) {
                $this->data['error'] = $this->language->get('error_warning');
                // No addresses
                if (!$active_networks) {
                    $this->data['error'] = $this->language->get('error_empty_currencies');
                }
                foreach ($networks as $network) {
                    if ($network->hasError()) {
                        if (!(array_key_exists('error', $this->data) && $this->data['error'])) {
                            $this->data['error'] = sprintf($this->language->get('error_currency_save'), $network->name, $network->error);
                        }
                    }
                }
                // Payment timeout
                $timeout = $this->data['apirone_mccp_timeout'];
                if ($timeout === 0 || $timeout < 0) {
                    $this->error['apirone_mccp_timeout'] = $this->language->get('error_apirone_mccp_timeout_positive');
                }
                elseif (empty($timeout)) {
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
                $this->saveSettingsFromPostData();
                $this->data['success'] = $this->language->get('text_success');
            }
        }
        $this->setPageData();
    }
}
