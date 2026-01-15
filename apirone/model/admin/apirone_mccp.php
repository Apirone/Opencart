<?php

namespace Apirone\Payment\Model\Admin;

require_once((version_compare(VERSION, 4, '<')
    ? DIR_SYSTEM . 'library/apirone/'
    : DIR_EXTENSION . 'apirone/system/library/'
) . 'apirone_mccp.php');

require_once(PATH_TO_LIBRARY . 'model/apirone_mccp.php');
require_once(PATH_TO_LIBRARY . 'vendor/autoload.php');

use Apirone\Payment\Model\ModelExtensionPaymentApironeMccpCommon;

use Apirone\SDK\Model\Settings;
use Apirone\SDK\Service\Db;

class ModelExtensionPaymentApironeMccpAdmin extends ModelExtensionPaymentApironeMccpCommon
{
    /**
     * Creates in DB new settings and invoices table
     * @return bool `true` on success, `false` otherwise
     */
    public function install(): bool
    {
        try {
            $_settings = Settings::init()->createAccount();
        } catch (\Throwable $ignore) {
            return false;
        }
        $_settings
            ->version(PLUGIN_VERSION)
            ->secret($this->hash(time(), $this->session->data[USER_TOKEN_KEY]))
            ->timeout(1800)
            ->processing_fee('percentage')
            ->factor(1.0)
            ->logo(true)
            ->status_ids(DEFAULT_STATUS_IDS);

        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting(SETTINGS_CODE, array(
            // Apirone plugin specific settings
            SETTING_PREFIX . 'settings' => $_settings->toJsonString(),
            // OpenCart common plugin settings
            SETTING_PREFIX . 'geo_zone_id' => '0',
            SETTING_PREFIX . 'status' => '0',
            SETTING_PREFIX . 'sort_order' => '0',
        ));

        $this->initInvoiceModel();
        Db::install();

        return true;
    }

    public function uninstall()
    {
        // do nothing
        // OpenCart automatically removes plugin settings
        // all invoices data in DB and logs remains for history
        // $this->initInvoiceModel();
        // Db::uninstall();
    }
}
