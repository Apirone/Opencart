<?php

namespace Opencart\Admin\Model\Extension\Apirone\Payment;

require_once(DIR_EXTENSION . 'apirone/system/library/model/apirone_mccp.php');

// model class must be named as plugin
class ApironeMccp extends \Apirone\Payment\Model\ModelExtensionPaymentApironeMccpCommon {}
