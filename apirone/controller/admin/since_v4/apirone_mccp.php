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
            $this->errorPostResponse('error_permission');
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
        } catch (\Exception $e) {
            $this->errorResponse('error_cant_get_currencies', $e->getMessage());
            return;
        }
        if (!count($networks)) {
            $this->errorResponse('error_cant_get_currencies_service_unavailable');
            return;
        }
        $networks_errors = $this->checkAndSetValues();

        if ($this->request->server['REQUEST_METHOD'] != 'POST') {
            // Full page render
            $this->setPageData();
            return;
        }
        // Post data handling
        $post_response = [];

        // Save settings anyway
        $this->saveSettingsFromPostData();

        if (!empty($networks_errors)) {
            foreach ($networks_errors as $abbr => $error) {
                $post_response['error']['address_' . $abbr] = $error;
            }
        }
        if (array_key_exists('apirone_mccp_timeout', $this->error)) {
            $post_response['error']['timeout'] = $this->error['apirone_mccp_timeout'];
        }
        if (array_key_exists('apirone_mccp_factor', $this->error)) {
            $post_response['error']['factor'] = $this->error['apirone_mccp_factor'];
        }
        if (array_key_exists('error', $post_response) && !empty($post_response['error'])) {
            $post_response['error']['warning'] = $this->language->get('error_warning');
        }
        else {
            $post_response['success'] = $this->language->get('text_success');
        }
        $this->postResponse($post_response);
    }

    protected function postResponse(&$data): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($data));
    }

    protected function errorPostResponse($error_message_key, $arg = null): void
    {
        $error_message_pattern = $this->language->get($error_message_key);
        $post_response['error']['warning'] = $arg ? sprintf($error_message_pattern, $arg) : $error_message_pattern;
        $this->postResponse($post_response);
    }

    protected function errorResponse($error_message_key, $arg = null): void
    {
        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            $this->errorPostResponse($error_message_key, $arg);
            return;
        }
        $this->setErrorPageData($error_message_key, $arg);
    }
}
