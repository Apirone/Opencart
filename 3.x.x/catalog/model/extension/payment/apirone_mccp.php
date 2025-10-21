<?php

require_once(DIR_SYSTEM . 'library/apirone/model/apirone_mccp.php');

class ModelExtensionPaymentApironeMccp extends ExtensionPaymentApironeMccpModelCommon
{
    /**
     * Gets method data to show in payment method selector\
     * OpenCart required
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
            'title'      => sprintf('<span data-toggle="tooltip" data-original-title="%s">%s</span>', $currencies, $this->language->get('text_title')),
            'terms'      => '',
            'sort_order' => $this->config->get(SETTING_PREFIX . 'sort_order'),
        );
    }
}
