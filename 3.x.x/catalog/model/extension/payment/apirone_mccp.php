<?php

use Apirone\SDK\Invoice;
use Apirone\SDK\Model\Settings;

require_once(DIR_SYSTEM . 'library/apirone/vendor/autoload.php');

class ModelExtensionPaymentApironeMccp extends Model 
{
    /**
     * Gets method data to show in payment method selector\
     * OpenCart required
     */
    public function getMethod($address, $total)
    {
        $status = false;
        $method_data = array();

        $_settings = $this->getSettings();
        $coins = $_settings ? $_settings->coins : false;
        if ($coins) {
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

            $this->load->language('extension/payment/apirone_mccp');
            $method_data = array(
                'code'       => 'apirone_mccp',
                'title'      => '<span data-toggle="tooltip" data-original-title="' . $currencies . '">' . $this->language->get('text_title') . '</span>',
                'terms'      => '',
                'sort_order' => $this->config->get('payment_apirone_mccp_sort_order')
            );  
        }
        return $method_data;
    }

    /**
     * Gets plugin settings
     * @return Settings
     */
    protected function getSettings()
    {
        $_settings_json = $this->config->get('payment_apirone_mccp_settings');
        if (!$_settings_json) {
            return;
        }
        try {
            return Settings::fromJson($_settings_json);
        }
        catch (Exception $e) {
            $openCartLogger = new \Log(PLUGIN_LOG_FILE_NAME);
            $openCartLogger->write($e->getMessage());
        }
    }

    /**
     * @return Closure(mixed $query): mixed DB query handler for Invoice
     */
    public function getDBHandler()
    {
        return function($query) {
            try {
                $result = $this->db->query($query);
                if ($result === true || $result === false) {
                    return $result;
                }
                if (empty($result)) {
                    return null;
                }
                $result = $result->rows;
                if (empty($result)) {
                    return null;
                }
                return $result;
            }
            catch (Exception $e) {
                $openCartLogger = new \Log(PLUGIN_LOG_FILE_NAME);
                $openCartLogger->write($e->getMessage());
                return null;
            }
        };
    }

    /**
     * Updates order status from invoice details data when invoice status changed
     * @param Invoice $invoice
     */
    public function updateOrderStatus($invoice)
    {
        $_settings = $this->getSettings();
        if (!$_settings) {
            return;
        }
        $this->load->model('checkout/order');

        $orderHistory = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_history WHERE `order_id` = " . (int) $invoice->order);
        $invoiceHistory = $invoice->details->history;

        foreach ($invoiceHistory as $item) {
            $comment = $this->getHistoryRecordComment($invoice->details->address, $item);

            if ($this->isHistoryRecordExists($comment, $orderHistory)) {
                continue;
            }
            $this->model_checkout_order->addOrderHistory($invoice->order, $_settings->status_ids->{$item->status}, $comment);
        }
    }

    private function isHistoryRecordExists($comment, $history)
    {
        foreach ($history->rows as $row) {
            if ($row['comment'] == $comment) {
                return true;
            }
        }
        return false;
    }

    private function getHistoryRecordComment($address, $item)
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
