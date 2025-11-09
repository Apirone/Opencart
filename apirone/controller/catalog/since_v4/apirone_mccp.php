<?php

namespace Opencart\Catalog\Controller\Extension\Apirone\Payment;

require_once(DIR_EXTENSION . 'apirone/system/library/apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'controller/catalog/apirone_mccp.php');

use \Apirone\Payment\Controller\Catalog\ControllerExtensionPaymentApironeMccpCatalog;

// class must be named as plugin
class ApironeMccp extends ControllerExtensionPaymentApironeMccpCatalog
{    /**
     * Renders crypto currency selector\
     * OpenCart required
     */
    public function index(): string
    {
        // all model updates is in model getSettings method
        $this->settings = $this->model->getSettings();
        if (!$this->settings) {
            $data['coins'] = null;
            return $this->load->view(PATH_TO_VIEWS, $data);
        }
        $data['button_confirm'] = $this->language->get('button_confirm');

        $this->load->language(PATH_TO_RESOURCES);
        $data = array_merge($data, $this->load->language('apirone_mccp'));

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $data['coins'] = $this->getCoins($order['total'] * $order['currency_value'], $order['currency_code']);
        $data['order_id'] = $order['order_id'];
        $data['order_key'] = $this->model->getHash($order['total']);
        $data['url_redirect'] = $this->url->link(PATH_FOR_ROUTES . 'confirm');

        $data['apirone_path_to_images'] = 'extension/apirone/catalog/view/image/';
        $data['apirone_path_to_css'] = 'extension/apirone/catalog/view/stylesheet/';

        return $this->load->view(PATH_TO_VIEWS, $data);
    }
}
