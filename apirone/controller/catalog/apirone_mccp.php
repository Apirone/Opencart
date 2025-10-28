<?php

namespace Apirone\Payment\Controller\Catalog;

require_once(((int) explode('.', VERSION, 2)[0] < 4 ? DIR_SYSTEM . 'library/apirone/' : DIR_EXTENSION . 'apirone/system/library/') . 'apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'controller/apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'vendor/autoload.php');

use \Apirone\API\Http\Request;

use \Apirone\SDK\Invoice;
use \Apirone\SDK\Model\UserData;
use \Apirone\SDK\Service\Utils;

class ControllerExtensionPaymentApironeMccpCatalog extends \Apirone\Payment\Controller\ControllerExtensionPaymentApironeMccpCommon
{
    /**
     * @param float $amount total order amount
     * @param string $fiat fiat currency of amount specified
     * @return array array of coins to display in currency selector
     */
    protected function getCoins(float $amount, string $fiat): ?array
    {
        $coins = $this->model->getCoinsAvailable();
        if (empty($coins) || !$this->settings->with_fee) {
            return $coins;
        }
        $currencies = [];
        foreach ($coins as $coin) {
            $currencies[] = $coin->abbr;
        }
        try {
            $estimations = Utils::estimate(
                $this->settings->account,
                $amount,
                $fiat,
                $currencies,
                true,
                $this->settings->factor,
            );
        } catch (\Exception $e) {
            $this->model->logError('Can not get estimations for currency selector: '.$e->getMessage());
            return null;
        }
        $coins_all = $this->settings->coins;
        $coins = [];
        foreach ($estimations as $estimation) {
            if (!(property_exists($estimation, 'min') && $estimation->min)) {
                continue;
            }
            // $coins[] = $coin = $coins_all[$estimation->currency]->toStd();
            $coins[] = $coin = $this->model->splitCoinAbbr($coins_all[$estimation->currency]);
            $coin->with_fee = sprintf($this->language->get('currency_selector_with_fee'), $amount + $estimation->fee, $fiat);
        }
        return $coins;
    }

    /**
     * @param float $amount total order amount
     * @param string $fiat fiat currency of amount specified
     * @param string $currency coin crypto currency abbreviation
     * @return ?\stdClass estimation in crypto currency and fee in fiat if with_fee setting is set
     */
    protected function getEstimation(float $amount, string $fiat, string $currency): ?\stdClass
    {
        try {
            $estimations = Utils::estimate(
                $this->settings->account,
                $amount,
                $fiat,
                $currency,
                true,
                $this->settings->factor,
            );
        } catch (\Exception $e) {
            $this->model->logError('Can not get estimation for invoice: '.$e->getMessage());
            return null;
        }
        if (empty($estimations)) {
            $this->model->logError('No estimation for '.$currency);
            return null;
        }
        $estimation = $estimations[0];
        if (!(property_exists($estimation, 'min') && $estimation->min)) {
            $this->model->logError('Invalid estimation for '.$currency);
            return null;
        }
        return $estimation;
    }

    protected function backToCart(): void
    {
        $this->response->redirect($this->url->link('checkout/cart'));
    }

