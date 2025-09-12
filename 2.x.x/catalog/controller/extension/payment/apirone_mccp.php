<?php

use Apirone\API\Exceptions\RuntimeException;
use Apirone\API\Exceptions\ValidationFailedException;
use Apirone\API\Exceptions\UnauthorizedException;
use Apirone\API\Exceptions\ForbiddenException;
use Apirone\API\Exceptions\NotFoundException;
use Apirone\API\Exceptions\MethodNotAllowedException;
use Apirone\API\Exceptions\InternalServerErrorException;
use Apirone\API\Exceptions\JsonException;
use Apirone\API\Http\Request;
use Apirone\API\Log\LogLevel;

use ApironeApi\Apirone;
use ApironeApi\Payment;

use Apirone\SDK\Invoice;
use Apirone\SDK\Model\Settings;
use Apirone\SDK\Service\InvoiceDb;
use Apirone\SDK\Service\Utils;

require_once(DIR_SYSTEM . 'library/apirone_api/Apirone.php');
require_once(DIR_SYSTEM . 'library/apirone_api/Payment.php');

require_once(DIR_SYSTEM . 'library/apirone/vendor/autoload.php');

define('PLUGIN_LOG_FILE_NAME', 'apirone.log');

class ControllerExtensionPaymentApironeMccp extends Controller
{
    private Settings $settings;

    public function __construct($registry)
    {
        parent::__construct($registry);
        $this->initLogging();
    }

    /**
     * @return write to log extended info except errors
     * @since 2.0.0
     * @author Valery Yu <vvy1976@gmail.com>
     * @see also in admin/controller/extension/payment/apirone_mccp.php
     * @internal
     */
    protected function isDebug()
    {
        try {
            return !!$this->settings->debug;
        } catch (\Throwable $ignore) {
            return false;
        }
    }

    /**
     * Initializes logging
     * @since 2.0.0
     * @author Valery Yu <vvy1976@gmail.com>
     * @see also in admin/controller/extension/payment/apirone_mccp.php
     * @internal
     */
    protected function initLogging()
    {
        try {
            $openCartLogger = new \Log(PLUGIN_LOG_FILE_NAME);

            $logHandler = function($log_level, $message, $context) use ($openCartLogger) {
                if ($log_level == LogLevel::ERROR || $this->isDebug()) {
                    $openCartLogger->write($message.' '.print_r($context));
                }
            };
            Invoice::logger($logHandler);
        }
        catch (Exception $e) {
            $this->log->write($e->getMessage());
        }
    }

    public function index()
    {
        $data['button_confirm'] = $this->language->get('button_confirm');
        $this->load->model('checkout/order');
        $this->load->language('extension/payment/apirone_mccp');
        $this->load->model('extension/payment/apirone_mccp');
        try {
            $this->getSettings();

            $data = array_merge($data, $this->load->language('apirone_mccp'));
            $data['error_message'] = false;

            $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

            $data['coins'] = $this->getCoins(
                $order['total'] * $order['currency_value'],
                $order['currency_code'],
                $this->showTestnet()
            );
            $data['order_id'] = $order['order_id'];
            $data['order_key'] = md5($this->settings->secret . $order['total']);
            $data['url_redirect'] = $this->url->link('extension/payment/apirone_mccp/confirm');
        }
        catch (\Throwable $e) {
            $this->log->write($e->getMessage());

            $data['coins'] = null;
        }
        return $this->load->view('extension/payment/apirone/apirone_mccp', $data);
    }

    /**
     * Gets existing plugin settings
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws ValidationFailedException
     * @throws UnauthorizedException
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws MethodNotAllowedException
     * @internal
     */
    protected function getSettings()
    {
        $_settings_json = $this->config->get('apirone_mccp_settings');
        if (!$_settings_json) {
            throw new RuntimeException('No settings loaded. Try reinstall plugin');
        }
        $this->settings = Settings::fromJson($_settings_json);
    }

