<?php

namespace Opencart\Catalog\Model\Extension\Apirone\Payment;

require_once(DIR_EXTENSION . 'apirone/system/library/apirone_api/Db.php');

use ApironeApi\Db;
class ApironeMccp extends \Opencart\System\Engine\Model {

    public function getMethod($address) {
        $this->load->language('extension/apirone/payment/apirone_mccp');
        $status = false;
        $method_data = array();

        $activeCurrencies = $this->getActiveCurrencies();
        
        if ($activeCurrencies) {
            $geozone = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" 
                . (int)$this->config->get('apirone_mccp_geo_zone_id') 
                . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

            if (!$this->config->get('apirone_geo_zone_id') || $geozone->num_rows) {
                $status = true;
            }
        }

        if ($status) {
            $currencies = '';
            foreach ($activeCurrencies as $item) {
                $currencies .= $item->name . ', ';
            }
            $currencies = substr($currencies, 0, -2);

            $method_data = array(
                'code'       => 'apirone_mccp',
                'title'      => $this->language->get('text_title'),
                'terms'      => '',
                'sort_order' => $this->config->get('payment_apirone_mccp_sort_order')
            );  
        }

        return $method_data;
    }

    public function getInvoiceByOrderId($order_id) {
        $result = $this->db->query(\ApironeApi\Db::getOrderInvoiceQuery($order_id, DB_PREFIX));
        if ($result->num_rows) {
            $invoice = $result->rows[0];
            $invoice['details'] = json_decode($invoice['details']);

            return json_decode(json_encode($invoice));
        }
        else {
            return false;
        }
    }

    public function getInvoiceById($invoice_id) {
        $result = $this->db->query(\ApironeApi\Db::getInvoiceQuery($invoice_id, DB_PREFIX));

        if ($result->num_rows) {
            $invoice = $result->rows[0];
            $invoice['details'] = json_decode($invoice['details']);

            return json_decode(json_encode($invoice));
        }
        else {
            return false;
        }
    }

    public function updateInvoice($order_id, $objInvoice) {
        $this->load->model('checkout/order');

        $params = array();
        $invoice = $this->getInvoiceByOrderId($order_id);

        if($invoice) {
            // Do update
            $params['order_id'] = $order_id;
            $params['status'] = $objInvoice->status;
            $params['details'] = $objInvoice;

            $result = $this->db->query(\ApironeApi\Db::updateInvoiceQuery($params, DB_PREFIX));
        }
        else {
            // Do insert
            $params['order_id'] = $order_id;
            $params['account'] = $objInvoice->account;
            $params['invoice'] = $objInvoice->invoice;
            $params['status'] = $objInvoice->status;
            $params['details'] = $objInvoice;

            $result = $this->db->query(\ApironeApi\Db::insertInvoiceQuery($params, DB_PREFIX));
        }
        if ($result) {
            $savedInvoice = $this->getInvoiceByOrderId($order_id);
            $this->updateOrderStatus($savedInvoice);

            return $savedInvoice;
        }
        else {
            return false;
        }

    }

    public function getActiveCurrencies() {
        $currencies = unserialize($this->config->get('payment_apirone_mccp_currencies'));
        $showTestnet = $this->showTestnet();
        $showTestnet = true;
        $activeCurrencies = array();

        foreach ($currencies as $item) {
            if ($item->testnet == 1 && $showTestnet == false) {
                continue;
            }
            if (!empty($item->address))
                $activeCurrencies[] = $item;
        }

        return $activeCurrencies;
    }

    public function showTestnet() {
        $this->load->model('account/customer');

        if (!$this->customer->isLogged()) {
            return false;
        }
        $testcustomer = $this->config->get('payment_apirone_mccp_testcustomer');
        $email = $this->customer->getEmail();

        return ($testcustomer == $email) ? true : false;
    }

    private function updateOrderStatus($invoice) {

        $orderHistory = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_history WHERE `order_id` = " . (int) $invoice->order_id);
        $invoiceHistory = $invoice->details->history;

        foreach ($invoiceHistory as $item) {
            $comment = $this->_historyRecordComment($invoice->details->address, $item);

            if ($this->_isHistoryRecordExists($comment, $orderHistory)) {
                continue;
            }

            $status = $this->config->get('payment_apirone_mccp_invoice_' . $item->status . '_status_id');

            $this->model_checkout_order->addHistory($invoice->order_id, $status, $comment);
        }
    }

    private function _isHistoryRecordExists($comment, $history) {
        foreach ($history->rows as $row) {
            if ($row['comment'] == $comment) {
                return true;
            }
        }
        return false;
    }

    private function _historyRecordComment($address, $item) {
        
        switch ($item->status) {
            case 'created':
                $comment = 'Invoice ' . $item->status . '. Payment address: ' . $address;
                break;
            case 'paid':
            case 'partpaid':
            case 'overpaid':
                $comment = 'Invoice ' . $item->status . '. Transaction hash: ' . $item->txid;
                break;
            case 'completed':
            case 'expired':
                $comment = 'Invoice ' . $item->status;
                break;
        }

        return $comment;
    }

}