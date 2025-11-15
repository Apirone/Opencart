<?php

namespace Apirone\Payment\Controller;

require_once((version_compare(VERSION, 4, '<')
    ? DIR_SYSTEM . 'library/apirone/'
    : DIR_EXTENSION . 'apirone/system/library/'
) . 'apirone_mccp.php');

require_once(PATH_TO_LIBRARY . 'controller/common.php');
require_once(PATH_TO_LIBRARY . 'vendor/autoload.php');

use Apirone\SDK\Model\Settings;

class ControllerExtensionPaymentApironeMccpCommon extends ControllerExtensionPaymentCommon
{
    protected ?Settings $settings = null;
    protected $model = null;

    public function __construct($registry)
    {
        parent::__construct($registry);

        $this->load->model(PATH_TO_RESOURCES);
        $this->model = $this->{'model_' . str_replace('/', '_', PATH_TO_RESOURCES)};
        $this->model->initLogger();
    }
}
