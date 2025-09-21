<?php

use Apirone\API\Exceptions\RuntimeException;
use Apirone\API\Exceptions\ValidationFailedException;
use Apirone\API\Exceptions\UnauthorizedException;
use Apirone\API\Exceptions\ForbiddenException;
use Apirone\API\Exceptions\NotFoundException;
use Apirone\API\Exceptions\MethodNotAllowedException;
use Apirone\API\Http\Request;
use Apirone\API\Log\LogLevel;

use Apirone\SDK\Invoice;
use Apirone\SDK\Model\Settings;
use Apirone\SDK\Model\UserData;

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

            $logHandler = function($log_level, $message, $context = null) use ($openCartLogger) {
                if ($log_level == LogLevel::ERROR || $this->isDebug()) {
                    $openCartLogger->write($message . (!isset($context) ? '' : ' CONTEXT: '. json_encode($context)));
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
        catch (Exception $e) {
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
     * @param string $currency coin crypto currency abbreviation
     * @return float coin amount in crypto currency
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
                "amount": "9.8",
                "min": "8916",
                "cur": "0.00008916",
                "with-fee-amount": "12.15",
                "with-fee-min": "10836",
                "with-fee-cur": "0.00010836"
            }
        ]');
        if (empty($estimations)) {
            return;
        }
        return $estimations[0]->{$show_with_fee ? 'with-fee-min' : 'min'};
    }

    protected function getDBHandler()
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
                $this->log->write($e->getMessage());
                return null;
            }
        };
    }

    public function confirm()
    {
        $this->load->model('checkout/order');
        $this->load->language('extension/payment/apirone_mccp');
        $this->load->model('extension/payment/apirone_mccp');

        $currency_crypto = isset($this->request->get['currency']) ? (string) $this->request->get['currency'] : '';
        $order_key = isset($this->request->get['key']) ? (string) $this->request->get['key'] : '';
        $order_id = isset($this->request->get['order']) ? (int) $this->request->get['order'] : 0;

        $order = $this->model_checkout_order->getOrder($order_id);
        try {
            $this->getSettings();
        }
        catch (Exception $e) {
            $this->log->write($e->getMessage());
            return;
        }
        // Exit if $order_key is !valid
        if (md5($this->settings->secret . $order['total']) != $order_key) {
            return;
        }
        Invoice::settings($this->settings);
        Invoice::db($this->getDBHandler(), DB_PREFIX);

        // Is order invoice already exists
        $orderInvoices = Invoice::getByOrder($order_id);
        if (count($orderInvoices)) {
            $invoice = $orderInvoices[0];
            // Update invoice when page loaded or reloaded & status != (expired || completed)
            $invoice->update();
            $this->model_extension_payment_apirone_mccp->updateOrderStatus($invoice);
            if ($invoice->status !== 'expired' && $invoice->details->currency == $currency_crypto) {
                $this->showInvoice($invoice->invoice);
                return;
            }
        }
        // Create new invoice
        $currency_fiat = $order['currency_code'];
        $amount_fiat = $order['total'] * $order['currency_value'];

        // TODO: replace with new Utils::estimate()
        $amount_crypto = $this->getCoinAmountMinor(
            $amount_fiat * $this->settings->factor,
            $currency_fiat,
            $currency_crypto
        );

        $merchant = $this->settings->merchant;
        if (!$merchant) {
            $merchant = $order['store_name'];
        }
        $merchant_url = $order['store_url'];

        $userData = UserData::init()
            ->merchant($merchant)
            ->url($merchant_url)
            ->price($amount_fiat . ' ' . strtoupper($currency_fiat));

        $callback_path = 'extension/payment/apirone_mccp/';
        try {
            $invoice = Invoice::init($currency_crypto, $amount_crypto)
                ->order($order_id)
                ->userData($userData)
                ->lifetime($this->settings->timeout)
                // TODO: add estimation
                ->callbackUrl($this->url->link($callback_path.'callback&key='.md5($this->settings->secret . $order_id)))
                ->linkback($this->url->link($callback_path.'linkback&key='.md5($this->settings->secret . $amount_crypto) .'&order='.$order_id))
                ->create();
            $this->model_extension_payment_apirone_mccp->updateOrderStatus($invoice);

            $this->cart->clear();    

            $this->showInvoice($invoice->invoice);
        }
        catch (Exception $e) {
            $this->log->write($e->getMessage());
            $this->response->redirect($this->url->link('checkout/cart'));
        }
    }
    
    protected function showInvoice($invoice)
    {
        $this->response->redirect($this->url->link('extension/payment/apirone_mccp/invoice&id=' . $invoice));
    }

    public function invoice()
    {
        // TODO: read Settings, get "logo" and pass it to template as "embed" prop in window.apirone_config
        $this->response->setOutput($this->load->view('extension/payment/apirone/apirone_mccp_invoice'));
    }

    // to test callback on local server
    // curl -k -w "%{http_code}\n" -X POST -d '{"invoice":"INVOICE_ID_HERE","status":"expired"}' 'https://examples.test/opencart2/index.php?route=extension/payment/apirone_mccp/callback&key=CALLBACK_KEY_HERE'
    /**
     * Callback URI for change invoice and order status
     * @api
     */
    public function callback()
    {
        $params = false;

        $data = file_get_contents('php://input');
        if($data) {
            $params = json_decode($data);
        }
        if (!$params) {
            http_response_code(400);
            $this->response->setOutput('Data not received');
            return;
        }
        $invoice_id = property_exists($params, 'invoice') ? (string) $params->invoice : '';
        $status = property_exists($params, 'status') ? (string) $params->status : '';
        $callback_key = isset($this->request->get['key']) ? (string) $this->request->get['key'] : '';

        if (!($invoice_id && $status && $callback_key)) {
            $message = 'Wrong params received';
            $this->log->write($message
                .': invoice:'.$invoice_id
                .', status:'.$status
                .', key:'.$callback_key
            );
            http_response_code(400);
            $this->response->setOutput($message);
            return;        
        }
        Invoice::db($this->getDBHandler(), DB_PREFIX);
        $invoice = Invoice::get($invoice_id);
        // Is invoice exists
        if (empty($invoice)) {
            $message = 'Invoice not found';
            $this->log->write($message . ': '.$invoice_id);
            http_response_code(404);
            $this->response->setOutput($message);
            return;
        }
        try {
            $this->getSettings();
        }
        catch (Exception $e) {
            $this->log->write($e->getMessage());
            return;
        }
        // Exit if callback key is !valid
        if (md5($this->settings->secret . $invoice->order) != $callback_key) {
            $message = 'Key not valid';
            $this->log->write($message
                .': key:'.$callback_key
                .', invoice:'.$invoice_id
            );
            http_response_code(403);
            return;
        }
        try {
            $this->getSettings();
        }
        catch (Exception $e) {
            $this->log->write($e->getMessage());
            http_response_code(500);
            $this->response->setOutput('Can not get settings');
            return;
        }
        Invoice::settings($this->settings);

        if ($invoice->update()) {
            // Update order if invoice was changed
            $this->load->model('extension/payment/apirone_mccp');
            $this->model_extension_payment_apirone_mccp->updateOrderStatus($invoice);
        };
    }

    /**
     * Callback URI for return to shop when order was paid
     * @api
     */
    public function linkback()
    {
        $this->load->model('extension/payment/apirone_mccp');

        $invoice_key = isset($this->request->get['key']) ? (string) $this->request->get['key'] : '';
        $order_id = isset($this->request->get['order']) ? (int) $this->request->get['order'] : 0;

        if (!($invoice_key && $order_id)) {
            $message = 'Wrong params received';
            $this->log->write($message .': key:'.$invoice_key .', order:'.$order_id);
            http_response_code(400);
            $this->response->setOutput($message);
            return;        
        }
        Invoice::db($this->getDBHandler(), DB_PREFIX);
        $orderInvoices = Invoice::getByOrder($order_id);
        // Is order invoice exists
        if (!count($orderInvoices)) {
            $message = 'Order not found' ;
            $this->log->write($message . ': '.$order_id);
            http_response_code(404);
            $this->response->setOutput($message);
            return;
        }
        $invoice = $orderInvoices[0];
        try {
            $this->getSettings();
        }
        catch (Exception $e) {
            $this->log->write($e->getMessage());
            http_response_code(500);
            $this->response->setOutput('Can not get settings');
            return;
        }
        // Exit if $invoice_key is !valid
        if (md5($this->settings->secret . $invoice->details->amount) != $invoice_key) {
            $message = 'Key not valid';
            $this->log->write($message .': key:'.$invoice_key .', invoice:'.$invoice->invoice);
            http_response_code(403);
            return;
        }
        $this->response->redirect($this->url->link('checkout/success'));
    }

    /**
     * API proxy
     * @api
     */
    public function getFromAPI($method, $path_suffix)
    {
        $request_server = $this->request->server;
        if (strtolower($request_server['REQUEST_METHOD']) != strtolower($method)
            || !$request_server['HTTPS']
        ) {
            $message = 'Method or protocol not allowed';
            $this->log->write($message);
            http_response_code(405);
            $this->response->setOutput($message);
            return;
        }
        try {
            $this->response->setOutput(
                Request::execute($method, 'v2/'.$path_suffix)->body);
        }
        catch (Exception $e) {
            $message = $e->getMessage();
            $this->log->write($message);
            http_response_code($e->getCode());
            $this->response->setOutput($message);
        }
    }

    /**
     * API proxy endpoint to get currencies with OPTIONS method
     * @api
     */
    public function wallets()
    {
        // OPTIONS https://examples.test/opencart2/index.php?route=extension/payment/apirone_mccp/wallets
        $this->getFromAPI('options', 'wallets');
        // TODO: we can also cache this info for any time to reduce calls to Apirone API
    }

    protected function getLastSegmentFromRequestUri()
    {
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
        // GET https://examples.test/opencart2/index.php?route=extension/payment/apirone_mccp/invoices/{INVOICE_ID}

        $invoice_id = $this->getLastSegmentFromRequestUri();
        if (!$invoice_id) {
            $message = 'Invoice id not specified';
            $this->log->write($message);
            http_response_code(400);
            $this->response->setOutput($message);
            return;
        }
        Invoice::db($this->getDBHandler(), DB_PREFIX);
        $invoice = Invoice::get($invoice_id);
        // Is invoice exists
        if (empty($invoice)) {
            $message = 'Invoice not found';
            $this->log->write($message . ': '.$invoice_id);
            http_response_code(404);
            $this->response->setOutput($message);
            return;
        }
        $this->response->setOutput(json_encode($invoice->info()));
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
