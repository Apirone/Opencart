<?php

use Apirone\API\Exceptions\RuntimeException;
use Apirone\API\Exceptions\ValidationFailedException;
use Apirone\API\Exceptions\UnauthorizedException;
use Apirone\API\Exceptions\ForbiddenException;
use Apirone\API\Exceptions\NotFoundException;
use Apirone\API\Exceptions\MethodNotAllowedException;
use Apirone\API\Http\Request;
use Apirone\API\Log\LogLevel;
use Apirone\API\Log\LoggerWrapper;

use Apirone\SDK\Invoice;
use Apirone\SDK\Model\Settings;
use Apirone\SDK\Model\UserData;
use Apirone\SDK\Service\Utils;

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
     * @see also in admin/controller/extension/payment/apirone_mccp.php
     * @internal
     */
    protected function isDebug()
    {
        try {
            return !!$this->settings->debug;
        }
        catch (\Throwable $ignore) {
            return false;
        }
    }

    /**
     * Initializes logging
     * @since 2.0.0
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
        try {
            $this->getSettings();

            $this->load->model('checkout/order');
            $this->load->language('extension/payment/apirone_mccp');
            $this->load->model('extension/payment/apirone_mccp');

            $data = array_merge($data, $this->load->language('apirone_mccp'));

            $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

            $amount_fiat = $order['total'] * $order['currency_value'];
            $currency_fiat = $order['currency_code'];
            $data['coins'] = $this->getCoins($amount_fiat, $currency_fiat, $this->showTestnet());
            $data['order_id'] = $order['order_id'];
            $data['order_key'] = md5($this->settings->secret . $order['total']);
            $data['url_redirect'] = $this->url->link('extension/payment/apirone_mccp/confirm');
        }
        catch (Exception $e) {
            LoggerWrapper::error($e->getMessage());
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
        // try {
        //     $estimations = Utils::estimate(
        //         $this->settings->account,
        //         $amount,
        //         $this->settings->factor,
        //         $this->settings->with_fee,
        //         $fiat,
        //         $currencies_to_estimate
        //     );
        // } catch (Exception $e) {
        //     LoggerWrapper::error('Can not get estimations for currency selector');
        //     return;
        // }
        // TODO: remove mocked data below after test
        $estimations = json_decode('[
            {
                "currency": "bnb",
                "fiat": "usd",
                "amount": "100",
                "factor": "1.01",
                "fee": "2.5",
                "min": "107906072942994608",
                "cur": "0.10790607294299462"
            },
            {
                "currency": "tbtc",
                "fiat": "usd",
                "amount": "100",
                "factor": "1.01",
                "fee": "19.16",
                "min": "103511",
                "cur": "0.00103511"
            },
            {
                "currency": "tltc",
                "error": "Invalid destinations"
            }
        ]');
        $currencies = $this->settings->currencies;
        $coins = [];
        foreach ($estimations as $estimation) {
            $abbr = $estimation->currency;
            $result_amount = $estimation->min;
            if (!$result_amount) {
                continue;
            }
            $coins[] = $coin = new stdClass();
            $coin->abbr = $currencies[$abbr]->abbr;
            $coin->network = $currencies[$abbr]->network;
            $coin->token = $currencies[$abbr]->token;
            $coin->label = $estimation->fee
                ? sprintf($this->language->get('currency_selector_label_with_fee'), $currencies[$abbr]->alias, $estimation->fee, $fiat)
                : $currencies[$abbr]->alias;
        }
        return $coins;
    }

    /**
     * @param float $amount total order amount
     * @param string $fiat fiat currency of amount specified
     * @param string $currency coin crypto currency abbreviation
     * @return stdClass estimation in crypto currency and fee in fiat if with_fee setting is set
     * @internal
     */
    protected function getEstimation($amount, $fiat, $currency)
    {
        if (!$this->settings) {
            return;
        }
        // try {
        //     $estimations = Utils::estimate(
        //         $this->settings->account,
        //         $amount,
        //         $this->settings->factor,
        //         $this->settings->with_fee,
        //         $fiat,
        //         $currency
        //     );
        // } catch (Exception $e) {
        //     LoggerWrapper::error('Can not get estimation for invoice');
        //     return;
        // }
        // TODO: remove mocked data below after test
        $estimations = json_decode('[
            {
                "currency": "tbtc",
                "fiat": "usd",
                "amount": "100",
                "factor": "1.01",
                "fee": "19.16",
                "min": "1351",
                "cur": "0.00001351"
            }
        ]');
        if (empty($estimations)) {
            return;
        }
        $estimation = $estimations[0];
        if (!(property_exists($estimation, 'min') && $estimation->min)) {
            return;
        }
        return $estimation;
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
                LoggerWrapper::error($e->getMessage());
                return null;
            }
        };
    }

    public function confirm()
    {
        try {
            $this->getSettings();
        }
        catch (Exception $e) {
            LoggerWrapper::error($e->getMessage());
            return;
        }
        $this->load->model('checkout/order');
        $this->load->language('extension/payment/apirone_mccp');
        $this->load->model('extension/payment/apirone_mccp');

        $currency_crypto = isset($this->request->get['currency']) ? (string) $this->request->get['currency'] : '';
        $order_key = isset($this->request->get['key']) ? (string) $this->request->get['key'] : '';
        $order_id = isset($this->request->get['order']) ? (int) $this->request->get['order'] : 0;

        $order = $this->model_checkout_order->getOrder($order_id);
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

        $estimation = $this->getEstimation($amount_fiat, $currency_fiat, $currency_crypto);
        if (!$estimation) {
            $this->response->redirect($this->url->link('checkout/cart'));
            return;
        }
        $amount_crypto = $estimation->min;

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
                // TODO:
                // ->estimation($estimation)
                ->userData($userData)
                ->lifetime($this->settings->timeout)
                ->callbackUrl($this->url->link($callback_path.'callback&key='.md5($this->settings->secret . $order_id)))
                ->linkback($this->url->link($callback_path.'linkback&key='.md5($this->settings->secret . $amount_crypto) .'&order='.$order_id))
                ->create();

            // $invoice->estimation($estimation);
            // $invoice->save();

            $this->model_extension_payment_apirone_mccp->updateOrderStatus($invoice);

            $this->cart->clear();

            $this->showInvoice($invoice->invoice);
        }
        catch (Exception $e) {
            LoggerWrapper::error($e->getMessage());
            $this->response->redirect($this->url->link('checkout/cart'));
        }
    }
    
    protected function showInvoice($invoice)
    {
        $this->response->redirect($this->url->link('extension/payment/apirone_mccp/invoice&id=' . $invoice));
    }

    public function invoice()
    {
        try {
            $this->getSettings();
            $data['apirone_config'] = $this->settings->logo ? '' : 'embed: true,';
        }
        catch (Exception $e) {
            LoggerWrapper::error($e->getMessage());
            $data['apirone_config'] = '';
        }
        // TODO: read Settings, get "logo" and pass it to template as "embed" prop in window.apirone_config
        $this->response->setOutput($this->load->view('extension/payment/apirone/apirone_mccp_invoice', $data));
    }

    // to test callback on local server
    // curl -k -w "%{http_code}\n" -X POST -d '{"invoice":"INVOICE_ID_HERE","status":"expired"}' 'https://examples.test/opencart2/index.php?route=extension/payment/apirone_mccp/callback&key=CALLBACK_KEY_HERE'
    /**
     * Callback URI for change invoice and order status
     * @api
     */
    public function callback()
    {
        try {
            $this->getSettings();
        }
        catch (Exception $e) {
            LoggerWrapper::error($e->getMessage());
            Utils::sendJson('Can not get settings', 500);
            return;
        }
        $params = false;

        $data = file_get_contents('php://input');
        if($data) {
            $params = json_decode(Utils::sanitize($data));
        }
        if (!$params) {
            $message = 'Data not received';
            LoggerWrapper::info($message);
            Utils::sendJson($message, 400);
            return;
        }
        $invoice_id = property_exists($params, 'invoice') ? (string) $params->invoice : '';
        $status = property_exists($params, 'status') ? (string) $params->status : '';
        $callback_key = isset($this->request->get['key']) ? (string) $this->request->get['key'] : '';

        if (!($invoice_id && $status && $callback_key)) {
            $message = 'Wrong params received';
            LoggerWrapper::info($message
                .': invoice:'.$invoice_id
                .', status:'.$status
                .', key:'.$callback_key
            );
            Utils::sendJson($message, 400);
            return;        
        }
        Invoice::db($this->getDBHandler(), DB_PREFIX);
        $invoice = Invoice::get($invoice_id);
        // Is invoice exists
        if (!(property_exists($invoice, 'invoice') && $invoice->invoice == $invoice_id)) {
            $message = 'Invoice not found';
            LoggerWrapper::info($message . ': '.$invoice_id);
            Utils::sendJson($message, 404);
            return;
        }
        // Exit if callback key is !valid
        if (md5($this->settings->secret . $invoice->order) != $callback_key) {
            $message = 'Key not valid';
            LoggerWrapper::info($message
                .': key:'.$callback_key
                .', invoice:'.$invoice_id
            );
            Utils::sendJson($message, 403);
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
        try {
            $this->getSettings();
        }
        catch (Exception $e) {
            LoggerWrapper::error($e->getMessage());
            Utils::sendJson('Can not get settings', 500);
            return;
        }
        $this->load->model('extension/payment/apirone_mccp');

        $invoice_key = isset($this->request->get['key']) ? (string) $this->request->get['key'] : '';
        $order_id = isset($this->request->get['order']) ? (int) $this->request->get['order'] : 0;

        if (!($invoice_key && $order_id)) {
            $message = 'Wrong params received';
            LoggerWrapper::info($message
                .': key:'.$invoice_key
                .', order:'.$order_id
            );
            Utils::sendJson($message, 400);
            return;        
        }
        Invoice::db($this->getDBHandler(), DB_PREFIX);
        $orderInvoices = Invoice::getByOrder($order_id);
        // Is order invoice exists
        if (!count($orderInvoices)) {
            $message = 'Order not found' ;
            LoggerWrapper::info($message . ': '.$order_id);
            Utils::sendJson($message, 404);
            return;
        }
        $invoice = $orderInvoices[0];
        // Exit if $invoice_key is !valid
        if (md5($this->settings->secret . $invoice->details->amount) != $invoice_key) {
            $message = 'Key not valid';
            LoggerWrapper::info($message
                .': key:'.$invoice_key
                .', invoice:'.$invoice->invoice
            );
            Utils::sendJson($message, 403);
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
        try {
            $this->getSettings();
        }
        catch (Exception $e) {
            LoggerWrapper::error($e->getMessage());
            Utils::sendJson('Can not get settings', 500);
            return;
        }
        $request_server = $this->request->server;
        if (strtolower($request_server['REQUEST_METHOD']) != strtolower($method)
            || !$request_server['HTTPS']
        ) {
            $message = 'Method or protocol not allowed';
            LoggerWrapper::info($message);
            Utils::sendJson($message, 405);
            return;
        }
        try {
            $response = Request::execute($method, 'v2/'.$path_suffix);
            header('Content-Type: application/json');
            echo $response->body;
        }
        catch (Exception $e) {
            $message = $e->getMessage();
            LoggerWrapper::info($message);
            Utils::sendJson($message, $e->getCode());
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
        // TODO: we can also cache this info until any expiration time to reduce calls to Apirone API
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

        try {
            $this->getSettings();
        }
        catch (Exception $e) {
            LoggerWrapper::error($e->getMessage());
            Utils::sendJson('Can not get settings', 500);
            return;
        }
        $invoice_id = $this->getLastSegmentFromRequestUri();
        if (!$invoice_id) {
            $message = 'Invoice id not specified';
            LoggerWrapper::info($message);
            Utils::sendJson($message, 400);
            return;
        }
        Invoice::db($this->getDBHandler(), DB_PREFIX);
        $invoice = Invoice::get($invoice_id);
        // Is invoice exists
        if (!(property_exists($invoice, 'invoice') && $invoice->invoice == $invoice_id)) {
            $message = 'Invoice not found';
            LoggerWrapper::info($message . ': '.$invoice_id);
            Utils::sendJson($message, 404);
            return;
        }
        Utils::sendJson($invoice->info());
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
