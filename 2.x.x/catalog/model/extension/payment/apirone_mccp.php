<?php

use Apirone\SDK\Model\Settings;

require_once(DIR_SYSTEM . 'library/apirone/vendor/autoload.php');

class ModelExtensionPaymentApironeMccp extends Model 
{
    public function getMethod($address, $total)
    {
        $this->load->language('extension/payment/apirone_mccp');
        $status = false;
        $method_data = array();

        if ($coins = $this->getCoins()) {
            $geozone = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" 
                . (int) $this->config->get('apirone_mccp_geo_zone_id') 
                . "' AND country_id = '" . (int) $address['country_id'] . "' AND (zone_id = '" . (int) $address['zone_id'] . "' OR zone_id = '0')");

            if (!$this->config->get('apirone_geo_zone_id') || $geozone->num_rows) {
                $status = true;
            }
        }
        if ($status) {
            $currencies = '';
            foreach ($coins as $coin) {
                $currencies .= $coin->alias . ', ';
            }
            $currencies = substr($currencies, 0, -2);

            $method_data = array(
                'code'       => 'apirone_mccp',
                'title'      => '<span data-toggle="tooltip" data-original-title="' . $currencies . '">' . $this->language->get('text_title') . '</span>',
                'terms'      => '',
                'sort_order' => $this->config->get('apirone_mccp_sort_order')
            );  
        }
        return $method_data;
    }

    /**
     * Gets coins enabled from plugin settings
     * @internal
     */
    protected function getCoins()
    {
        $_settings_json = $this->config->get('apirone_mccp_settings');
        if (!$_settings_json) {
            return;
        }
        try {
            $_settings = Settings::fromJson($_settings_json);
        }
        catch (Exception $e) {
            $openCartLogger = new \Log(PLUGIN_LOG_FILE_NAME);
            $openCartLogger->write($e->getMessage());
            return;
        }
        if (!$_settings) {
            return;
        }
        return $_settings->coins;
    }

    public function updateOrderStatus($invoice)
    {
        $this->load->model('checkout/order');

        $orderHistory = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_history WHERE `order_id` = " . (int) $invoice->order);
        $invoiceHistory = $invoice->details->history;

        foreach ($invoiceHistory as $item) {
            $comment = $this->_historyRecordComment($invoice->details->address, $item);

            if ($this->_isHistoryRecordExists($comment, $orderHistory)) {
                continue;
            }

            // TODO: restore if statuses map is stored in Settings
            // $status = $this->config->get('apirone_mccp_invoice_' . $item->status . '_status_id');
            $status = $this->_historyRecordStatusId($item->status);

            $this->model_checkout_order->addOrderHistory($invoice->order, $status, $comment);
        }
    }

    private function _isHistoryRecordExists($comment, $history)
    {
        foreach ($history->rows as $row) {
            if ($row['comment'] == $comment) {
                return true;
            }
        }
        return false;
    }

    private function _historyRecordStatusId($status)
    {
        switch ($status) {
            case 'paid':
            case 'overpaid':
            case 'completed':
                return 5;
            case 'expired':
                return 16;
        }
        // created, partpaid
        return 1;
    }

    private function _historyRecordComment($address, $item)
    {
        $status = $item->status;
        $prefix = 'Invoice ' . $status;
        switch ($status) {
            case 'created':
                return $prefix . '. Payment address: ' . $address;
            case 'paid':
            case 'partpaid':
            case 'overpaid':
                return $prefix . '. Transaction hash: ' . $item->txid;
        }
        // completed, expired
        return $prefix;
    }
}
