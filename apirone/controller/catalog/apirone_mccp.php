<?php

namespace Apirone\Payment\Controller\Catalog;

require_once((version_compare(VERSION, 4, '<')
    ? DIR_SYSTEM . 'library/apirone/'
    : DIR_EXTENSION . 'apirone/system/library/'
) . 'apirone_mccp.php');

require_once(PATH_TO_LIBRARY . 'controller/apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'vendor/autoload.php');

use Apirone\SDK\Invoice;
use Apirone\SDK\Model\UserData;
use Apirone\SDK\Service\Api;
use Apirone\SDK\Service\Utils;

class ControllerExtensionPaymentApironeMccpCatalog extends \Apirone\Payment\Controller\ControllerExtensionPaymentApironeMccpCommon
{
    /**
     * @param float $amount total order amount
     * @param string $fiat fiat currency of amount specified
     * @return array associative array of coins (as stdClass) to display in currency selector with abbr as key
     */
    protected function getCoins(float $amount, string $fiat): ?array
    {
        $coins_available = $this->model->getCoinsAvailable();
        if (empty($coins_available) || !$this->settings->with_fee) {
            return $coins_available;
        }
        try {
            $estimations = Utils::estimate(
                $this->settings->account,
                $amount,
                $fiat,
                array_keys($coins_available),
                true,
                $this->settings->factor,
            );
        } catch (\Exception $ignore) {
            return null;
        }
        $coins = [];
        foreach ($estimations as $estimation) {
            if (!(
                property_exists($estimation, 'min') && $estimation->min
                && property_exists($estimation, 'currency')
            )) {
                continue;
            }
            $abbr = $estimation->currency;
            if (!($abbr && array_key_exists($abbr, $coins_available))) {
                continue;
            }
            $coins[$abbr] = $coin = $coins_available[$abbr];

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
        } catch (\Exception $ignore) {
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
     * Creates new invoice or updates existing for order\
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
        $this->model->initInvoiceModel();
        // Is order invoice already exists
        $orderInvoices = Invoice::getByOrder($order_id);
        if (count($orderInvoices)) {
            $invoice = $orderInvoices[0];
            // Update existing invoice when page is loaded or reloaded & status != expired & the same crypto currency
            $invoice->update(30);
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
            $invoice = Invoice::init($this->settings->account, $currency_crypto)
                ->amount($amount_crypto)
                ->order($order_id)
                ->estimation($estimation)
                ->userData($userData)
                ->lifetime($this->settings->timeout)
                ->callbackUrl($this->url->link(PATH_FOR_ROUTES . 'callback&key='.$this->model->getHash($order_id), '', true))
                ->linkback($this->url->link(PATH_FOR_ROUTES . 'linkback&key='.$this->model->getHash($amount_crypto) .'&order='.$order_id, '', true))
                ->create();

            $this->model->updateOrderStatus($invoice);
            unset($this->session->data['order_id']);
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
        $this->response->redirect($this->url->link(PATH_FOR_ROUTES . 'invoice&id=' . $invoice));
    }

    public function invoice(): void
    {
        $this->settings = $this->model->getSettings();
        if (!$this->settings) {
            Utils::sendJson('Can not get settings', 500);
            return;
        }
        $data['apirone_path_to_images'] = OC_MAJOR_VERSION < 4
            ? 'catalog/view/theme/default/image/apirone'
            : 'extension/apirone/catalog/view/image';

        $data['apirone_path_to_js'] = OC_MAJOR_VERSION < 4
            ? 'catalog/view/javascript/apirone/'
            : 'extension/apirone/catalog/view/javascript/';

        $data['apirone_path_to_css'] = OC_MAJOR_VERSION < 4
            ? 'catalog/view/theme/default/stylesheet/apirone/'
            : 'extension/apirone/catalog/view/stylesheet/';

        $data['apirone_path_for_routes'] = PATH_FOR_ROUTES;

        $data['invoice_app_config'] = sprintf('logo: %s,', $this->settings->logo ? 'true' : 'false');

        $this->response->setOutput($this->load->view(PATH_TO_VIEWS . '_invoice', $data));
    }

    /**
     * @return \Closure callback input checker
     */
    protected function getCallbackChecker(): \Closure
    {
        return function(Invoice $invoice): void
        {
            $this->settings = $this->model->getSettings();
            if (!$this->settings) {
                Utils::sendJson('Can not get settings', 500);
                exit;
            }
            $invoice_id = $invoice->invoice;
            $order_id = $invoice->order;
            $status = $invoice->status;

            $callback_key = key_exists('key', $this->request->get) ? (string) $this->request->get['key'] : '';
            if (!$callback_key) {
                $message = 'Wrong params received';
                $this->model->logInfo($message
                    .': invoice:'.$invoice_id
                    .', status:'.$status
                    .', order:'.$order_id
                    .', key:'.$callback_key
                );
                Utils::sendJson($message, 400);
                exit;
            }
            if ($this->model->hashInvalid($order_id, $callback_key)) {
                $message = 'Key not valid';
                $this->model->logInfo($message
                    .', invoice:'.$invoice_id
                    .', status:'.$status
                    .', order:'.$order_id
                    .': key:'.$callback_key
                );
                Utils::sendJson($message, 403);
                exit;
            }
         };
    }

    /**
     * @return \Closure payment processing handler
     */
    protected function getPaymentProcessor(): \Closure
    {
        return function(Invoice $invoice): void
        {
            $this->model->updateOrderStatus($invoice);
        };
    }

    // to test callback on local server
    // OC2, OC3: curl -k -w "%{http_code}\n" -X POST -d '{"invoice":"INVOICE_ID_HERE","status":"expired"}' 'https://examples.test/opencartX/index.php?route=extension/payment/apirone_mccp/callback&key=CALLBACK_KEY_HERE'
    // OC4:      curl -k -w "%{http_code}\n" -X POST -d '{"invoice":"INVOICE_ID_HERE","status":"expired"}' 'https://examples.test/opencart4/index.php?route=extension/apirone/payment/apirone_mccp|callback&key=CALLBACK_KEY_HERE'
    /**
     * Callback URI for change invoice and order status
     */
    public function callback(): void
    {
        Invoice::callbackHandler($this->getPaymentProcessor(), $this->getCallbackChecker());
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
        $amount = $invoice->details->amount;
        if ($this->model->hashInvalid($amount, $invoice_key)) {
            $message = 'Key not valid';
            $this->model->logInfo($message
                .', invoice:'.$invoice->invoice
                .', amount:'.$amount
                .': key:'.$invoice_key
            );
            Utils::sendJson($message, 403);
            return;
        }
        $this->response->redirect($this->url->link('checkout/success'));
    }

    /**
     * API proxy endpoint for invoice app to get currencies
     */
    public function wallets(): void
    {
        // OC2, OC3: OPTIONS https://examples.test/opencartX/index.php?route=extension/payment/apirone_mccp/wallets
        // OC4:      OPTIONS https://examples.test/opencart4/index.php?route=extension/apirone/payment/apirone_mccp|wallets
        Api::wallets();
        // TODO: we can also cache this info until any expiration time to reduce calls to Apirone API
    }

    /**
     * API proxy endpoint to get invoice data with invoice ID in path
     */
    public function invoices(): void
    {
        // OC2, OC3: GET https://examples.test/opencartX/index.php?route=extension/payment/apirone_mccp/invoices&id={INVOICE_ID}
        // OC4:      GET https://examples.test/opencart4/index.php?route=extension/apirone/payment/apirone_mccp|invoices&id={INVOICE_ID}

        // settings not need, but update need
        $this->model->update();

        $invoice_id = key_exists('id', $this->request->get) ? (string) $this->request->get['id'] : '';
        if (!$invoice_id) {
            $message = 'Invoice id not specified';
            $this->model->logInfo($message);
            Utils::sendJson($message, 400);
            return;
        }
        $this->model->initInvoiceModel();
        Api::checkInterval(30);
        Api::invoices($invoice_id, $this->getPaymentProcessor());
    }
}
