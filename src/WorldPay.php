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

use Nails\Common\Exception\NailsException;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Factory\ChargeResponse;
use Nails\Invoice\Factory\CompleteResponse;
use Nails\Invoice\Factory\RefundResponse;
use Nails\Invoice\Factory\ScaResponse;

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
     * @return bool
     */
    public function isAvailable($oInvoice): bool
    {
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the currencies which this driver supports, it will only be presented
     * when attempting to pay an invoice in a supported currency
     *
     * @return string[]|null
     */
    public function getSupportedCurrencies(): ?array
    {
        return null;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver uses a redirect payment flow or not.
     *
     * @return bool
     */
    public function isRedirect(): bool
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
        return [];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns any assets to load during checkout
     *
     * @return array
     */
    public function getCheckoutAssets(): array
    {
        return [
            [
                'checkout.min.js',
                $this->getSlug(),
                'JS',
            ],
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Initiate a payment
     *
     * @param integer   $iAmount      The payment amount
     * @param string    $sCurrency    The payment currency
     * @param \stdClass $oData        An array of driver data
     * @param \stdClass $oCustomData  The custom data object
     * @param string    $sDescription The charge description
     * @param \stdClass $oPayment     The payment object
     * @param \stdClass $oInvoice     The invoice object
     * @param string    $sSuccessUrl  The URL to go to after successful payment
     * @param string    $sErrorUrl    The URL to go to after failed payment
     *
     * @return ChargeResponse
     */
    public function charge(
        $iAmount,
        $sCurrency,
        $oData,
        $oCustomData,
        $sDescription,
        $oPayment,
        $oInvoice,
        $sSuccessUrl,
        $sErrorUrl
    ): ChargeResponse {
        //  @todo (Pablo - 2019-07-24) - Implement this method
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Handles any SCA requests
     *
     * @param ScaResponse $oScaResponse The SCA Response object
     * @param array       $aData        Any saved SCA data
     * @param string      $sSuccessUrl  The URL to redirect to after authorisation
     *
     * @return ScaResponse
     */
    public function sca(ScaResponse $oScaResponse, array $aData, string $sSuccessUrl): ScaResponse
    {
        //  @todo (Pablo - 2019-07-24) - Implement this method
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the payment
     *
     * @param \stdClass $oPayment  The Payment object
     * @param \stdClass $oInvoice  The Invoice object
     * @param array     $aGetVars  Any $_GET variables passed from the redirect flow
     * @param array     $aPostVars Any $_POST variables passed from the redirect flow
     *
     * @return CompleteResponse
     */
    public function complete($oPayment, $oInvoice, $aGetVars, $aPostVars): CompleteResponse
    {
        //  @todo (Pablo - 2019-07-24) - Implement this method
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     *
     * @param string    $sTxnId      The transaction's ID
     * @param integer   $iAmount     The amount to refund
     * @param string    $sCurrency   The currency in which to refund
     * @param \stdClass $oCustomData The custom data object
     * @param string    $sReason     The refund's reason
     * @param \stdClass $oPayment    The payment object
     * @param \stdClass $oInvoice    The invoice object
     *
     * @return RefundResponse
     */
    public function refund($sTxnId, $iAmount, $sCurrency, $oCustomData, $sReason, $oPayment, $oInvoice): RefundResponse
    {
        //  @todo (Pablo - 2019-07-24) - Implement this method
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new payment source, returns a semi-populated source resource
     *
     * @param \Nails\Invoice\Resource\Source $oResource The Resouce object to update
     * @param array                          $aData     Data passed from the caller
     *
     * @throws DriverException
     */
    public function createSource(
        \Nails\Invoice\Resource\Source &$oResource,
        array $aData
    ): void {
        //  @todo (Pablo - 2019-09-05) - implement this
        throw new NailsException('Method not implemented');
    }
}
