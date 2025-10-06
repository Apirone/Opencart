<?php

use Apirone\SDK\Invoice;
use Apirone\SDK\Model\HistoryItem;
use Apirone\SDK\Model\Settings;

require_once(DIR_SYSTEM . 'library/apirone/vendor/autoload.php');

class ModelExtensionPaymentApironeMccp extends Model 
{
    /**
     * Gets method data to show in payment method selector\
     * OpenCart required
     */
    public function getMethod(array $address): array
    {
        $geo_zone_id_allowed = (int) $this->config->get('payment_apirone_mccp_geo_zone_id');
        if ($geo_zone_id_allowed) {
            $geo_zone_query_result = $this->db->query(sprintf(
                'SELECT * FROM %s WHERE geo_zone_id = "%s" AND country_id = "%s" AND (zone_id = "%s" OR zone_id = "0")',
                DB_PREFIX . 'zone_to_geo_zone',
                $geo_zone_id_allowed,
                (int) $address['country_id'],
                (int) $address['zone_id'],
            ));
            if (!$geo_zone_query_result->num_rows) {
                return [];
            }
        }
        $_settings = $this->getSettings();
        if (!$_settings) {
            return [];
        }
        $coins = $_settings->coins;
        if (empty($coins)) {
            return [];
        }
        $currencies = '';
        foreach ($coins as $coin) {
            if ($currencies) {
                $currencies .= ', ';
            }
            $currencies .= $coin->alias;
        }
        $this->load->language('extension/payment/apirone_mccp');
        return array(
            'code'       => 'apirone_mccp',
            'title'      => sprintf('<span data-toggle="tooltip" data-original-title="%s">%s</span>', $currencies, $this->language->get('text_title')),
            'terms'      => '',
            'sort_order' => $this->config->get('payment_apirone_mccp_sort_order')
        );  
    }

    /**
     * @return Settings plugin settings
     */
    protected function getSettings(): ?Settings
    {
        $_settings_json = $this->config->get('payment_apirone_mccp_settings');
        if (!$_settings_json) {
            return null;
        }
        try {
            return Settings::fromJson($_settings_json);
        }
        catch (Exception $e) {
            $openCartLogger = new \Log(PLUGIN_LOG_FILE_NAME);
            $openCartLogger->write($e->getMessage());
            return null;
        }
    }

    /**
     * @return Closure DB query handler for Invoice
     */
    public function getDBHandler(): Closure
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
    public function updateOrderStatus(Invoice $invoice): void
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

    private function isHistoryRecordExists(string $comment, mixed $history)
    {
        foreach ($history->rows as $row) {
            if ($row['comment'] == $comment) {
                return true;
            }
        }
        return false;
    }

    private function getHistoryRecordComment(string $address, HistoryItem $item)
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