    /**
     * @return bool show test networks
     * @internal
     */
    protected function showTestnet() 
    {
        $testcustomer = $this->settings->testcustomer;

        if ($testcustomer == '*') {
            return true;
        }
        $this->load->model('account/customer');

        if (!$this->customer->isLogged()) {
            return false;
        }
        $email = $this->customer->getEmail();

        return ($testcustomer == $email) ? true : false;
    }

    /**
     * @param float $amount total order amount
     * @param string $fiat fiat currency of amount specified
     * @param bool $show_testnet add test networks to result array
     * @return array array of coins to display in currency selector
     * @internal
     */
    protected function getCoins($amount, $fiat, $show_testnet)
    {
        if (!$this->settings) {
            return;
        }
        $account = $this->settings->account;
        $factor = $this->settings->factor;
        $currencies = $this->settings->currencies;
        $show_in_major = $this->settings->show_in_major;
        $show_with_fee = $this->settings->show_with_fee;
        $show_in_fiat = $this->settings->show_in_fiat;

        $coins_aliases = [];
        $currencies_to_estimate = [];
        foreach ($this->settings->coins as $coin) {
            if ($show_testnet || !$coin->test) {
                $abbr = $coin->abbr;
                $currencies_to_estimate[] = $abbr;
                $coins_aliases[$abbr] = $coin->alias;
            }
        }
        if (!count($currencies_to_estimate)) {
            return;
        }
        // $estimations = Utils::estimate($account, $amount * $factor, $fiat, $currencies_to_estimate);
        // TODO: remove mocked data below after test
        $estimations = json_decode('[
            {
                "currency": "tbtc",
                "fiat": "usd",
                "amount": "100",
                "min": "89163",
                "cur": "0.00089163",
                "with-fee-amount": "121.53",
                "with-fee-min": "108364",
                "with-fee-cur": "0.00108364"
            },
            {
                "currency": "usdt@trx",
                "fiat": "usd",
                "amount": "100",
                "min": "89163",
                "cur": "0.00089163",
                "with-fee-amount": "121.53",
                "with-fee-min": "108364",
                "with-fee-cur": "0.00108364"
            }
        ]');

        $coins = [];
        foreach ($estimations as $estimation) {
            $abbr = $estimation->currency;

            $result_amount = $show_with_fee
                ? ($show_in_fiat
                    ? $estimation->{'with-fee-amount'}
                    : ($show_in_major
                        ? $estimation->{'with-fee-cur'}
                        : $estimation->{'with-fee-min'})
                )
                : ($show_in_fiat
                    ? $estimation->amount
                    : ($show_in_major
                        ? $estimation->cur
                        : $estimation->min)
                );
            if (!$result_amount) {
                continue;
            }
            $coins[] = $coin = new stdClass();
            $coin->abbr = $currencies[$abbr]->abbr;
            $coin->network = $currencies[$abbr]->network;
            $coin->token = $currencies[$abbr]->token;
            $coin->alias = $show_in_fiat ? $fiat : $currencies[$abbr]->alias;
            $coin->amount = $result_amount;
        }
        return $coins;
    }

    /**
     * @param float $amount total order amount
     * @param string $fiat fiat currency of amount specified
     * @param string $currency coin currency abbreviation
     * @return float coin amount
     * @internal
     */
    protected function getCoinAmountMinor($amount, $fiat, $currency)
    {
        if (!$this->settings) {
            return;
        }
        $account = $this->settings->account;
        $factor = $this->settings->factor;
        $show_with_fee = $this->settings->show_with_fee;

        // $estimations = Utils::estimate($account, $amount * $factor, $fiat, $currency);
        // TODO: remove mocked data below after test
        $estimations = json_decode('[
            {
                "currency": "tbtc",
                "fiat": "usd",
                "amount": "100",
                "min": "89163",
                "cur": "0.00089163",
                "with-fee-amount": "121.53",
                "with-fee-min": "108364",
                "with-fee-cur": "0.00108364"
            }
        ]');
        if (empty($estimations)) {
            return;
        }
        return $estimations[0]->{$show_with_fee ? 'with-fee-min' : 'min'};
    }

