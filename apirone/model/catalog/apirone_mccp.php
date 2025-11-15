<?php

namespace Apirone\Payment\Model\Catalog;

require_once((version_compare(VERSION, 4, '<')
    ? DIR_SYSTEM . 'library/apirone/'
    : DIR_EXTENSION . 'apirone/system/library/'
) . 'apirone_mccp.php');

require_once(PATH_TO_LIBRARY . 'model/apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'vendor/autoload.php');

use Apirone\Payment\Model\ModelExtensionPaymentApironeMccpCommon;

use Apirone\SDK\Invoice;
use Apirone\SDK\Model\HistoryItem;
use Apirone\SDK\Model\Settings\Coin;

class ModelExtensionPaymentApironeMccpCatalog extends ModelExtensionPaymentApironeMccpCommon
{
    // TODO: can be replaced with SDK method if it will
    public function splitCoinAbbr(Coin $coin): \stdClass
    {
        $std = new \stdClass();

        $abbr = $coin->abbr;

        $std->abbr = $abbr;
        $std->alias = $coin->alias;
        $std->test = $coin->test;

        if (!$abbr) {
            $std->network = null;
            $std->token = null;
            return $std;
        }
        $parts = explode('@', $abbr, 2);
        if (count($parts) == 1) {
            $std->network = $abbr;
            $std->token = null;
            return $std;
        }
        $std->token = $parts[0];
        $std->network = $parts[1];
        return $std;
    }

    /**
     * @return array array of coins available for user
     */
    public function getCoinsAvailable(): ?array
    {
        if (!$this->getSettings()) {
            return null;
        }
        $show_testnet = $this->showTestnet();
        $coins = [];
        foreach (self::$settings->coins as $coin) {
            if ($show_testnet || !$coin->test) {
                // TODO: can be replaced with SDK method if it will
                // $coins[] = $coin->toStd();
                $coins[] = $this->splitCoinAbbr($coin);
            }
        }
        return $coins;
    }

    /**
     * @return bool show test networks
     */
    protected function showTestnet(): bool
    {
        $testcustomer = self::$settings->testcustomer;
        if ($testcustomer == '*') {
            return true;
        }
        $this->load->model('account/customer');
        if (!$this->customer->isLogged()) {
            return false;
        }
        return $this->customer->getEmail() == $testcustomer;
    }

    /**
     * @param array $address customer address data
     * @return ?string comma separated crypto currencies the customer can pay with
     */
    public function getCurrencies(array $address): ?string
    {
        $geo_zone_id_allowed = (int) $this->config->get(SETTING_PREFIX . 'geo_zone_id');
        if ($geo_zone_id_allowed) {
            $geo_zone_query_result = $this->db->query(sprintf(
                'SELECT * FROM %s WHERE geo_zone_id = "%s" AND country_id = "%s" AND (zone_id = "%s" OR zone_id = "0")',
                DB_PREFIX . 'zone_to_geo_zone',
                $geo_zone_id_allowed,
                (int) $address['country_id'],
                (int) $address['zone_id'],
            ));
            if (!$geo_zone_query_result->num_rows) {
                return null;
            }
        }
        $coins = $this->getCoinsAvailable();
        if (empty($coins)) {
            return null;
        }
        $currencies = '';
        foreach ($coins as $coin) {
            if ($currencies) {
                $currencies .= ', ';
            }
            $currencies .= $coin->alias;
        }
        $this->load->language(PATH_TO_RESOURCES);
        return $currencies;
    }

    public function getHash(string $salt): string
    {
        return $this->hash($this->getSettings()->secret, $salt);
    }

    public function hashInvalid(string $salt, string $hash): bool
    {
        return $this->getHash($salt) != $hash;
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
            if (OC_MAJOR_VERSION < 4) {
                $this->model_checkout_order->addOrderHistory($invoice->order, $_settings->status_ids->{$item->status}, $comment);
            }
            else {
                $this->model_checkout_order->addHistory($invoice->order, $_settings->status_ids->{$item->status}, $comment);
            }
        }
    }

    private function isHistoryRecordExists(string $comment, \stdClass $history): bool
    {
        foreach ($history->rows as $row) {
            if ($row['comment'] == $comment) {
                return true;
            }
        }
        return false;
    }

    private function getHistoryRecordComment(string $address, HistoryItem $item): string
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
