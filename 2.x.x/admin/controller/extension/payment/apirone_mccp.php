<?php

use ApironeApi\Apirone;

require_once(DIR_SYSTEM . 'library/apirone_api/Apirone.php');

// Define Plugin version
define ('PLUGIN_VERSION', '1.1.3');

class ControllerExtensionPaymentApironeMccp extends Controller {
    private $error = array();

    public function index() {
        $this->update();
        $this->load->language('extension/payment/apirone_mccp');
        $this->load->model('extension/payment/apirone_mccp');

        $account = unserialize( $this->config->get('apirone_mccp_account') );
        $secret = $this->config->get('apirone_mccp_secret');

        $apirone_currencies = Apirone::currencyList();
        $plugin_currencies = unserialize( $this->config->get('apirone_mccp_currencies') );

        $errors_count = 0;
        $active_currencies = 0;
        $currencies = array();

        if (!$this->user->hasPermission('modify', 'extension/payment/apirone_mccp')) {
            $data['error'] = $this->language->get('error_permission');
            $errors_count++;
        }

        foreach ($apirone_currencies as $item) {
            $currency = new \stdClass();

            $currency->name = $item->name;
            $currency->abbr = $item->abbr;
            $currency->{'dust-rate'} = $item->{'dust-rate'};
            $currency->{'units-factor'} = $item->{'units-factor'};
            $currency->address = '';
            $currency->currency_tooltip = sprintf($this->language->get('currency_activate_tooltip'), $item->name);
            $currency->testnet = $item->testnet;

            // Set address from config 
            if ($plugin_currencies) {
                $currency->address = $plugin_currencies[$item->abbr]->address;
            }
            // Set address from config 
            if ($this->request->server['REQUEST_METHOD'] == 'POST') {
                $currency->address = $_POST['address'][$item->abbr];
                if ($currency->address != '') {
                    $result = Apirone::setTransferAddress($account, $item->abbr, $currency->address);
                        if ($result == false) {
                        $currency->error = 1;
                        $errors_count++;
                    }
                }
            }
            // Set tooltip
            if (empty($currency->address))
                $currency->currency_tooltip = sprintf($this->language->get('currency_activate_tooltip'), $item->name);
            else {
                $currency->currency_tooltip = sprintf($this->language->get('currency_deactivate_tooltip'), $item->name);
                $active_currencies++;
            }
            $currencies[$item->abbr] = $currency;
        }

        // Set values into template vars
        $this->setValue($data, 'apirone_mccp_version');
        $this->setValue($data, 'apirone_mccp_timeout', true);
        $this->setValue($data, 'apirone_mccp_invoice_created_status_id');
        $this->setValue($data, 'apirone_mccp_invoice_paid_status_id');
        $this->setValue($data, 'apirone_mccp_invoice_partpaid_status_id');
        $this->setValue($data, 'apirone_mccp_invoice_overpaid_status_id');
        $this->setValue($data, 'apirone_mccp_invoice_completed_status_id');
        $this->setValue($data, 'apirone_mccp_invoice_expired_status_id');
        $this->setValue($data, 'apirone_mccp_geo_zone_id');
        $this->setValue($data, 'apirone_mccp_status');
        $this->setValue($data, 'apirone_mccp_sort_order');
        $this->setValue($data, 'apirone_mccp_merchantname');
        $this->setValue($data, 'apirone_mccp_secret');
        $this->setValue($data, 'apirone_mccp_testcustomer');

        if ($active_currencies == 0 || $data['apirone_mccp_timeout'] <= 0 || count($currencies) == 0) {
            $errors_count++;            
        }

        $errors_count = $errors_count + count($this->error);

        // Save settings if post & no errors
        $this->load->model('setting/setting');
        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            if ($errors_count == 0) {
                $_settings['apirone_mccp_version'] = PLUGIN_VERSION;
                $_settings['apirone_mccp_account'] = serialize($account);
                $_settings['apirone_mccp_secret'] = $secret;
                $_settings['apirone_mccp_currencies'] = serialize($currencies);

                $_settings['apirone_mccp_timeout'] = $_POST['apirone_mccp_timeout'];
                $_settings['apirone_mccp_invoice_created_status_id'] = $_POST['apirone_mccp_invoice_created_status_id'];
                $_settings['apirone_mccp_invoice_paid_status_id'] = $_POST['apirone_mccp_invoice_paid_status_id'];
                $_settings['apirone_mccp_invoice_partpaid_status_id'] = $_POST['apirone_mccp_invoice_partpaid_status_id'];
                $_settings['apirone_mccp_invoice_overpaid_status_id'] = $_POST['apirone_mccp_invoice_overpaid_status_id'];
                $_settings['apirone_mccp_invoice_completed_status_id'] = $_POST['apirone_mccp_invoice_completed_status_id'];
                $_settings['apirone_mccp_invoice_expired_status_id'] = $_POST['apirone_mccp_invoice_expired_status_id'];
                $_settings['apirone_mccp_geo_zone_id'] = $_POST['apirone_mccp_geo_zone_id'];
                $_settings['apirone_mccp_status'] = $_POST['apirone_mccp_status'];
                $_settings['apirone_mccp_sort_order'] = $_POST['apirone_mccp_sort_order'];
                $_settings['apirone_mccp_merchantname'] = $_POST['apirone_mccp_merchantname'];
                $_settings['apirone_mccp_testcustomer'] = $_POST['apirone_mccp_testcustomer'];

                $this->model_setting_setting->editSetting('apirone_mccp', $_settings);
                $data['success'] = $this->language->get('text_success');
            }
            else {
                // No addresses
                if ($active_currencies == 0 ) {
                    $data['error'] = $this->language->get('error_empty_currencies');
                }
                // Payment timeout
                if($data['apirone_mccp_timeout'] <= 0) {
                    $this->error['apirone_mccp_timeout'] = $this->language->get('error_apirone_mccp_timeout_positive');
                }
                if($data['apirone_mccp_timeout'] === '') {
                    $this->error['apirone_mccp_timeout'] = $this->language->get('error_apirone_mccp_timeout');
                }

            }
        }