    public function confirm()
    {
        $this->load->model('checkout/order');
        $this->load->language('extension/payment/apirone_mccp');
        $this->load->model('extension/payment/apirone_mccp');

        $currency = (isset($this->request->get['currency'])) ? (string) $this->request->get['currency'] : '';
        $order_key = (isset($this->request->get['key'])) ? (string) $this->request->get['key'] : '';
        $order_id = (isset($this->request->get['order'])) ? (int) $this->request->get['order'] : 0;

        $order = $this->model_checkout_order->getOrder($order_id);
        try {
            $this->getSettings();
        }
        catch (\Throwable $e) {
            $this->log->write($e->getMessage());
        }
        // Exit if $order_key is !valid
        if (md5($this->settings->secret . $order['total']) != $order_key) {
            return;
        }
        // $currencyInfo = Apirone::getCurrency($currency);
        // TODO: what for this currencyInfo?
        // $currencyInfo = $this->settings->currencies[$currency];

        // Is order invoice already exists
        // $orderInvoice = $this->model_extension_payment_apirone_mccp->getInvoiceByOrderId($order_id);
        // if ($orderInvoice) {
        //     // Update invoice when page loaded or reloaded & status != 0 (expired || completed)
        //     if (Payment::invoiceStatus($orderInvoice) != 0) {
        //         $invoice_data = Apirone::invoiceInfoPublic($orderInvoice->invoice);
        //         if ($invoice_data) {
        //             $invoiceUpdated = $this->model_extension_payment_apirone_mccp->updateInvoice($orderInvoice->order_id, $invoice_data);
        //             $orderInvoice = ($invoiceUpdated) ? $invoiceUpdated : $orderInvoice;
        //         }
        //     }
        //     $this->showInvoice($orderInvoice, $currencyInfo);
        //     return;
        // }
        Invoice::settings($this->settings);
        Invoice::db($this->db->query, DB_PREFIX);

        $orderInvoices = Invoice::getByOrder($order_id);
        if (count($orderInvoices)) {
            $this->showInvoice($orderInvoices[0]);
            return;
        }
        // $factor = (float) $this->config->get('apirone_mccp_factor');
        // $totalCrypto = Payment::fiat2crypto($order['total'] * $order['currency_value'] * $factor, $order['currency_code'], $currency);
        // $amount = (int) Payment::cur2min($totalCrypto, $currencyInfo->{'units-factor'});
        $amount = $this->getCoinAmountMinor(
            $order['total'] * $order['currency_value'],
            $order['currency_code'],
            $currency
        );
        // TODO: what for this callback?
        // $invoiceSecret = md5($this->settings->secret . $order_id);
        // $callback = $this->url->link('extension/payment/apirone_mccp/callback&id=' . $invoiceSecret);

        // $created = Apirone::invoiceCreate(
        //     unserialize($this->config->get('apirone_mccp_account')),
        //     Payment::makeInvoiceData($currency, $amount, $lifetime, $callback, $order['total'], $order['currency_code'])
        // );
        $created = Invoice::init($currency, $amount)
            ->order($order_id)
            ->lifetime($this->settings->timeout)
            ->userData(); // TODO: how to create user data?

        if($created) {
            // TODO: replace updateInvoice to Invoice:::???
            $this->model_extension_payment_apirone_mccp->updateInvoice($order_id, $created);
            $this->showInvoice($created, true);

            return;
        }
        $this->response->redirect($this->url->link('checkout/cart'));
    }

    // TODO: what for this callback?
    public function callback()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/apirone_mccp');
        $params = false;

        $data = file_get_contents('php://input');
        if($data) {
            $params = json_decode($data);
        }

        if (!$params) {
            http_response_code(400);
            $message = "Data not received";
            $this->response->setOutput($message);
            LoggerWrapper::callbackError($message);

            return;
        }
        if (!property_exists($params, 'invoice') || !property_exists($params, 'status')) {
            http_response_code(400);
            $message = "Wrong params received: " . json_encode($params);
            $this->response->setOutput($message);
            LoggerWrapper::callbackError($message);

            return;        
        }


