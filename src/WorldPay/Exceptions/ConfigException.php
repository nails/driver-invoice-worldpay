<?php

namespace Nails\Invoice\Driver\Payment\WorldPay\Exceptions;

use Nails\Invoice\Driver\Payment\WorldPay\Exceptions\WorldPayException;
use Nails\Invoice\Exception\DriverException;

/**
 * Class ConfigException
 *
 * @package Nails\Invoice\Driver\Payment\WorldPay\Exceptions
 */
class ConfigException extends WorldPayException
{
    const DOCUMENTATION_URL = 'https://github.com/nails/driver-invoice-worldpay#configuration';
}
