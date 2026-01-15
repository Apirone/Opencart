<?php

namespace Apirone\Payment\Controller;

require_once((version_compare(VERSION, 4, '<')
    ? DIR_SYSTEM . 'library/apirone/'
    : DIR_EXTENSION . 'apirone/system/library/'
) . 'apirone_mccp.php');

require_once(PATH_TO_LIBRARY . 'controller/common.php');
require_once(PATH_TO_LIBRARY . 'vendor/autoload.php');

use Apirone\API\Http\Request;
use Apirone\SDK\Model\Settings;
use Apirone\SDK\Service\Utils;

class ControllerExtensionPaymentApironeMccpCommon extends ControllerExtensionPaymentCommon
{
    protected ?Settings $settings = null;
    protected $model = null;

    public function __construct($registry)
    {
        parent::__construct($registry);

        Request::userAgent('OpenCart/' . VERSION . ' MCCP/' . PLUGIN_VERSION);

        $this->load->model(PATH_TO_RESOURCES);
        $this->model = $this->{'model_' . str_replace('/', '_', PATH_TO_RESOURCES)};
        $this->model->initLogger();
    }

    private const ANCHOR_PATTERN = '<a href="%s" target="_blank">%s</a>';

    /**
     * Action for events with triggers:
     * admin/model/sale/order/getHistories/after
     * catalog/model/account/order/getHistories/after
     * @param array &$output order history records from getHistories() method output
     */
	public function afterGetHistories(string &$route, array &$_data, array &$output): void
    {
        foreach($output as &$history) {
            $comment = json_decode($history['comment']);
            if (!(is_object($comment) && property_exists($comment, 'abbr') && property_exists($comment, 'status'))) {
                continue;
            }
            if (OC_MAJOR_VERSION < 4 && strpos($route, 'account') !== false) {
                $history['notify'] = true;
            }
            $this->load->language(PATH_TO_RESOURCES);
            if (property_exists($comment, 'address')) {
                $history['comment'] = sprintf($this->language->get('order_history_address'),
                    $comment->status,
                    Utils::getCoin($comment->abbr)->alias,
                    sprintf(self::ANCHOR_PATTERN, Utils::getAddressLink($comment->abbr, $comment->address), $comment->address)
                );
                continue;
            }
            if (property_exists($comment, 'txid')) {
                $history['comment'] = sprintf($this->language->get('order_history_txid'),
                    $comment->status,
                    sprintf(self::ANCHOR_PATTERN, Utils::getTransactionLink($comment->abbr, $comment->txid), $comment->txid)
                );
                continue;
            }
            $history['comment'] = sprintf($this->language->get('order_history'), $comment->status);
        }
	}
}
