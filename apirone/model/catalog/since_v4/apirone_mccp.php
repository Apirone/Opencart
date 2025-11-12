<?php

namespace Opencart\Catalog\Model\Extension\Apirone\Payment;

require_once(DIR_EXTENSION . 'apirone/system/library/model/catalog/apirone_mccp.php');

// class must be named as plugin
class ApironeMccp extends \Apirone\Payment\Model\Catalog\ModelExtensionPaymentApironeMccpCatalog
{
    /**
     * Gets method data to show in payment method selector\
     * OpenCart required\
     * Before 4.0.2.0
     * @param array $address
     * @return array
     */
    public function getMethod(array $address): ?array
    {
        $currencies = $this->getCurrencies($address);
        $this->logError('getMethod debug currencies: ' . json_encode($currencies));
        return !$currencies ? null : array(
            'code'       => 'apirone_mccp',
            // TODO: check 'title' can be in HTML and tooltip can be visible by this HTML
            'title'      => sprintf('<span data-toggle="tooltip" data-original-title="%s">%s</span>', $currencies, $this->language->get('text_title')),
            // TODO: check 'terms' presence not log notifications
            'terms'      => '',
            'sort_order' => $this->config->get(SETTING_PREFIX . 'sort_order'),
        );  
    }

    /**
     * Gets method data to show in payment method selector\
     * OpenCart required\
     * Since 4.0.2.0
     * @param array $address
     * @return array
     */
    public function getMethods(array $address): ?array
    {
        $currencies = $this->getCurrencies($address);
        if (!$currencies) {
            return null;
        }
        $option['apirone_mccp'] = [
            'code' => 'apirone_mccp.apirone_mccp',
            'name' => $currencies,
        ];
        return [
            'code'       => 'apirone_mccp',
            'name'       => $this->language->get('text_title'),
            'option'     => $option,
            'sort_order' => $this->config->get('payment_apirone_mccp_sort_order'),
        ];
    }
}
