<?php

require_once(DIR_SYSTEM . 'library/apirone/model/catalog/apirone_mccp.php');

// the class name formation matters
class ModelExtensionPaymentApironeMccp extends \Apirone\Payment\Model\Catalog\ModelExtensionPaymentApironeMccpCatalog
{
    /**
     * Gets method data to show in payment method selector\
     * OpenCart required
     */
    public function getMethod(array $address): ?array
    {
        $currencies = $this->getCurrencies($address);
        return !$currencies ? null : array(
            'code'       => 'apirone_mccp',
            'title'      => sprintf('<span data-toggle="tooltip" data-original-title="%s">%s</span>', $currencies, $this->language->get('text_title')),
            'terms'      => '',
            'sort_order' => $this->config->get(SETTING_PREFIX . 'sort_order'),
        );
    }
}