        $callback_secret = (isset($this->request->get['id'])) ? (string) $this->request->get['id'] : '';

        $invoice = $this->model_extension_payment_apirone_mccp->getInvoiceById($params->invoice);
        
        if (!$invoice) {
            http_response_code(404);
            $message = "Invoice not found: " . $params->invoice;
            $this->response->setOutput($message);
            LoggerWrapper::callbackError($message);

            return;
        }

        if (md5($this->settings->secret . $invoice->order_id) != $callback_secret) {
            http_response_code(403);
            $message = "Secret not valid: " . $callback_secret;
            $this->response->setOutput($message);
            LoggerWrapper::callbackError($message);

            return;
        }

        $invoiceUpdated = Apirone::invoiceInfoPublic($invoice->invoice);

        if($invoiceUpdated) {
            $this->model_extension_payment_apirone_mccp->updateInvoice($invoice->order_id, $invoiceUpdated);
        }

        LoggerWrapper::callbackDebug('', $params);
    }
    
    protected function showInvoice($clear_cart = false)
    {
        if ($clear_cart) {
            $this->cart->clear();    
        }
        // TODO: redirect to invoice Vue application
    }
    
    protected function invoice()
    {
        // TODO: show invoice view with new Vue application

        $this->response->setOutput($this->load->view('extension/payment/apirone/apirone_mccp_invoice', $data));
    }
    
    /**
     * API proxy endpoint to get currencies with OPTIONS method
     * @api
     */
    public function wallets()
    {
        // https://examples.test/opencart2/index.php?route=extension/payment/apirone_mccp/wallets

        if ($this->request->server['REQUEST_METHOD'] != 'OPTIONS') {
            http_response_code(405);
            return;
        }
        try {
            $this->response->setOutput(json_encode(
                Request::options('v2/wallets')));
        }
        catch (Exception $e) {
            $this->response->setOutput($e->getCode().' '.$e->getMessage());
        }
    }

    protected function getPathFromRequestUri()
    {
        $path_parts = explode('?', $this->request->server['REQUEST_URI'], 2);
        if (!count($path_parts)) {
            return;
        }
        $path_parts = explode('#', $path_parts[0], 2);
        if (!count($path_parts)) {
            return;
        }
        return $path_parts[0];
    }

    protected function getLastSegmentFromRequestUri()
    {
        // $path_segments = explode('/', $this->getPathFromRequestUri(), 10);
        $path_segments = explode('/', $this->request->get['route'], 10);
        $path_segments_count = count($path_segments);
        if (!$path_segments_count) {
            return;
        }
        $path_last_segment = $path_segments[$path_segments_count - 1];
        if ($path_last_segment) {
            return $path_last_segment;
        }
        if ($path_segments_count < 2) {
            return;
        }
        return $path_segments[$path_segments_count - 2];
    }
    
    /**
     * API proxy endpoint to get invoice data with invoice ID in path
     * @api
     */
    public function invoices()
    {
        // https://examples.test/opencart2/index.php?route=extension/payment/apirone_mccp/invoices/{INVOICE_ID}

        $request_server = $this->request->server;
        if ($request_server['REQUEST_METHOD'] != 'GET' || !$request_server['HTTPS']) {
            http_response_code(405);
            return;
        }
        $last_path_segment = $this->getLastSegmentFromRequestUri();
        if (!$last_path_segment) {
            http_response_code(400);
            return;
        }
        try {
            $this->response->setOutput(json_encode(
                Request::get(sprintf('v2/invoices/%s', $last_path_segment))));
        }
        catch (Exception $e) {
            $this->response->setOutput($e->getCode().' '.$e->getMessage());
        }
    }
}

function pa($mixed, $title = false)
{
    if ($title) {
        echo $title . ':';
    }
    echo '<pre>';
    if (gettype($mixed) == 'boolean') {
        print_r($mixed ? 'true' : 'false');
    }
    else {
        print_r(!is_null($mixed) ? $mixed : 'NULL');
    }
    echo '</pre>';
}
