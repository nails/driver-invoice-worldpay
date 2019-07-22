<?php

/**
 * WorldPay payment Driver
 *
 * @package     Nails
 * @subpackage  driver-invoice-stripe
 * @category    Driver
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Invoice\Driver\Payment;

use Nails\Invoice\Driver\PaymentBase;

/**
 * Class WorldPay
 *
 * @package Nails\Invoice\Driver\Payment
 */
class WorldPay extends PaymentBase
{
    //  @todo (Pablo - 2019-07-22) - Complete this driver

    /**
     * Returns whether the driver is available to be used against the selected invoice
     *
     * @param \stdClass $oInvoice The invoice being charged
     *
     * @return boolean
     */
    public function isAvailable($oInvoice)
    {
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver uses a redirect payment flow or not.
     *
     * @return boolean
     */
    public function isRedirect()
    {
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the payment fields the driver requires, use static::PAYMENT_FIELDS_CARD for basic credit
     * card details.
     *
     * @return mixed
     */
    public function getPaymentFields()
    {
        return [
            [
                'key'      => 'token',
                'label'    => 'Card Details (WP)',
                'required' => true,
            ],
        ];
    }
}