    /**
     * Payment confirmation handler\
     * Creates new invoice or updates existing for order
     * OpenCart required
     */
    public function confirm(): void
    {
        $this->settings = $this->model->getSettings();
        if (!$this->settings) {
            $this->backToCart();
            return;
        }
        $currency_crypto = isset($this->request->get['currency']) ? (string) $this->request->get['currency'] : '';
        $order_key = isset($this->request->get['key']) ? (string) $this->request->get['key'] : '';
        $order_id = isset($this->request->get['order']) ? (int) $this->request->get['order'] : 0;

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($order_id);

        if ($this->model->hashInvalid($order['total'], $order_key)) {
            $message = 'Key not valid';
            $this->model->logInfo($message
                .': key:'.$order_key
                .', order:'.$order_id
            );
            $this->backToCart();
            return;
        }
        Invoice::settings($this->settings);
        $this->model->initInvoiceModel();
        // Is order invoice already exists
        $orderInvoices = Invoice::getByOrder($order_id);
        if (count($orderInvoices)) {
            $invoice = $orderInvoices[0];
            // Update invoice when page loaded or reloaded & status != (expired || completed)
            $invoice->update();
            $this->model->updateOrderStatus($invoice);
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
            $this->backToCart();
            return;
        }
        $amount_crypto = $estimation->min;

        $userData = UserData::init()
            ->merchant($this->settings->merchant ?: $order['store_name'])
            ->url($order['store_url'])
            ->price($amount_fiat.' '.strtoupper($currency_fiat));

        try {
            $invoice = Invoice::init($currency_crypto, $amount_crypto)
                ->order($order_id)
                ->estimation($estimation)
                ->userData($userData)
                ->lifetime($this->settings->timeout)
                ->callbackUrl($this->url->link(PATH_TO_RESOURCES . '/callback&key='.$this->model->getHash($order_id)))
                ->linkback($this->url->link(PATH_TO_RESOURCES . '/linkback&key='.$this->model->getHash($amount_crypto) .'&order='.$order_id))
                ->create();

            $this->model->updateOrderStatus($invoice);
            $this->cart->clear();
            $this->showInvoice($invoice->invoice);
        }
        catch (\Exception $e) {
            $this->model->logError($e->getMessage());
            $this->backToCart();
        }
    }

    protected function showInvoice(string $invoice): void
    {
        $this->response->redirect($this->url->link(PATH_TO_RESOURCES . '/invoice&id=' . $invoice));
    }

    public function invoice(): void
    {
        $this->settings = $this->model->getSettings();
        if (!$this->settings) {
            Utils::sendJson('Can not get settings', 500);
            return;
        }
        $data['apirone_config'] = $this->settings->logo ? '' : 'embed: true,';

        $this->response->setOutput($this->load->view(PATH_TO_VIEWS . '_invoice', $data));
    }

    // to test callback on local server
    // curl -k -w "%{http_code}\n" -X POST -d '{"invoice":"INVOICE_ID_HERE","status":"expired"}' 'https://examples.test/opencart2/index.php?route=extension/payment/apirone_mccp/callback&key=CALLBACK_KEY_HERE'
    /**
     * Callback URI for change invoice and order status
     */
    public function callback(): void
    {
        $this->settings = $this->model->getSettings();
        if (!$this->settings) {
            Utils::sendJson('Can not get settings', 500);
            return;
        }
        $data = file_get_contents('php://input');
        $params = $data ? json_decode(Utils::sanitize($data)) : false;
        if (!$params) {
            $message = 'Data not received';
            $this->model->logInfo($message);
            Utils::sendJson($message, 400);
            return;
        }
        $invoice_id = property_exists($params, 'invoice') ? (string) $params->invoice : '';
        $status = property_exists($params, 'status') ? (string) $params->status : '';
        $callback_key = key_exists('key', $this->request->get) ? (string) $this->request->get['key'] : '';

        if (!($invoice_id && $status && $callback_key)) {
            $message = 'Wrong params received';
            $this->model->logInfo($message
                .': invoice:'.$invoice_id
                .', status:'.$status
                .', key:'.$callback_key
            );
            Utils::sendJson($message, 400);
            return;
        }
        $this->model->initInvoiceModel();
        $invoice = Invoice::get($invoice_id);
        // Is invoice exists
        if (!(property_exists($invoice, 'invoice') && $invoice->invoice == $invoice_id)) {
            $message = 'Invoice not found';
            $this->model->logInfo($message . ': '.$invoice_id);
            Utils::sendJson($message, 404);
            return;
        }
        // Exit if callback key is !valid
        if ($this->model->hashInvalid($invoice->order, $callback_key)) {
            $message = 'Key not valid';
            $this->model->logInfo($message
                .': key:'.$callback_key
                .', invoice:'.$invoice_id
            );
            Utils::sendJson($message, 403);
            return;
        }
        Invoice::settings($this->settings);

        if ($invoice->update()) {
            // Update order if invoice was changed
            $this->load->model(PATH_TO_RESOURCES);
            $this->model->updateOrderStatus($invoice);
        };
    }

