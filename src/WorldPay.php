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
use Nails\Invoice\Exception\DriverException;
use Nails\Invoice\Factory\ChargeResponse;
use Nails\Invoice\Factory\CompleteResponse;
use Nails\Invoice\Factory\RefundResponse;
use Nails\Invoice\Factory\ScaResponse;
use Nails\Invoice\Resource;
use stdClass;
use function foo\func;

/**
 * Class WorldPay
 *
 * @package Nails\Invoice\Driver\Payment
 */
class WorldPay extends PaymentBase
{
    const PAYMENT_SOURCES_ERROR = 'Payment Sources are not supported by the WorldPay driver';

    // --------------------------------------------------------------------------

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
        $aCodes = json_decode($this->getSetting('sMerchantCodes'));

        if (is_null($aCodes)) {
            return null;
        }

        return array_map(function ($oItem) {
            return strtoupper($oItem->currency);
        }, $aCodes);
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

        if (!empty($oSource)) {
            throw new DriverException(
                static::PAYMENT_SOURCES_ERROR
            );
        }

        try {

            $oDoc = $this->createXmlDocument();

            $oDoc->appendChild(
                $this->createXmlElement($oDoc, 'paymentService', [
                    $this->createXmlElement($oDoc, 'submit', [
                        $this->createXmlElement($oDoc, 'order', [
                            $this->createXmlElement($oDoc, 'description'),
                            $this->createXmlElement($oDoc, 'amount', '', [
                                'currencyCode' => $oCurrency->code,
                                'exponent'     => 2,
                                'value'        => $iAmount,
                            ]),
                            $this->createXmlElement($oDoc, 'orderContent'),
                            $this->createXmlElement($oDoc, 'paymentMethodMask', [
                                $this->createXmlElement($oDoc, 'include', '', [
                                    'code' => 'ALL',
                                ]),
                            ]),
                            $this->createXmlElement($oDoc, 'shopper', [
                                $this->createXmlElement($oDoc, 'shopperEmailAddress', $oInvoice->customer()->billing_email ?: $oInvoice->customer()->email),
                            ]),
                            $this->createXmlElement($oDoc, 'shippingAddress', [
                                $this->createXmlElement($oDoc, 'address1', $oInvoice->deliveryAddress()->line_1 ?? ''),
                                $this->createXmlElement($oDoc, 'address2', $oInvoice->deliveryAddress()->line_2 ?? ''),
                                $this->createXmlElement($oDoc, 'address3', $oInvoice->deliveryAddress()->line_3 ?? ''),
                                $this->createXmlElement($oDoc, 'postalCode', $oInvoice->deliveryAddress()->postcode ?? ''),
                                $this->createXmlElement($oDoc, 'city', $oInvoice->deliveryAddress()->town ?? ''),
                                $this->createXmlElement($oDoc, 'state', $oInvoice->deliveryAddress()->region ?? ''),
                                $this->createXmlElement($oDoc, 'country', $oInvoice->deliveryAddress()->country->iso ?? ''),
                            ]),
                            $this->createXmlElement($oDoc, 'billingAddress', [
                                $this->createXmlElement($oDoc, 'address1', $oInvoice->billingAddress()->line_1 ?? ''),
                                $this->createXmlElement($oDoc, 'address2', $oInvoice->billingAddress()->line_2 ?? ''),
                                $this->createXmlElement($oDoc, 'address3', $oInvoice->billingAddress()->line_3 ?? ''),
                                $this->createXmlElement($oDoc, 'postalCode', $oInvoice->billingAddress()->postcode ?? ''),
                                $this->createXmlElement($oDoc, 'city', $oInvoice->billingAddress()->town ?? ''),
                                $this->createXmlElement($oDoc, 'state', $oInvoice->billingAddress()->region ?? ''),
                                $this->createXmlElement($oDoc, 'country', $oInvoice->billingAddress()->country->iso ?? ''),
                            ]),
                        ], [
                            'orderCode'      => $oInvoice->ref,
                            'installationId' => $this->getInstallationId($oCurrency->code, $bCustomerPresent),
                        ]),
                    ]),
                ], [
                    'version'      => 1.4,
                    'merchantCode' => $this->getMerchantCode($oCurrency->code, $bCustomerPresent),
                ])
            );

            dd($oDoc->saveXML());

        } catch (\Exception $e) {
            throw new DriverException(
                sprintf(
                    'Failed to build XML document. [%s] %s – %s',
                    get_class($e),
                    $e->getCode(),
                    $e->getMessage(),
                )
            );
        }

