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
        $networks_errors = $this->checkAndSetValues();

        if ($this->request->server['REQUEST_METHOD'] != 'POST') {
            // First page loading
            $this->setPageData();
            return;
        }
        // Post data handling

        // Save settings anyway
        $this->saveSettingsFromPostData();

        if (!empty($networks_errors)
            || array_key_exists('apirone_mccp_timeout', $this->error)
            || array_key_exists('apirone_mccp_factor', $this->error)
        ) {
            $this->data['error'] = $this->language->get('error_warning');
        }
        else {
            $this->data['success'] = $this->language->get('text_success');
        }
        $this->setPageData();
    }
}
