<?php

namespace Opencart\Catalog\Model\Extension\Apirone\Payment;

require_once(DIR_EXTENSION . 'apirone/system/library/model/apirone_mccp.php');

// model class must be named as plugin
class ApironeMccp extends \Apirone\Payment\Model\ModelExtensionPaymentApironeMccpCommon
{
    /**
     * Gets method data to show in payment method selector\
     * OpenCart required
     * Before 4.0.2.0
     * @param array $address
     * @return array
     */
    public function getMethod(array $address): ?array
    {
        $geo_zone_id_allowed = (int) $this->config->get(SETTING_PREFIX . 'geo_zone_id');
        if ($geo_zone_id_allowed) {
            $geo_zone_query_result = $this->db->query(sprintf(
                'SELECT * FROM %s WHERE geo_zone_id = "%s" AND country_id = "%s" AND (zone_id = "%s" OR zone_id = "0")',
                DB_PREFIX . 'zone_to_geo_zone',
                $geo_zone_id_allowed,
                (int) $address['country_id'],
                (int) $address['zone_id'],
            ));
            if (!$geo_zone_query_result->num_rows) {
                return null;
            }
        }
        $coins = $this->getCoinsAvailable();
        if (empty($coins)) {
            return null;
        }
        $currencies = '';
        foreach ($coins as $coin) {
            if ($currencies) {
                $currencies .= ', ';
            }
            $currencies .= $coin->alias;
        }
        $this->load->language(PATH_TO_RESOURCES);
        return array(
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
     * OpenCart required
     * Since 4.0.2.0
     * @param array $address
     * @return array
     */
    public function getMethods(array $address): ?array
    {
        $geo_zone_id_allowed = (int) $this->config->get(SETTING_PREFIX . 'geo_zone_id');
        if ($geo_zone_id_allowed) {
            $geo_zone_query_result = $this->db->query(sprintf(
                'SELECT * FROM %s WHERE geo_zone_id = "%s" AND country_id = "%s" AND (zone_id = "%s" OR zone_id = "0")',
                DB_PREFIX . 'zone_to_geo_zone',
                $geo_zone_id_allowed,
                (int) $address['country_id'],
                (int) $address['zone_id'],
            ));
            if (!$geo_zone_query_result->num_rows) {
                return null;
            }
        }
        $coins = $this->getCoinsAvailable();
        if (empty($coins)) {
            return null;
        }
        $currencies = '';
        foreach ($coins as $coin) {
            if ($currencies) {
                $currencies .= ', ';
            }
            $currencies .= $coin->alias;
        }
        $this->load->language(PATH_TO_RESOURCES);

        $option_data['apirone_mccp'] = array(
            'code' => 'apirone_mccp.apirone_mccp',
            'name' => $currencies,
        );
        return array(
            'code'       => 'apirone_mccp',
            'name'       => $this->language->get('text_title'),
            'option'     => $option_data,
            'terms'      => '',
            'sort_order' => $this->config->get(SETTING_PREFIX . 'sort_order'),
        );  
    }
}