        //  @todo (Pablo - 2019-07-24) - Implement this method
        throw new NailsException('Method ' . __METHOD__ . ' not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new XML Document
     *
     * @return \DOMDocument
     */
    protected function createXmlDocument(): \DOMDocument
    {
        $oImp = new \DOMImplementation();
        $oDoc = $oImp->createDocument(
            'paymentService',
            null,
            $oImp->createDocumentType(
                'paymentService',
                '-//Worldpay//DTD Worldpay PaymentService v1//EN',
                'http://dtd.worldpay.com/paymentService_v1.dtd'
            )
        );

        $oDoc->xmlVersion         = '1.0';
        $oDoc->encoding           = 'UTF-8';
        $oDoc->preserveWhiteSpace = false;
        $oDoc->formatOutput       = true;

        return $oDoc;
    }

    // --------------------------------------------------------------------------

    /**
     * Utility method for creating XML nodes
     *
     * @param \DOMDocument         $oXml        The main XML document
     * @param string               $sNode       The node type
     * @param string|\DOMElement[] $mValue      The node's value, or array of child nodes
     * @param string[]             $aAttributes Array of attributes for the node
     *
     * @return \DOMElement
     */
    protected function createXmlElement(
        \DOMDocument $oXml,
        string $sNode,
        $mValue = '',
        array $aAttributes = []
    ): \DOMElement {

        $oNode = $oXml->createElement($sNode);

        if (is_array($mValue)) {
            foreach ($mValue as $oChild) {
                $oNode->appendChild($oChild);
            }
        } else {
            $oNode->nodeValue = $mValue;
        }

        foreach ($aAttributes as $sKey => $sValue) {
            $oNode->setAttribute($sKey, $sValue);
        }

        return $oNode;
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
        throw new NailsException('Method ' . __METHOD__ . ' not implemented');
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
        throw new NailsException('Method ' . __METHOD__ . ' not implemented');
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
        throw new NailsException('Method ' . __METHOD__ . ' not implemented');
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
        $sToken = getFromArray('worldpay_token', $aData);
        if (empty($sToken)) {
            throw new DriverException('"worldpay_token" must be supplied when creating a WorldPay payment source.');
        }

        $oResource->data = (object) [
            'token' => $sToken,
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Updates a payment source on the gateway
     *
     * @param Resource\Source $oResource The Resource being updated
     */
    public function updateSource(
        Resource\Source $oResource
    ): void {
        throw new DriverException(
            static::PAYMENT_SOURCES_ERROR
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes a payment source from the gateway
     *
     * @param Resource\Source $oResource The Resource being deleted
     */
    public function deleteSource(
        Resource\Source $oResource
    ): void {
        //  Nothing to do
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
        throw new NailsException('Method ' . __METHOD__ . ' not implemented');
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
        throw new NailsException('Method ' . __METHOD__ . ' not implemented');
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
        throw new NailsException('Method ' . __METHOD__ . ' not implemented');
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
        throw new NailsException('Method ' . __METHOD__ . ' not implemented');
    }

    // --------------------------------------------------------------------------

    public function getConfig(): array
    {
        return json_decode($this->getSetting('aConfig')) ?? [];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a config property for a given currency/customer present combo
     *
     * @param string $sProperty
     * @param string $sCurrency
     * @param bool   $bCustomerPresent
     *
     * @return mixed
     */
    protected function getConfigProperty(string $sProperty, string $sCurrency, bool $bCustomerPresent = true)
    {
        $aConfig = $this->getConfig();
        $aConfig = array_filter($aConfig, function (\stdClass $oConfig) use ($sCurrency, $bCustomerPresent) {
            return $oConfig->for_currency === $sCurrency && $bCustomerPresent === $oConfig->customer_present;
        });

        $mValue = reset($aConfig)->{$sProperty} ?? null;

        if (empty($mValue)) {
            throw new DriverException(
                sprintf(
                    'Unable to ascertain property `%s` for currency: %s with customer present: %s',
                    $sProperty,
                    $sCurrency,
                    json_encode($bCustomerPresent)
                )
            );
        }

        return $mValue;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the merchant_code for a given currency/customer present combo
     *
     * @param string $sCurrency        The currency to query
     * @param bool   $bCustomerPresent Whether the customer is present
     *
     * @return string|null
     */
    public function getMerchantCode(string $sCurrency, bool $bCustomerPresent = true): ?string
    {
        return (string) $this->getConfigProperty('merchant_code', $sCurrency, $bCustomerPresent) ?: null;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the installation_id for a given currency/customer present combo
     *
     * @param string $sCurrency        The currency to query
     * @param bool   $bCustomerPresent Whether the customer is present
     *
     * @return int|null
     */
    public function getInstallationId(string $sCurrency, bool $bCustomerPresent = true): ?string
    {
        return (int) $this->getConfigProperty('installation_id', $sCurrency, $bCustomerPresent) ?: null;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the xml_username for a given currency/customer present combo
     *
     * @param string $sCurrency        The currency to query
     * @param bool   $bCustomerPresent Whether the customer is present
     *
     * @return string|null
     */
    public function getXmlUsername(string $sCurrency, bool $bCustomerPresent = true): ?string
    {
        return (string) $this->getConfigProperty('xml_username', $sCurrency, $bCustomerPresent) ?: null;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the xml_password for a given currency/customer present combo
     *
     * @param string $sCurrency        The currency to query
     * @param bool   $bCustomerPresent Whether the customer is present
     *
     * @return string|null
     */
    public function getXmlPassword(string $sCurrency, bool $bCustomerPresent = true): ?string
    {
        return (string) $this->getConfigProperty('xml_password', $sCurrency, $bCustomerPresent) ?: null;
    }
}
