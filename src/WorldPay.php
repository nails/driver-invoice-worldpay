<?php

/**
 * WorldPay payment Driver
 *
 * @package     Nails
 * @subpackage  driver-invoice-worldpay
 * @category    Driver
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Invoice\Driver\Payment;

use Nails\Common\Exception\NailsException;
use Nails\Currency\Resource\Currency;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Factory\ChargeResponse;
use Nails\Invoice\Factory\CompleteResponse;
use Nails\Invoice\Factory\RefundResponse;
use Nails\Invoice\Factory\ScaResponse;
use Nails\Invoice\Resource;
use stdClass;

/**
 * Class WorldPay
 *
 * @package Nails\Invoice\Driver\Payment
 */
class WorldPay extends PaymentBase
{
    /**
     * Returns whether the driver is available to be used against the selected invoice
     *
     * @param Resource\Invoice $oInvoice The invoice being charged
     *
     * @return bool
     */
    public function isAvailable(Resource\Invoice $oInvoice): bool
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
        $sCode = appSetting('sSupportedCurrency', 'nails/driver-invoice-worldpay');
        return $sCode ? [strtoupper($sCode)] : null;
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
     * @param int                           $iAmount      The payment amount
     * @param Currency                      $oCurrency    The payment currency
     * @param stdClass                      $oData        An array of driver data
     * @param Resource\Invoice\Data\Payment $oPaymentData The payment data object
     * @param string                        $sDescription The charge description
     * @param Resource\Payment              $oPayment     The payment object
     * @param Resource\Invoice              $oInvoice     The invoice object
     * @param string                        $sSuccessUrl  The URL to go to after successful payment
     * @param string                        $sErrorUrl    The URL to go to after failed payment
     * @param Resource\Source|null          $oSource      The saved payment source to use
     *
     * @return ChargeResponse
     */
    public function charge(
        int $iAmount,
        Currency $oCurrency,
        stdClass $oData,
        Resource\Invoice\Data\Payment $oPaymentData,
        string $sDescription,
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice,
        string $sSuccessUrl,
        string $sErrorUrl,
        bool $bCustomerPresent,
        Resource\Source $oSource = null
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
     * @param Resource\Payment $oPayment  The Payment object
     * @param Resource\Invoice $oInvoice  The Invoice object
     * @param array            $aGetVars  Any $_GET variables passed from the redirect flow
     * @param array            $aPostVars Any $_POST variables passed from the redirect flow
     *
     * @return CompleteResponse
     */
    public function complete(
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice,
        array $aGetVars,
        array $aPostVars
    ): CompleteResponse {
        //  @todo (Pablo - 2019-07-24) - Implement this method
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     *
     * @param string                        $sTransactionId The transaction's ID
     * @param int                           $iAmount        The amount to refund
     * @param Currency                      $oCurrency      The currency in which to refund
     * @param Resource\Invoice\Data\Payment $oPaymentData   The payment data object
     * @param string                        $sReason        The refund's reason
     * @param Resource\Payment              $oPayment       The payment object
     * @param Resource\Invoice              $oInvoice       The invoice object
     *
     * @return RefundResponse
     */
    public function refund(
        string $sTransactionId,
        int $iAmount,
        Currency $oCurrency,
        Resource\Invoice\Data\Payment $oPaymentData,
        string $sReason,
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice
    ): RefundResponse {
        //  @todo (Pablo - 2019-07-24) - Implement this method
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new payment source, returns a semi-populated source resource
     *
     * @param Resource\Source $oResource The Resouce object to update
     * @param array           $aData     Data passed from the caller
     *
     * @throws DriverException
     */
    public function createSource(
        Resource\Source &$oResource,
        array $aData
    ): void {
        //  @todo (Pablo - 2019-09-05) - implement this
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for creating a new customer on the gateway
     *
     * @param array $aData The driver specific customer data
     */
    public function createCustomer(array $aData = [])
    {
        //  @todo (Pablo - 2019-10-03) - implement this
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for retrieving an existing customer from the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     * @param array $aData       Any driver specific data
     */
    public function getCustomer($mCustomerId, array $aData = [])
    {
        //  @todo (Pablo - 2019-10-03) - implement this
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for updating an existing customer on the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     * @param array $aData       The driver specific customer data
     */
    public function updateCustomer($mCustomerId, array $aData = [])
    {
        //  @todo (Pablo - 2019-10-03) - implement this
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for deleting an existing customer on the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     */
    public function deleteCustomer($mCustomerId)
    {
        //  @todo (Pablo - 2019-10-03) - implement this
        throw new NailsException('Method not implemented');
    }
}