    /**
     * Callback URI for return to shop when order was paid
     */
    public function linkback(): void
    {
        if (!$this->model->getSettings()) {
            Utils::sendJson('Can not get settings', 500);
            return;
        }
        $invoice_key = key_exists('key', $this->request->get) ? (string) $this->request->get['key'] : '';
        $order_id = key_exists('order', $this->request->get) ? (int) $this->request->get['order'] : 0;

        if (!($invoice_key && $order_id)) {
            $message = 'Wrong params received';
            $this->model->logInfo($message
                .': key:'.$invoice_key
                .', order:'.$order_id
            );
            Utils::sendJson($message, 400);
            return;
        }
        $this->model->initInvoiceModel();
        $orderInvoices = Invoice::getByOrder($order_id);
        if (!count($orderInvoices)) {
            $message = 'Order not found' ;
            $this->model->logInfo($message . ': '.$order_id);
            Utils::sendJson($message, 404);
            return;
        }
        $invoice = $orderInvoices[0];
        if ($this->model->hashInvalid($invoice->details->amount, $invoice_key)) {
            $message = 'Key not valid';
            $this->model->logInfo($message
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
     */
    public function getFromAPI($method, $path_suffix): void
    {
        $request_server = $this->request->server;
        if (strtolower($request_server['REQUEST_METHOD']) != strtolower($method)
            || !$request_server['HTTPS']
        ) {
            $message = 'Method or protocol not allowed';
            $this->model->logInfo($message);
            Utils::sendJson($message, 405);
            return;
        }
        try {
            $response = Request::execute($method, 'v2/'.$path_suffix);
            header('Content-Type: application/json');
            echo $response->body;
        }
        catch (\Exception $e) {
            $message = $e->getMessage();
            $this->model->logInfo($message);
            Utils::sendJson($message, $e->getCode());
        }
    }

    /**
     * API proxy endpoint for invoice app to get currencies
     */
    public function wallets(): void
    {
        // OPTIONS https://examples.test/opencart2/index.php?route=extension/payment/apirone_mccp/wallets
        $this->getFromAPI('options', 'wallets');
        // TODO: we can also cache this info until any expiration time to reduce calls to Apirone API
    }

    protected function getLastSegmentFromRequestUri(): string
    {
        $route = key_exists('route', $this->request->get) ? (string) $this->request->get['route'] : '';
        if (!$route) {
            return '';
        }
        $path_segments = explode('/', $route, 10);
        $path_segments_count = count($path_segments);
        if (!$path_segments_count) {
            return '';
        }
        $path_last_segment = $path_segments[$path_segments_count - 1];
        if ($path_last_segment) {
            return $path_last_segment;
        }
        if ($path_segments_count < 2) {
            return '';
        }
        return $path_segments[$path_segments_count - 2];
    }

    /**
     * API proxy endpoint to get invoice data with invoice ID in path
     */
    public function invoices(): void
    {
        // GET https://examples.test/opencart2/index.php?route=extension/payment/apirone_mccp/invoices/{INVOICE_ID}

        // settings not need, but update need
        $this->model->update();

        $invoice_id = $this->getLastSegmentFromRequestUri();
        if (!$invoice_id) {
            $message = 'Invoice id not specified';
            $this->model->logInfo($message);
            Utils::sendJson($message, 400);
            return;
        }
        $this->model->initInvoiceModel();
        $invoice = Invoice::get($invoice_id);
        if (!(property_exists($invoice, 'invoice') && $invoice->invoice == $invoice_id)) {
            $message = 'Invoice not found';
            $this->model->logInfo($message . ': '.$invoice_id);
            Utils::sendJson($message, 404);
            return;
        }
        Utils::sendJson($invoice->info());
    }
}
