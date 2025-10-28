<?php

namespace Apirone\Payment\Model;

require_once(((int) explode('.', VERSION, 2)[0] < 4 ? DIR_SYSTEM . 'library/apirone/' : DIR_EXTENSION . 'apirone/system/library/') . 'apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'model/common' . (OC_MAJOR_VERSION < 4 ? '_before_oc4' : '') . '.php');
require_once(PATH_TO_LIBRARY . 'vendor/autoload.php');

use \Apirone\Payment\Model\LogCommon as Log;

use \Apirone\API\Endpoints\Service;
use \Apirone\API\Http\Request;
use \Apirone\API\Log\LoggerWrapper;
use \Apirone\API\Log\LogLevel;

use \Apirone\SDK\Invoice;
use \Apirone\SDK\Model\HistoryItem;
use \Apirone\SDK\Model\Settings;
use \Apirone\SDK\Model\Settings\Coin;

class ModelExtensionPaymentApironeMccpCommon extends ModelExtensionPaymentCommon
{
    private static ?Log $logger = null;
    protected static ?Settings $settings = null;

    public function initLogger(): void
    {
        self::$logger = new Log('apirone.log');
        LoggerWrapper::setLogger($this);
    }

    protected function getLogger(): Log
    {
        if (!self::$logger) {
            $this->initLogger();
        }
        return self::$logger;
    }

    /**
     * @return bool log extended info except errors
     */
    protected function isDebug(): bool
    {
        // true parameter mandatory! to prevent infinite recursion
        $_settings = $this->getSettings(true);
        return !$_settings ? false : !!$_settings->debug;
    }

    /**
     * Universal log handler.
     * Logs any message of ERROR level.
     * Logs message of other level only if debug mode is turned on.
     * @param string $log_level constants from LogLevel static class
     * @param string $message
     * @param ?array $context array of additional info if need
     */
    public function log(string $log_level, string $message, array $context = array()): void
    {
        if ($log_level == LogLevel::ERROR || $this->isDebug()) {
            $this->getLogger()->write($message . (!isset($context) ? '' : ' CONTEXT: '. json_encode($context)));
        }
    }

    /**
     * Logs the message anyway.
     * Shorthand for {@link log()} with ERROR level without context.
     * @param string $message
     */
    public function logError(string $message): void
    {
        $this->getLogger()->write($message);
    }

    /**
     * Logs the message only if debug mode is turned on.
     * Shorthand for {@link log()} with INFO level without context.
     * @param string $message
     */
    public function logInfo(string $message): void
    {
        $this->log(LogLevel::INFO, $message);
    }

    /**
     * @param bool $doNotTryUpdate do not try update plugin before get settings
     * @return Settings plugin settings
     */
    public function getSettings(bool $doNotTryUpdate = false): ?Settings
    {
        if (self::$settings) {
            return self::$settings;
        }
        if (!$doNotTryUpdate && $this->update()) {
            $this->load->model('setting/setting');
            $plugin_data = $this->model_setting_setting->getSetting(SETTINGS_CODE);
            $_settings_json = $plugin_data[SETTING_PREFIX . 'settings'];
        }
        else {
            $_settings_json = $this->config->get(SETTING_PREFIX . 'settings');
        }
        if (!$_settings_json) {
            $this->logError('No Apirone MCCP plugin settings found, try reinstall plugin');
            return null;
        }
        try {
            self::$settings = Settings::fromJson($_settings_json);
        }
        catch (\Exception $e) {
            $this->logError('Can not get Apirone MCCP plugin settings: ' . $e->getMessage());
            return null;
        }
        return self::$settings;
    }

    protected function hash(string $main, string $salt): string
    {
        return md5($main . $salt);
    }

    /**
     * @return \Closure DB query handler for Invoice
     */
    protected function getDBHandler(): \Closure
    {
        return function($query) {
            try {
                $result = $this->db->query($query);
            }
            catch (\Exception $e) {
                $this->logError('Can not execute DB query: ' . $e->getMessage());
                return null;
            }
            if ($result === true || $result === false) {
                return $result;
            }
            if (empty($result) || !property_exists($result, 'rows')) {
                return null;
            }
            $result = $result->rows;
            if (empty($result)) {
                return null;
            }
            return $result;
        };
    }

    public function initInvoiceModel(): void
    {
        Invoice::db($this->getDBHandler(), DB_PREFIX);
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

    /**
     * Updates database structure if version changed
     * @return bool plugin version has changed
     */
    public function update(): bool
    {
        if (!$this->config->has(SETTING_PREFIX . 'settings')) {
            $version = $this->config->get(SETTING_PREFIX . 'version');
            if (!($version || $this->config->has(SETTING_PREFIX . 'account'))) {
                // no valid plugin data stored, nothing to update
                $this->logError('No valid plugin settings');
                return false;
            }
        }
        else {
            // true parameter mandatory! to prevent infinite recursion
            $_settings = $this->getSettings(true);
            if (!$_settings) {
                // can not get settings and version, nothing to update
                return false;
            }
            $version = $_settings->version;
            if (!$version) {
                // no version in settings, nothing to update
                $this->logError('No any plugin version in settings');
                return false;
            }
        }
        $updated = $version != PLUGIN_VERSION;

        if (!$version) {
            $version = $this->upd_1_0_1__1_1_0();
        }
        if ($version == '1.1.0') {
            $version = $this->upd_version('1.1.1');
        }
        if ($version == '1.1.1') {
            $version = $this->upd_version('1.1.2');
        }
        if ($version == '1.1.2') {
            $version = $this->upd_version('1.1.3');
        }
        if ($version == '1.1.3') {
            $version = $this->upd_version('1.1.4');
        }
        if ($version == '1.1.4') {
            $version = $this->upd_1_1_4__1_2_0();
        }
        if ($version == '1.2.0') {
            $version = $this->upd_version('1.2.1');
        }
        if ($version == '1.2.1') {
            $version = $this->upd_version('1.2.2');
        }
        if ($version == '1.2.2') {
            $version = $this->upd_version('1.2.3');
        }
        if ($version == '1.2.3') {
            $version = $this->upd_version('1.2.4');
        }
        if ($version == '1.2.4') {
            $version = $this->upd_version('1.2.5');
        }
        if ($version == '1.2.5') {
            $version = $this->upd_version('1.2.6');
        }
        if ($version == '1.2.6') {
            $version = $this->upd_1_2_6__2_0_0();
        }
        return $updated;
    }

    /**
     * Updates plugin version only in settings
     * @param string $version new plugin version
     * @return ?string the same new version as in param on success
     */
    protected function upd_version(string $version): ?string
    {
        if (substr($version, 0, 2) === '1.') {
            $this->config->set(SETTING_PREFIX . 'version', $version);
            return $version;
        }
        // true parameter mandatory! to prevent infinite recursion
        $_settings = $this->getSettings(true);
        if (!$_settings) {
            return null;
        }
        $this->config->set(SETTING_PREFIX . 'settings', $_settings->version($version)->toJsonString());
        return $version;
    }

    /**
     * Updates database version from 1 to 2
     * @return ?string new version on success
     */
    private function upd_1_2_6__2_0_0(): ?string
    {
        $version = '2.0.0';

        $this->load->model('setting/setting');
        $plugin_data = $this->model_setting_setting->getSetting(SETTINGS_CODE);

        $account_serialized = $plugin_data[SETTING_PREFIX . 'account'];
        if ($account_serialized) {
            $account = unserialize($account_serialized);

            $account_id = $account->account;
            $transfer_key = $account->{'transfer-key'};
        }
        try {
            $_settings = $account_id && $transfer_key
                ? Settings::fromExistingAccount($account_id, $transfer_key)
                : Settings::init()->createAccount();
        }
        catch (\Exception $e) {
            $this->logError('Can not get or create account: ' . $e->getMessage());
            return null;
        }
        $_status_ids = [];
        foreach (DEFAULT_STATUS_IDS as $apirone_status => $oc_default_status_id) {
            $key = SETTING_PREFIX . 'invoice_' . $apirone_status . '_status_id';

            $_status_ids[$apirone_status] = key_exists($key, $plugin_data)
                ? intval($plugin_data[$key])
                : $oc_default_status_id;
        }
        $coins = [];
        foreach ($_settings->networks as $network) {
            if (!$network->address) {
                continue;
            }
            // address stored for currency
            if (!count($tokens = $network->tokens)) {
                // currency with address has no tokens, add it as visible
                $coins[] = Coin::init($network);
                continue;
            }
            // currency has tokens, add all as visible by default
            foreach ($tokens as $token) {
                $coins[] = Coin::init($token);
            }
        }
        $_settings
            ->version($version)
            ->secret($plugin_data[SETTING_PREFIX . 'secret'])
            ->merchant($plugin_data[SETTING_PREFIX . 'merchantname'])
            ->testcustomer($plugin_data[SETTING_PREFIX . 'testcustomer'])
            ->timeout(intval($plugin_data[SETTING_PREFIX . 'timeout']))
            ->processing_fee($plugin_data[SETTING_PREFIX . 'processing_fee'])
            ->factor(floatval($plugin_data[SETTING_PREFIX . 'factor']))
            ->logo(true)
            ->debug(!!$plugin_data[SETTING_PREFIX . 'debug'])
            ->status_ids($_status_ids)
            ->coins($coins);

        $this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND `code` = '" . SETTINGS_CODE . "' AND NOT `key` IN ('" . SETTING_PREFIX . "geo_zone_id', '" . SETTING_PREFIX . "status', '" . SETTING_PREFIX . "sort_order')");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = '" . SETTINGS_CODE . "', `key` = '" . SETTING_PREFIX . "settings', `value` = '" . $this->db->escape($_settings->toJsonString()) . "'");

        $this->update_invoice_table_1__2();

        return $version;
    }

    /**
     * Updates invoice DB table
     */
    private function update_invoice_table_1__2(): void
    {
        $table_name_current = DB_PREFIX.'apirone_mccp';

        if (!$this->db->query("SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE table_schema = '".DB_DATABASE."' AND table_name = '".$table_name_current."';")) {
            $this->logError('No '.$table_name_current.' table in DB schema '.DB_DATABASE);
            return;
        }
        $table_name_new = DB_PREFIX.'apirone_invoice';

        if (!$this->db->query("RENAME TABLE ".DB_DATABASE.".".$table_name_current." TO ".DB_DATABASE.".".$table_name_new.";")) {
            $this->logError('Can not rename '.$table_name_current.' table to '.$table_name_new.' in DB schema '.DB_DATABASE);
            return;
        }
        if (!$this->db->query("ALTER TABLE ".DB_DATABASE.".".$table_name_new." RENAME COLUMN `order_id` TO `order`;")) {
            $this->logError('Can not rename column in '.$table_name_new.' table in DB schema '.DB_DATABASE);
        }
        if (!$this->db->query("ALTER TABLE ".DB_DATABASE.".".$table_name_new." ADD `meta` TEXT;")) {
            $this->logError('Can not add column to '.$table_name_new.' table in DB schema '.DB_DATABASE);
        }
    }

    /**
     * Updates database from very old version
     * @return ?string new version on success
     */
    private function upd_1_1_4__1_2_0(): ?string
    {
        $account_serialized = $this->config->get(SETTING_PREFIX . 'account');
        if (!$account_serialized) {
            $this->logError('No account data');
            return null;
        }
        $account = unserialize($account_serialized);
        if (!($account && $account->account)) {
            $this->logError('Invalid account data');
            return null;
        }
        $endpoint = '/v2/accounts/' . $account->account;

        $params['transfer-key'] = $account->{'transfer-key'};
        $params['processing-fee-policy'] = 'percentage';

        try {
            $currencies = Service::account();

            foreach ($currencies as $currency) {
                $params['currency'] = $currency->abbr;

                Request::patch($endpoint, $params);
            }
            return $this->upd_version('1.2.0');
        }
        catch (\Exception $e) {
            $this->logError('Can not update settings: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Updates database from very very old version
     * @return ?string new version on success
     */
    private function upd_1_0_1__1_1_0(): ?string
    {
        $version = '1.1.0';

        // Get current settings
        $pending = $this->config->get(SETTING_PREFIX . 'pending_status_id') ?: 1;
        $completed = $this->config->get(SETTING_PREFIX . 'completed_status_id') ?: 5;
        $voided = $this->config->get(SETTING_PREFIX . 'voided_status_id') ?: 16;

        // Remove old settings
        $this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE `code` = '" . SETTINGS_CODE . "' AND `key` IN ('" . SETTING_PREFIX . "pending_status_id', '" . SETTING_PREFIX . "completed_status_id', '" . SETTING_PREFIX . "voided_status_id')");

        // Add new settings
        $data = array(
            SETTING_PREFIX . 'version' => $version,
            SETTING_PREFIX . 'invoice_created_status_id' => $pending,
            SETTING_PREFIX . 'invoice_paid_status_id' => $pending,
            SETTING_PREFIX . 'invoice_partpaid_status_id' => $pending,
            SETTING_PREFIX . 'invoice_overpaid_status_id' => $pending,
            SETTING_PREFIX . 'invoice_completed_status_id' => $completed,
            SETTING_PREFIX . 'invoice_expired_status_id' => $voided,
        );
        foreach ($data as $key => $value) {
            $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = '" . SETTINGS_CODE . "', `key` = '" . $key . "', `value` = '" . $value . "'");
		}
        return $version;
    }
}
