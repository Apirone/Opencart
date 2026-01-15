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
        return !$this->getCurrencies($address) ? null : array(
            'code'       => 'apirone_mccp',
            'title'      => $this->language->get('text_title'),
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
        if (!$this->getCurrencies($address)) {
            return null;
        }
        $text_title = $this->language->get('text_title');

        $option['apirone_mccp'] = [
            'code' => 'apirone_mccp.apirone_mccp',
            'name' => $text_title,
        ];
        return [
            'code'       => 'apirone_mccp',
            'name'       => $text_title,
            'option'     => $option,
            'sort_order' => $this->config->get('payment_apirone_mccp_sort_order'),
        ];
    }
}