        // =============================================================================================
        // Set template variables

        $this->document->setTitle($this->language->get('heading_title'));

        $data = array_merge($data, $this->load->language('apirone_mccp'));

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['order_statuses'][] = ['order_status_id' => 0, 'name' => $this->language->get('text_missing')];

        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $data['currencies'] = $currencies;
        // Can't get currency list
        if (count($currencies) == 0) {
            $data['error'] = $this->language->get('error_service_not_available');
        }

        $this->getBreadcrumbsAndActions($data);
        $data['errors'] = $this->error;
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/apirone_mccp', $data));
    }

    protected function validate() {
        
    }

    protected function getBreadcrumbsAndActions(&$data) {
        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'token='. $this->session->data['token'], true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('extension/extension', 'token='. $this->session->data['token'] . '&type=payment', true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/apirone_mccp', 'token='. $this->session->data['token'], true)
        );

        $data['action'] = $this->url->link('extension/payment/apirone_mccp', 'token='. $this->session->data['token'], true);

        $data['cancel'] = $this->url->link('extension/extension', 'token='. $this->session->data['token'] . '&type=payment', true);
    }

    protected function setValue(&$data, $value, $required = false) {
        if (isset($this->request->post[$value])) {
            $data[$value] = $this->request->post[$value];
        }
        else {
            $data[$value] = $this->config->get($value);
        }
        if ($required && empty($data[$value])) {
            $this->error[$value] = $this->language->get(str_replace('payment', 'error', $value));
        }
    }

    // Install / Uninstall plugin
    public function install() {

        $this->load->model('extension/payment/apirone_mccp');
        $this->load->model('setting/setting');

        $data = array(
            'apirone_mccp_version' => PLUGIN_VERSION,
            'apirone_mccp_secret' => md5(time().'token='. $this->session->data['token']),
            'apirone_mccp_invoice_created_status_id' => '1',
            'apirone_mccp_invoice_paid_status_id' => '1',
            'apirone_mccp_invoice_partpaid_status_id' => '1',
            'apirone_mccp_invoice_overpaid_status_id' => '1',
            'apirone_mccp_invoice_completed_status_id' => '5',
            'apirone_mccp_invoice_expired_status_id' => '16',
            'apirone_mccp_timeout' => '1800',
            'apirone_mccp_sort_order' => '0',
        );

        $account = Apirone::accountCreate();

        if($account) {
            $data['apirone_mccp_account']  = serialize($account);
        }

        $this->model_setting_setting->editSetting('apirone_mccp', $data);

        $query = ApironeApi\Db::createInvoicesTableQuery(DB_PREFIX);
        $this->model_extension_payment_apirone_mccp->install_invoices_table($query);
    }

    public function uninstall() {
        $this->load->model('extension/payment/apirone_mccp');
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('apirone_mccp');

        // $query = ApironeApi\Db::deleteInvoicesTableQuery(DB_PREFIX);
        // $this->model_extension_payment_apirone_mccp->delete_invoices_table($query);
    }

    private function update() {
        $this->load->model('setting/setting');
        $version = $this->model_setting_setting->getSettingValue('apirone_mccp_version');
        if ($version == '') {
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

        return;
    }

    private function upd_version($version) {
        $current = $this->model_setting_setting->getSetting('apirone_mccp');
        $current['apirone_mccp_version'] = $version;

        $this->model_setting_setting->editSetting('apirone_mccp', $current);

        return $version;
    }

    private function upd_1_0_1__1_1_0() {
        $current = $this->model_setting_setting->getSetting('apirone_mccp');

        $data = $current;

        $pending = array_key_exists('apirone_mccp_pending_status_id', $current) ? $current['apirone_mccp_pending_status_id'] : 1;
        $completed = array_key_exists('apirone_mccp_completed_status_id', $current) ? $current['apirone_mccp_completed_status_id'] : 5;
        $voided = array_key_exists('apirone_mccp_voided_status_id', $current) ? $current['apirone_mccp_voided_status_id'] : 16;

        // Add new settings
        $data['apirone_mccp_version'] = '1.1.0';
        $data['apirone_mccp_invoice_created_status_id'] = $pending;
        $data['apirone_mccp_invoice_paid_status_id'] = $pending;
        $data['apirone_mccp_invoice_partpaid_status_id'] = $pending;
        $data['apirone_mccp_invoice_overpaid_status_id'] = $pending;
        $data['apirone_mccp_invoice_completed_status_id'] = $completed;
        $data['apirone_mccp_invoice_expired_status_id'] = $voided;

        // Remove old settings
        unset($data['apirone_mccp_status_id']);
        unset($data['apirone_mccp_pending_status_id']);
        unset($data['apirone_mccp_voided_status_id']);

        $this->model_setting_setting->editSetting('apirone_mccp', $data);

        return $data['apirone_mccp_version'];
    }
}