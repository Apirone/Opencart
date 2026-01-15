<?php

require_once(DIR_SYSTEM . 'library/apirone/apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'controller/catalog/apirone_mccp.php');

use Apirone\Payment\Controller\Catalog\ControllerExtensionPaymentApironeMccpCatalog;

// the class name formation matters
class ControllerExtensionPaymentApironeMccp extends ControllerExtensionPaymentApironeMccpCatalog
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

        $data['coins'] = $coins = $this->getCoins($order['total'] * $order['currency_value'], $order['currency_code']);
        $data['coin_first'] = array_key_first($coins);
        $data['order_id'] = $order['order_id'];
        $data['order_key'] = $this->model->getHash($order['total']);
        $data['url_redirect'] = $this->url->link(PATH_FOR_ROUTES . 'confirm');

        $data['apirone_path_to_images'] = 'catalog/view/theme/default/image/apirone/';
        $data['apirone_path_to_css'] = 'catalog/view/theme/default/stylesheet/apirone/';

        return $this->load->view(PATH_TO_VIEWS, $data);
    }
}
