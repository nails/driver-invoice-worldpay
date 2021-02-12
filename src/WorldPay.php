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
use Nails\Common\Factory\HttpRequest\Post;
use Nails\Common\Service\HttpCodes;
use Nails\Common\Service\Input;
use Nails\Currency\Resource\Currency;
use Nails\Environment;
use Nails\Factory;
use Nails\Invoice\Constants;
use Nails\Invoice\Driver\Payment\Exceptions\Api\AuthenticationException;
use Nails\Invoice\Driver\Payment\Exceptions\Api\ParseException;
use Nails\Invoice\Driver\Payment\Exceptions\WorldPayException;
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
    const WP_ENDPOINT_TEST      = 'https://secure-test.worldpay.com/jsp/merchant/xml/paymentService.jsp';
    const WP_ENDPOINT_LIVE      = 'https://secure.worldpay.com/jsp/merchant/xml/paymentService.jsp';
    const PAYMENT_SOURCES_ERROR = 'Payment Sources are not supported by the WorldPay driver';
    const CUSTOMERS_ERROR       = 'Customers are not supported by the WorldPay driver';

    //  Error Codes:
    //  https://developer.worldpay.com/docs/wpg/directintegration/quickstart#integrate
    const XML_ERROR_AUTHENTICATION          = 401;
    const XML_ERROR_PARSE                   = 2;
    const XML_ERROR_SECURITY_VIOLATION      = 4;
    const XML_ERROR_INVALID_ORDER_DETAILS   = 5;
    const XML_ERROR_INVALID_PAYMENT_DETAILS = 7;

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

        //  @todo (Pablo 12/02/2021) - handle CVC if passed

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var ChargeResponse $oChargeResponse */
        $oChargeResponse = Factory::factory('ChargeResponse', Constants::MODULE_SLUG);

        try {

            $oDoc = $this->createXmlDocument();

            $oDoc->appendChild(
                $this->createXmlElement($oDoc, 'paymentService', [
                    $this->createXmlElement($oDoc, 'submit', [
                        $this->createXmlElement($oDoc, 'order', [
                            $this->createXmlElement($oDoc, 'description', 'Payment for invoice #' . $oInvoice->ref),
                            $this->createXmlElement($oDoc, 'amount', '', [
                                'currencyCode' => $oCurrency->code,
                                'exponent'     => $oCurrency->decimal_precision,
                                'value'        => $iAmount,
                            ]),
                            $this->createXmlElement($oDoc, 'paymentDetails', [
                                $this->getPaymentXml($oDoc, $oSource),
                                $this->createXmlElement($oDoc, 'session', null, [
                                    'shopperIPAddress' => $oInput->ipAddress(),
                                ]),
                            ]),
                            $this->createXmlElement($oDoc, 'shopper', [
                                $this->createXmlElement($oDoc, 'shopperEmailAddress', $oInvoice->customer()->billing_email ?: $oInvoice->customer()->email),
                                $this->createXmlElement($oDoc, 'authenticatedShopperID', $oInvoice->customer()->id),
                            ]),
                        ], ['orderCode' => $oPayment->ref]),
                    ]),
                ], [
                    'version'      => 1.4,
                    'merchantCode' => $this->getMerchantCode($oCurrency->code, $bCustomerPresent),
                ])
            );

            $oResponseDoc = $this->makeRequest($oDoc, $oCurrency, $bCustomerPresent);

            dd($oResponseDoc->saveXML());

            $oChargeResponse
                ->setStatusComplete()
                ->setTransactionId($oCharge->id)
                ->setFee($oBalanceTransaction->fee);

        } catch (AuthenticationException $e) {
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There is a configuration error preventing your payment from being processed.'
            );

        } catch (ParseException $e) {
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                sprintf(
                    'There was a problem processing your payment: %s',
                    $e->getMessage()
                )
            );

        } catch (\Exception $e) {
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem processing your payment, you may wish to try again.'
            );
        }

        dd($oChargeResponse);

        return $oChargeResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the appropriate payment XML depending on whetehr a token or card details have been supplied
     *
     * @param \DOMDocument    $oDoc
     * @param Resource\Source $oSource
     *
     * @return \DOMElement
     */
    private function getPaymentXml(\DOMDocument $oDoc, Resource\Source $oSource): \DOMElement
    {
        if ($oSource) {
            return $this->createXmlElement($oDoc, 'TOKEN-SSL', [
                $this->createXmlElement($oDoc, 'paymentTokenID', $oSource->data->token),
                $this->createXmlElement($oDoc, 'paymentInstrument', [
                    $this->createXmlElement($oDoc, 'cardDetails', [
                        $this->createXmlElement($oDoc, 'cardHolderName', 'REFUSED'),
                    ]),
                ]),
            ], ['tokenScope' => 'shopper']);

        } else {
            //  @todo (Pablo 12/02/2021) - populate values
            return $this->createXmlElement($oDoc, 'CARD-SSL', [
                $this->createXmlElement($oDoc, 'cardNumber'),
                $this->createXmlElement($oDoc, 'expiryDate'),
                $this->createXmlElement($oDoc, 'cardHolderName'),
                $this->createXmlElement($oDoc, 'cardAddress', [
                    $this->createXmlElement($oDoc, 'address1'),
                    $this->createXmlElement($oDoc, 'address2'),
                    $this->createXmlElement($oDoc, 'address3'),
                    $this->createXmlElement($oDoc, 'postalCode'),
                    $this->createXmlElement($oDoc, 'city'),
                    $this->createXmlElement($oDoc, 'state'),
                    $this->createXmlElement($oDoc, 'countryCode'),
                ]),
            ]);
        }
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

    private function getNodeAtPath(\DOMDocument $oDoc, string $sPath): \DOMElement
    {
        dd($oDoc->saveXML());
        $aPath     = explode('.', $sPath);
        $sRootNode = array_shift($aPath);

        $oRootNode = $oDoc->getElementsByTagName($sRootNode)->item(0);
        if (empty($oRootNode)) {
            throw new DriverException('Failed to parse XML path; missing root node "' . $sRootNode . '"');
        }

        $aCurrentPath  = [$sRootNode];
        $oPreviousNode = $oRootNode;

        foreach ($aPath as $sNodeName) {

            $aCurrentPath[] = $sNodeName;
            $oCurrentNode   = null;

            foreach ($oPreviousNode->childNodes as $oChildNode) {
                if ($oChildNode->tagName === $sNodeName) {
                    $oCurrentNode = $oChildNode;
                    break;
                }
            }

            if (empty($oCurrentNode)) {
                throw new DriverException(sprintf(
                    'Failed to parse XML path; missing node at path "%s"',
                    implode('.', $aCurrentPath)
                ));
            }

            $oPreviousNode = $oCurrentNode;
        }

        return $oPreviousNode;
    }

    private function makeRequest(\DOMDocument $oDoc, Currency $oCurrency, bool $bCustomerPresent): \DOMDocument
    {
        /** @var Post $oHttpPost */
        $oHttpPost = Factory::factory('HttpRequestPost');
        $oHttpPost
            ->baseUri(
                Environment::is(Environment::ENV_PROD)
                    ? \Nails\Invoice\Driver\Payment\WorldPay::WP_ENDPOINT_LIVE
                    : \Nails\Invoice\Driver\Payment\WorldPay::WP_ENDPOINT_TEST

            )
            ->auth(
                $this->getXmlUsername($oCurrency->code, $bCustomerPresent) . 'test',
                $this->getXmlPassword($oCurrency->code, $bCustomerPresent) . 'test'
            )
            ->setHeader('Content-Type', 'text/xml')
            ->body($oDoc->saveXML(), false);

        $oHttpResponse = $oHttpPost->execute();

        if ($oHttpResponse->getStatusCode() !== HttpCodes::STATUS_OK) {
            throw new WorldPayException(
                HttpCodes::getByCode($oHttpResponse->getStatusCode()),
                $oHttpResponse->getStatusCode()
            );
        }

        //  @todo (Pablo 12/02/2021) - handle non-200

        $oResponseDoc = $this->createXmlDocument();
        $oResponseDoc->loadXML($oHttpResponse->getBody(false));

        try {

            $oErrorNode = $this->getNodeAtPath($oResponseDoc, 'paymentService.reply.error');

            switch ((int) $oErrorNode->attributes->getNamedItem('code')->nodeValue) {
                case static::XML_ERROR_AUTHENTICATION:
                case static::XML_ERROR_SECURITY_VIOLATION:
                    throw new AuthenticationException(
                        'Incorrect credentials supplied',
                        $oErrorNode->attributes->getNamedItem('code')->nodeValue
                    );

                case static::XML_ERROR_PARSE:
                case static::XML_ERROR_INVALID_ORDER_DETAILS:
                case static::XML_ERROR_INVALID_PAYMENT_DETAILS:
                    throw new ParseException(
                        $oErrorNode->nodeValue,
                        $oErrorNode->attributes->getNamedItem('code')->nodeValue
                    );
                    break;

                default:
                    throw new DriverException(
                        $oErrorNode->nodeValue,
                        $oErrorNode->attributes->getNamedItem('code')->nodeValue
                    );
            }

        } catch (\Exception $e) {
            //  No error node detected
        }

        $oPaymentServiceNode = $oResponseDoc->getElementsByTagName('paymentService')->item(0);
        if (empty($oPaymentServiceNode)) {
            throw new DriverException('Failed to parse response from WorldPay; missing paymentService node');
        }

        $oReplyNode = $oPaymentServiceNode->childNodes->item(0);
        if (empty($oReplyNode) || $oReplyNode->tagName !== 'reply') {
            throw new DriverException('Failed to parse response from WorldPay; missing reply node');
        }

        $oErrorNode = $oReplyNode->childNodes->item(0);
        if (!empty($oErrorNode) && $oErrorNode->tagName === 'error') {

            switch ($oErrorNode->attributes->getNamedItem('code')->nodeValue) {
                case '401':
                case '5':
                    throw new AuthenticationException(
                        'Incorrect credentials supplied',
                        $oErrorNode->attributes->getNamedItem('code')->nodeValue
                    );

                case '2':
                case '7':
                    throw new ParseException(
                        $oErrorNode->nodeValue,
                        $oErrorNode->attributes->getNamedItem('code')->nodeValue
                    );
                    break;

                default:
                    throw new DriverException(
                        $oErrorNode->nodeValue,
                        $oErrorNode->attributes->getNamedItem('code')->nodeValue
                    );
            }
        }

        return $oResponseDoc;
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
     *
     * @throws DriverException
     */
    public function createCustomer(array $aData = [])
    {
        throw new DriverException(
            static::CUSTOMERS_ERROR
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for retrieving an existing customer from the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     * @param array $aData       Any driver specific data
     *
     * @throws DriverException
     */
    public function getCustomer($mCustomerId, array $aData = [])
    {
        throw new DriverException(
            static::CUSTOMERS_ERROR
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for updating an existing customer on the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     * @param array $aData       The driver specific customer data
     *
     * @throws DriverException
     */
    public function updateCustomer($mCustomerId, array $aData = [])
    {
        throw new DriverException(
            static::CUSTOMERS_ERROR
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for deleting an existing customer on the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     *
     * @throws DriverException
     */
    public function deleteCustomer($mCustomerId)
    {
        throw new DriverException(
            static::CUSTOMERS_ERROR
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the decoded config array
     *
     * @return array
     */
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
