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

use Firebase\JWT\JWT;
use Nails\Address\Resource\Address;
use Nails\Common\Exception\Encrypt\DecodeException;
use Nails\Common\Exception\EnvironmentException;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Factory\HttpRequest\Post;
use Nails\Common\Factory\HttpResponse;
use Nails\Common\Resource\DateTime;
use Nails\Common\Service\Encrypt;
use Nails\Common\Service\HttpCodes;
use Nails\Common\Service\Input;
use Nails\Common\Service\Session;
use Nails\Config;
use Nails\Currency\Resource\Currency;
use Nails\Environment;
use Nails\Factory;
use Nails\Invoice\Constants;
use Nails\Invoice\Driver\Payment\WorldPay\Log;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Driver\Payment\WorldPay\Ddc;
use Nails\Invoice\Driver\Payment\WorldPay\Exceptions\Api\AuthenticationException;
use Nails\Invoice\Driver\Payment\WorldPay\Exceptions\Api\ParseException;
use Nails\Invoice\Driver\Payment\WorldPay\Exceptions\ConfigException;
use Nails\Invoice\Driver\Payment\WorldPay\Exceptions\WorldPayException;
use Nails\Invoice\Driver\Payment\WorldPay\Exceptions\Xml\NodeNotFoundException;
use Nails\Invoice\Driver\Payment\WorldPay\Sca;
use Nails\Invoice\Driver\Payment\WorldPay\Settings;
use Nails\Invoice\Driver\Payment\WorldPay\ThreeDSChallenge;
use Nails\Invoice\Exception\ResponseException;
use Nails\Invoice\Factory\ChargeResponse;
use Nails\Invoice\Factory\CompleteResponse;
use Nails\Invoice\Factory\Invoice\PaymentData;
use Nails\Invoice\Factory\RefundResponse;
use Nails\Invoice\Factory\ResponseBase;
use Nails\Invoice\Factory\ScaResponse;
use Nails\Invoice\Model\Invoice;
use Nails\Invoice\Model\Payment;
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
     * WorldPay API Endpoints
     */
    const WP_ENDPOINT_TEST = 'https://secure-test.worldpay.com/jsp/merchant/xml/paymentService.jsp';
    const WP_ENDPOINT_LIVE = 'https://secure.worldpay.com/jsp/merchant/xml/paymentService.jsp';

    /**
     * XML Request constants
     */
    const PAYMENT_SERVICE_VERSION = 1.4;

    /**
     * XML Error codes
     * https://developer.worldpay.com/docs/wpg/troubleshoot#common-error-codes
     */
    const XML_ERROR_AUTHENTICATION          = 401;
    const XML_ERROR_GENERAL                 = 1;
    const XML_ERROR_PARSE                   = 2;
    const XML_ERROR_AMOUNT_INVALID          = 3;
    const XML_ERROR_SECURITY_VIOLATION      = 4;
    const XML_ERROR_INVALID_ORDER_DETAILS   = 5;
    const XML_ERROR_INVALID_BATCH           = 6;
    const XML_ERROR_INVALID_PAYMENT_DETAILS = 7;
    const XML_ERROR_SERVICE_UNAVAILABLE     = 8;

    /**
     * XML Transaction states
     */
    const XML_TRANSACTION_AUTHORISED           = 'AUTHORISED';
    const XML_TRANSACTION_REFUSED              = 'REFUSED';
    const XML_TRANSACTION_CANCELLED            = 'CANCELLED';
    const XML_TRANSACTION_CAPTURED             = 'CAPTURED';
    const XML_TRANSACTION_PARTIALLY_CAPTURED   = 'PARTIALLY_CAPTURED';
    const XML_TRANSACTION_SETTLED              = 'SETTLED';
    const XML_TRANSACTION_SETTLED_BY_MERCHANT  = 'SETTLED_BY_MERCHANT';
    const XML_TRANSACTION_SENT_FOR_REFUND      = 'SENT_FOR_REFUND';
    const XML_TRANSACTION_REFUNDED             = 'REFUNDED';
    const XML_TRANSACTION_REFUNDED_BY_MERCHANT = 'REFUNDED_BY_MERCHANT';
    const XML_TRANSACTION_REFUND_FAILED        = 'REFUND_FAILED';
    const XML_TRANSACTION_CHARGED_BACK         = 'CHARGED_BACK';
    const XML_TRANSACTION_ERROR                = 'ERROR';

    /**
     * User facing error messages for request exceptions
     */
    const REQUEST_ERROR_AUTH          = 'There is a configuration error preventing your payment from being processed.';
    const REQUEST_PAYMENT_ERROR_PARSE = 'There was a problem processing your payment: %s';
    const REQUEST_PAYMENT_ERROR_OTHER = 'There was a problem processing your payment, you may wish to try again.';
    const REQUEST_REFUND_ERROR_PARSE  = 'There was a problem processing your refund: %s';
    const REQUEST_REFUND_ERROR_OTHER  = 'There was a problem processing your refund, you may wish to try again.';

    /**
     * Other error messages
     */
    const PAYMENT_SOURCES_ERROR = '%s Payment Sources is not supported by the WorldPay driver';
    const CUSTOMERS_ERROR       = '%s Customers is not supported by the WorldPay driver';

    /**
     * Session
     */
    const SESSION_KEY = 'WORLDPAY_SESSION_ID';

    // --------------------------------------------------------------------------

    /** @var \Nails\Invoice\Driver\Payment\WorldPay\Log */
    protected $oLog;

    /** @var string */
    protected $sLogRef;

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
        $aConfig = $this->getConfig();
        if (is_null($aConfig)) {
            return null;
        }

        return array_map(function ($oItem) {
            return strtoupper($oItem->for_currency);
        }, $aConfig);
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

        /** @var ChargeResponse $oChargeResponse */
        $oChargeResponse = Factory::factory('ChargeResponse', Constants::MODULE_SLUG);

        $this
            ->setLogRef($oPayment->ref)
            ->log('Initiating charge')
            ->log('Current URL: %s', [$this->getCurrentUrl()]);

        if (!empty($oPaymentData->ddc_session_id)) {
            $this
                ->chargeHandle3ds(
                    $oChargeResponse,
                    $iAmount,
                    $oCurrency,
                    $oPaymentData,
                    $oPayment,
                    $oInvoice,
                    $bCustomerPresent,
                    $oSource
                );

        } else {
            $this
                ->chargeHandleCharge(
                    $oChargeResponse,
                    $iAmount,
                    $oCurrency,
                    $oPaymentData,
                    $oPayment,
                    $oInvoice,
                    $bCustomerPresent,
                    $oSource
                );
        }

        //  Hide sensitive fields
        $this->obfuscateCvcInPaymentData($oPaymentData, $oPayment);

        $this->log('Returning control to caller');

        return $oChargeResponse;
    }

    // --------------------------------------------------------------------------

    protected function chargeHandle3ds(
        ChargeResponse $oChargeResponse,
        int $iAmount,
        Currency $oCurrency,
        Resource\Invoice\Data\Payment $oPaymentData,
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice,
        bool $bCustomerPresent,
        Resource\Source $oSource = null
    ): void {

        $this->log('Handling 3DS charge');

        $oScaData = new WorldPay\Sca\Data(
            $oInvoice,
            $oCurrency,
            $iAmount,
            $oSource,
            $oPayment,
            clone $oPaymentData, // Clone allows CVC data (if supplied) to persist
            $bCustomerPresent
        );

        $this
            ->log('Marking Charge response object as SCA with the following data:')
            ->log((string) $oScaData);

        $oChargeResponse
            ->setIsSca($oScaData->toArray());
    }

    // --------------------------------------------------------------------------

    /**
     * Performs a charge
     *
     * @param ChargeResponse                $oChargeResponse
     * @param int                           $iAmount
     * @param Currency                      $oCurrency
     * @param Resource\Invoice\Data\Payment $oPaymentData
     * @param Resource\Payment              $oPayment
     * @param Resource\Invoice              $oInvoice
     * @param bool                          $bCustomerPresent
     * @param Resource\Source|null          $oSource
     *
     * @throws ResponseException
     */
    protected function chargeHandleCharge(
        ChargeResponse $oChargeResponse,
        int $iAmount,
        Currency $oCurrency,
        Resource\Invoice\Data\Payment $oPaymentData,
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice,
        bool $bCustomerPresent,
        Resource\Source $oSource = null
    ): void {
        try {

            $this->log('Handling regular charge');

            $oResponse = $this
                ->makeRequest(
                    $this->buildChargeXml(
                        $oInvoice,
                        $oCurrency,
                        $iAmount,
                        $oSource,
                        $oPayment,
                        $oPaymentData,
                        $bCustomerPresent
                    ),
                    $oCurrency,
                    $bCustomerPresent
                );

            $this->processLastEvent($oResponse, $oChargeResponse, $oPayment);

        } catch (AuthenticationException $e) {
            $oChargeResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    static::REQUEST_ERROR_AUTH
                );

        } catch (ParseException $e) {
            $oChargeResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    sprintf(static::REQUEST_PAYMENT_ERROR_PARSE, $e->getMessage())
                );

        } catch (\Exception $e) {
            $oChargeResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    static::REQUEST_PAYMENT_ERROR_OTHER
                );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Builds the XML for making a charge
     *
     * @param Resource\Invoice $oInvoice
     * @param Currency         $oCurrency
     * @param int              $iAmount
     * @param Resource\Source  $oSource
     * @param Resource\Payment $oPayment
     * @param PaymentData      $oPaymentData
     * @param bool             $bCustomerPresent
     *
     * @return \DOMDocument
     * @throws FactoryException
     * @throws \Nails\Common\Exception\ModelException
     */
    private function buildChargeXml(
        Resource\Invoice $oInvoice,
        Currency $oCurrency,
        int $iAmount,
        ?Resource\Source $oSource,
        Resource\Payment $oPayment,
        Resource\Invoice\Data\Payment $oPaymentData,
        bool $bCustomerPresent
    ): \DOMDocument {

        $this->log('Building Charge XML');

        /** @var Input $oInput */
        $oInput = Factory::service('Input');

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
                            $this->getPaymentXml($oDoc, $oSource, $oPaymentData),
                            $this->createXmlElement($oDoc, 'session', null, array_filter([
                                'shopperIPAddress' => $oInput->ipAddress(),
                                'id'               => $this->getSessionId($oPayment),
                            ])),
                        ]),
                        $this->createXmlElement($oDoc, 'shopper', [
                            $this->createXmlElement($oDoc, 'shopperEmailAddress', $oInvoice->customer()->billing_email ?: $oInvoice->customer()->email),
                            $this->createXmlElement($oDoc, 'authenticatedShopperID', $oInvoice->customer()->id),
                            $this->createXmlElement($oDoc, 'browser', [
                                $this->createXmlElement($oDoc, 'acceptHeader', $oInput->server('HTTP_ACCEPT')),
                                $this->createXmlElement($oDoc, 'userAgentHeader', $oInput->server('HTTP_USER_AGENT')),
                            ]),
                        ]),
                    ], ['orderCode' => $oPayment->ref]),
                ]),
            ], [
                'version'      => static::PAYMENT_SERVICE_VERSION,
                'merchantCode' => $this->getMerchantCode($oCurrency->code, $bCustomerPresent),
            ])
        );

        return $oDoc;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the appropriate payment XML depending on whetehr a token or card details have been supplied
     *
     * @param \DOMDocument    $oDoc
     * @param Resource\Source $oSource
     * @param PaymentData     $oPaymentData
     *
     * @return \DOMElement
     */
    private function getPaymentXml(
        \DOMDocument $oDoc,
        ?Resource\Source $oSource,
        Resource\Invoice\Data\Payment $oPaymentData
    ): \DOMElement {

        if ($oSource || !empty($oPaymentData->worldpay_token)) {

            //  Support paying with a token passed as a payment source, or as payment data
            $sPaymentToken = $oPaymentData->worldpay_token ?? $oSource->data->token;

            /**
             * Allow values to be overridden at payment time. The order of these elements
             * is important, WorldPay will complain if they are received in the wrong order:
             *
             * 1. expiryDate
             * 2. cardHolderName
             * 3. cvc
             * 4. cardAddress
             */

            $aOverrides = array_filter([
                $this->getCardExpiryDateXml($oDoc, $oPaymentData->expiryDate ?? null),
                $this->getCardHolderNameXml($oDoc, $oPaymentData->cardHolderName ?? null),
                $this->getCardCvcXml($oDoc, $oPaymentData->cvc ?? null),
                $this->getCardAddressXml($oDoc, $oPaymentData->cardAddress ?? null),
            ]);

            if (!empty($aOverrides)) {
                $oPaymentInstrument = $this->createXmlElement($oDoc, 'paymentInstrument', [
                    $this->createXmlElement($oDoc, 'cardDetails', $aOverrides),
                ]);
            }

            return $this->createXmlElement($oDoc, 'TOKEN-SSL', array_filter([
                $this->createXmlElement($oDoc, 'paymentTokenID', $sPaymentToken),
                $oPaymentInstrument ?? null,
            ]), ['tokenScope' => 'shopper']);

        } elseif (!empty($oPaymentData->cardNumber)) {

            return $this->createXmlElement($oDoc, 'CARD-SSL', array_filter([
                $this->getCardNumberXml($oDoc, $oPaymentData->cardNumber),
                $this->getCardExpiryDateXml($oDoc, $oPaymentData->expiryDate ?? null),
                $this->getCardHolderNameXml($oDoc, $oPaymentData->cardHolderName ?? null),
                $this->getCardCvcXml($oDoc, $oPaymentData->cvc ?? null),
                $this->getCardAddressXml($oDoc, $oPaymentData->cardAddress ?? null),
            ]));

        } else {

            throw new WorldPayException(
                'Must provide a payment source, or `worldpay_token`.'
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the XML for the cardNumber field
     *
     * @param \DOMDocument $oDoc
     * @param              $sCardNumber
     *
     * @return \DOMElement
     */
    protected function getCardNumberXml(\DOMDocument $oDoc, $sCardNumber): \DOMElement
    {
        return $this->createXmlElement($oDoc, 'cardNumber', $sCardNumber);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the XML for the card expiryDate field
     *
     * @param \DOMDocument $oDoc
     * @param              $mDate
     *
     * @return \DOMElement|null
     */
    protected function getCardExpiryDateXml(\DOMDocument $oDoc, $mDate): ?\DOMElement
    {
        if ($mDate instanceof \Datetime) {
            $oDate = $mDate;

        } elseif ($mDate instanceof DateTime) {
            $oDate = $mDate->getDateTimeObject();

        } elseif (is_string($mDate)) {
            $oDate = $this->parseDateString($mDate);
        }

        if (empty($oDate)) {
            return null;
        }

        return $this->createXmlElement($oDoc, 'expiryDate', [
            $this->createXmlElement($oDoc, 'date', null, [
                'month' => $oDate->format('m'),
                'year'  => $oDate->format('Y'),
            ]),
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the XML for the cardHolderName field
     *
     * @param \DOMDocument $oDoc
     * @param              $sCardHolderName
     *
     * @return \DOMElement|null
     */
    protected function getCardHolderNameXml(\DOMDocument $oDoc, $sCardHolderName): ?\DOMElement
    {
        if (empty($sCardHolderName)) {
            return null;
        }

        return $this->createXmlElement($oDoc, 'cardHolderName', $sCardHolderName);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the XML for the card CVC field
     *
     * @param \DOMDocument $oDoc
     * @param              $sCardCvc
     *
     * @return \DOMElement|null
     */
    protected function getCardCvcXml(\DOMDocument $oDoc, $sCardCvc): ?\DOMElement
    {
        if (empty($sCardCvc)) {
            return null;
        }

        return $this->createXmlElement($oDoc, 'cvc', $sCardCvc);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the XML for the cardAddress field
     *
     * @param \DOMDocument $oDoc
     * @param              $oCardAddress
     *
     * @return \DOMElement|null
     */
    protected function getCardAddressXml(\DOMDocument $oDoc, $oCardAddress): ?\DOMElement
    {
        if (!$oCardAddress instanceof Address) {
            return null;
        }

        return $this->createXmlElement($oDoc, 'cardAddress', [
            $this->createXmlElement($oDoc, 'address', [
                $this->createXmlElement($oDoc, 'address1', $oCardAddress->line_1),
                $this->createXmlElement($oDoc, 'address2', $oCardAddress->line_2),
                $this->createXmlElement($oDoc, 'address3', $oCardAddress->line_3),
                $this->createXmlElement($oDoc, 'postalCode', $oCardAddress->postcode),
                $this->createXmlElement($oDoc, 'city', $oCardAddress->town),
                $this->createXmlElement($oDoc, 'state', $oCardAddress->region),
                $this->createXmlElement($oDoc, 'countryCode', $oCardAddress->country->iso),
            ]),
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Safely parse a datetime string
     *
     * @param string $sDate
     *
     * @return \DateTime|null
     */
    private function parseDateString(string $sDate)
    {
        try {
            return new \DateTime($sDate);
        } catch (\Exception $e) {
            return null;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * @param \DOMDocument|\DOMElement   $oDoc
     * @param ChargeResponse|ScaResponse $oResponse
     * @param Resource\Payment           $oPayment
     */
    protected function processLastEvent($oDoc, ResponseBase $oResponse, Resource\Payment $oPayment)
    {
        try {

            $this->log('Processing last event');

            $oLastEventNode = $this->getNodeAtPath(
                $oDoc,
                'paymentService.reply.orderStatus.payment.lastEvent'
            );

            switch ($oLastEventNode->nodeValue) {
                case static::XML_TRANSACTION_AUTHORISED:

                    $this
                        ->log('Marking as complete')
                        ->log('Setting transaction ID: %s', [$oPayment->ref]);

                    $oResponse
                        ->setStatusComplete()
                        ->setTransactionId($oPayment->ref);
                    break;

                case static::XML_TRANSACTION_REFUSED:

                    $this->log('Marking as failed (decline)');
                    $oResponse
                        ->setStatusFailed(
                            'Card was declined',
                            static::XML_TRANSACTION_REFUSED,
                            'Your card was declined.'
                        );
                    break;

                case static::XML_TRANSACTION_ERROR:

                    $this->log('Marking as failed (error)');
                    $oResponse
                        ->setStatusFailed(
                            'An error occurred',
                            static::XML_TRANSACTION_REFUSED,
                            'An error occurred, you have not been charged.'
                        );
                    break;
            }

        } catch (NodeNotFoundException $e) {

            $this->log('Marking as failed (error)');

            $oResponse
                ->setStatusFailed(
                    'An error occurred, `paymentService.reply.orderStatus.payment.lastEvent` node not found',
                    static::XML_TRANSACTION_REFUSED,
                    static::REQUEST_PAYMENT_ERROR_OTHER
                );
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

    /**
     * Returns the node at a given path in an XML document using dot notation
     *
     * @param \DOMDocument|\DOMElement $oDoc  The document to inspect
     * @param string                   $sPath The path to retrieve
     *
     * @return \DOMElement
     * @throws NodeNotFoundException
     */
    private function getNodeAtPath($oDoc, string $sPath): \DOMElement
    {
        $this
            ->log('Searching the following document for node: %s', [$sPath])
            ->log(
                $oDoc instanceof \DOMDocument
                    ? $oDoc->saveXML()
                    : $oDoc->ownerDocument->saveHTML($oDoc)
            );

        $aPath     = explode('.', $sPath);
        $sRootNode = array_shift($aPath);

        $oRootNode = $oDoc->getElementsByTagName($sRootNode)->item(0);
        if (empty($oRootNode)) {
            $this->log('Node not found');
            throw new NodeNotFoundException('Failed to parse XML path; missing root node "' . $sRootNode . '"');
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
                $this->log('Node not found');
                throw new NodeNotFoundException(sprintf(
                    'Failed to parse XML path; missing node at path "%s"',
                    implode('.', $aCurrentPath)
                ));
            }

            $oPreviousNode = $oCurrentNode;
        }

        $this->log('Node found with value: %s', [$oPreviousNode->nodeValue]);

        return $oPreviousNode;
    }

    // --------------------------------------------------------------------------

    /**
     * Performs the API Request to the WP endpoint
     *
     * @param \DOMDocument $oDoc             The XML document to send
     * @param Currency     $oCurrency        The currency being processed
     * @param bool         $bCustomerPresent Whether the customer is present or not
     *
     * @return \DOMDocument
     * @throws AuthenticationException
     * @throws WorldPayException
     * @throws ParseException
     * @throws WorldPayException
     * @throws FactoryException
     */
    private function makeRequest(\DOMDocument $oDoc, Currency $oCurrency, bool $bCustomerPresent = true, string $sMachineCookie = null): \DOMDocument
    {
        $this->log('Preparing API request');

        /** @var Post $oHttpPost */
        $oHttpPost = Factory::factory('HttpRequestPost');
        $oHttpPost
            ->baseUri(
                Environment::is(Environment::ENV_PROD)
                    ? \Nails\Invoice\Driver\Payment\WorldPay::WP_ENDPOINT_LIVE
                    : \Nails\Invoice\Driver\Payment\WorldPay::WP_ENDPOINT_TEST

            )
            ->auth(
                $this->getXmlUsername($oCurrency->code, $bCustomerPresent),
                $this->getXmlPassword($oCurrency->code, $bCustomerPresent)
            )
            ->setHeader('Content-Type', 'text/xml')
            ->body($oDoc->saveXML(), false);

        if (!empty($sMachineCookie)) {
            $oHttpPost
                ->setHeader('Cookie', $sMachineCookie);
        }

        $this
            ->log('Executing API request')
            ->log('Request URI: %s', [$oHttpPost->getBaseUri()])
            ->log('Request Headers:')
            ->log(json_encode($oHttpPost->getHeaders(), JSON_PRETTY_PRINT))
            ->log('Request Body:')
            ->log($oDoc->saveXML());

        $oHttpResponse = $oHttpPost->execute();

        $this
            ->log('Response received')
            ->log('Response Code: %s', [$oHttpResponse->getStatusCode()])
            ->log('Response Headers:')
            ->log(json_encode($oHttpResponse->getHeaders(), JSON_PRETTY_PRINT))
            ->log('Response Body:')
            ->log($oHttpResponse->getBody(false));

        if ($oHttpResponse->getStatusCode() !== HttpCodes::STATUS_OK) {
            throw new WorldPayException(
                sprintf(
                    'Recieved a non-200 response from WorldPay API: [%s] %s',
                    $oHttpResponse->getStatusCode(),
                    HttpCodes::getByCode($oHttpResponse->getStatusCode())
                ),
                $oHttpResponse->getStatusCode()
            );
        }

        $oResponse = $this->createXmlDocument();
        $oResponse->loadXML($oHttpResponse->getBody(false));

        $this
            ->setResponseMachineCode($oHttpResponse, $oResponse)
            ->handleRequestResponseError($oResponse);

        return $oResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Sets the Machine cookie from the request as ana ttribute of the response XML
     *
     * @param HttpResponse $oHttpResponse
     * @param \DOMDocument $oResponse
     *
     * @return $this
     */
    private function setResponseMachineCode(HttpResponse $oHttpResponse, \DOMDocument $oResponse): self
    {
        $this->log('Extract machine cookie from response');

        //  Add the Machine Cookie as part of the document
        $aMachineCookie = array_filter($oHttpResponse->getHeader('Set-Cookie'), function ($sCookie) {
            return preg_match('/^machine=/', $sCookie);
        });
        $sMachineCookie = reset($aMachineCookie);

        $oResponse->getElementsByTagName('paymentService')->item(0)->setAttribute('machineCookie', $sMachineCookie);

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Extracts the error node from a document if there is one
     *
     * @param \DOMDocument $oResponse
     *
     * @return \DOMElement|null
     */
    private function extractErrorNode(\DOMDocument $oResponse): ?\DOMElement
    {
        $this->log('Look for error identifiers in response [%s or %s]', [
            'paymentService.reply.error',
            'paymentService.reply.orderStatus.error',
        ]);

        try {

            $oNode = $this->getNodeAtPath($oResponse, 'paymentService.reply.error');

        } catch (NodeNotFoundException $e) {
            try {

                $oNode = $this->getNodeAtPath($oResponse, 'paymentService.reply.orderStatus.error');

            } catch (NodeNotFoundException $e) {
                $this->log('No errors detected');
            }
        }

        return $oNode ?? null;
    }

    // --------------------------------------------------------------------------

    /**
     * Checks a response for an error
     *
     * @param \DOMDocument $oResponse
     *
     * @return $this
     * @throws AuthenticationException
     * @throws WorldPayException
     * @throws ParseException
     */
    private function handleRequestResponseError(\DOMDocument $oResponse): self
    {
        /**
         * Errors can appear in a couple of places. Test until we find one, or don't.
         * - paymentService.reply.error
         * - paymentService.reply.orderStatus.error
         */

        $oErrorNode = $this->extractErrorNode($oResponse);

        if (!empty($oErrorNode)) {
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

                default:
                    throw new WorldPayException(
                        $oErrorNode->nodeValue,
                        $oErrorNode->attributes->getNamedItem('code')->nodeValue
                    );
            }
        }

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Obfuscates the CVC property in payment data if it's been passed
     *
     * @param Resource\Invoice\Data\Payment $oPaymentData The payment data object
     * @param Resource\Payment              $oPayment     The payment being handled
     *
     * @throws FactoryException
     */
    private function obfuscateCvcInPaymentData(Resource\Invoice\Data\Payment $oPaymentData, Resource\Payment $oPayment)
    {
        if (!empty($oPaymentData->cvc)) {

            $this->log('Obfuscating CVC in payment data');

            /** @var Payment $oPaymentModel */
            $oPaymentModel = Factory::model('Payment', Constants::MODULE_SLUG);

            $oPaymentData->cvc = str_repeat('*', strlen($oPaymentData->cvc));

            $oPaymentModel->update($oPayment->id, [
                'custom_data' => $oPaymentData,
            ]);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Handles any SCA requests
     *
     * @param ScaResponse               $oScaResponse The SCA Response object
     * @param Resource\Payment\Data\Sca $oData        Any saved SCA data
     * @param string                    $sSuccessUrl  The URL to redirect to after authorisation
     *
     * @return ScaResponse
     */
    public function sca(ScaResponse $oScaResponse, Resource\Payment\Data\Sca $oData, string $sSuccessUrl): ScaResponse
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');

        try {

            $oScaData = Sca\Data::buildFromPaymentData($oData);

            $this
                ->setLogRef($oScaData->getPayment()->ref)
                ->log('Handling SCA')
                ->log('Current URL: %s', [$this->getCurrentUrl()]);

            if (empty($oInput->post())) {
                $this->scaInitialPayment($oScaResponse, $oScaData);
            } else {
                $this->scaSecondPayment($oScaResponse, $oScaData, $oInput->post());
            }

        } catch (\Exception $e) {

            $this
                ->log(
                    'Caught exception: %s with code: %s and message: %s',
                    [\Exception::class, $e->getCode(), $e->getMessage()]
                )
                ->log('Marking as failed');

            $oScaResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode()
            );
        }

        $this->log('Returning control to caller');

        return $oScaResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Handles the SCA Initial Payment request
     *
     * @param ScaResponse $oScaResponse
     * @param Sca\Data    $oScaData
     *
     * @throws AuthenticationException
     * @throws WorldPayException
     * @throws FactoryException
     * @throws NodeNotFoundException
     * @throws ParseException
     * @throws WorldPayException
     * @throws ModelException
     * @throws ResponseException
     */
    private function scaInitialPayment(ScaResponse $oScaResponse, Sca\Data $oScaData): void
    {
        $this->log('Handling SCA initial payment');

        $oRequest = $this->buildChargeXml(
            $oScaData->getInvoice(),
            $oScaData->getCurrency(),
            $oScaData->getAmount(),
            $oScaData->getSource(),
            $oScaData->getPayment(),
            $oScaData->getPaymentData(),
            $oScaData->isCustomerPresent(),
        );

        $this
            ->log('Adding 3DS data to charge XML')
            ->log('Retrieving <order> node');

        //  Add 3DS data
        $oOrderNode = $this->getNodeAtPath($oRequest, 'paymentService.submit.order');

        //  Add riskData node to reduce likelihood of challenge
        //  @todo (Pablo 08/03/2021) - add authenticationRiskData node
        //  @todo (Pablo 08/03/2021) - add shopperAccountRiskData node
        //  @todo (Pablo 08/03/2021) - add transactionRiskData node

        //  Add additional3DSData node
        $a3dsData = [
            'dfReferenceId'       => $oScaData->getPaymentData()->ddc_session_id,
            'challengeWindowSize' => 'fullPage',
            'challengePreference' => 'noPreference',
        ];

        $this
            ->log('Inserting 3DS data to <order> node; data to be inserted is:')
            ->log(json_encode($a3dsData, JSON_PRETTY_PRINT));

        $oOrderNode
            ->appendChild(
                $this->createXmlElement($oRequest, 'additional3DSData', null, $a3dsData)
            );

        try {

            $oResponse = $this->makeRequest(
                $oRequest,
                $oScaData->getCurrency(),
                $oScaData->isCustomerPresent()
            );

            try {

                $this->log('Determining if challenge is required');

                $oChallengeNode = $this->getNodeAtPath(
                    $oResponse,
                    'paymentService.reply.orderStatus.challengeRequired.threeDSChallengeDetails'
                );

                $this
                    ->log('Challenge requested')
                    ->log('Building 3DS object so we may determine challenge URL');

                $oThreeDSChallenge = new ThreeDSChallenge(
                    $this->get3dsIssuer(),
                    $this->get3dsOrgUnitId(),
                    $this->getCurrentUrl(),
                    $this->getNodeAtPath($oChallengeNode, 'acsURL')->nodeValue,
                    $this->getNodeAtPath($oChallengeNode, 'payload')->nodeValue,
                    $this->getNodeAtPath($oChallengeNode, 'transactionId3DS')->nodeValue,
                    $this->get3dsMacKey()
                );

                $sChallengeUrl  = $oThreeDSChallenge::getUrl();
                $aChallengeData = [
                    'JWT' => $oThreeDSChallenge->getJwt(),
                    'MD'  => $this->extractEncryptedMachineCookie($oResponse),
                ];

                $this
                    ->log('Challenge URL:')
                    ->log($sChallengeUrl)
                    ->log('Challenge POST data:')
                    ->log(json_encode($aChallengeData, JSON_PRETTY_PRINT))
                    ->log('Marking payment as redirect');

                $oScaResponse
                    ->setIsRedirect(true)
                    ->setRedirectUrl($sChallengeUrl)
                    ->setRedirectPostData($aChallengeData);

            } catch (NodeNotFoundException $e) {

                $this->log('Challenge not requested');

                /**
                 * No challenge is required; check the lastEvent to ensure that
                 * the payment was successful.
                 */
                $this->processLastEvent($oResponse, $oScaResponse, $oScaData->getPayment());
            }

        } catch (AuthenticationException $e) {

            $this
                ->log(
                    'Caught exception: %s with code: %s and message: %s',
                    [AuthenticationException::class, $e->getCode(), $e->getMessage()]
                )
                ->log('Marking payment as failed');

            $oScaResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    static::REQUEST_ERROR_AUTH
                );

        } catch (ParseException $e) {

            $this
                ->log(
                    'Caught exception: %s with code: %s and message: %s',
                    [ParseException::class, $e->getCode(), $e->getMessage()]
                )
                ->log('Marking payment as failed');

            $oScaResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    sprintf(static::REQUEST_PAYMENT_ERROR_PARSE, $e->getMessage())
                );

        } catch (\Exception $e) {

            $this
                ->log(
                    'Caught exception: %s with code: %s and message: %s',
                    [\Exception::class, $e->getCode(), $e->getMessage()]
                )
                ->log('Marking payment as failed');

            $oScaResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    static::REQUEST_PAYMENT_ERROR_OTHER
                );
        }

        //  Hide sensitive fields
        $this->obfuscateCvcInPaymentData($oScaData->getPaymentData(), $oScaData->getPayment());
    }

    // --------------------------------------------------------------------------

    /**
     * Handles the SCA Second Payment request
     *
     * @param ScaResponse $oScaResponse
     * @param Sca\Data    $oScaData
     * @param array       $aPayload
     *
     * @throws AuthenticationException
     * @throws DecodeException
     * @throws WorldPayException
     * @throws EnvironmentException
     * @throws FactoryException
     * @throws ParseException
     * @throws ResponseException
     * @throws WorldPayException
     */
    private function scaSecondPayment(ScaResponse $oScaResponse, Sca\Data $oScaData, array $aPayload): void
    {
        $this->log('Handling SCA second payment');

        $sTransactionId = getFromArray('TransactionId', $aPayload);
        $sResponse      = getFromArray('Response', $aPayload);
        $sMachineCookie = $this->decryptMachineCookie(getFromArray('MD', $aPayload));
        $oPayment       = $oScaData->getPayment();

        $oRequest = $this->createXmlDocument();

        $oRequest->appendChild(
            $this->createXmlElement($oRequest, 'paymentService', [
                $this->createXmlElement($oRequest, 'submit', [
                    $this->createXmlElement($oRequest, 'order', [
                        $this->createXmlElement($oRequest, 'info3DSecure', [
                            $this->createXmlElement($oRequest, 'completedAuthentication'),
                        ]),
                        $this->createXmlElement($oRequest, 'session', null, ['id' => $this->getSessionId($oPayment)]),
                    ], [
                        'orderCode' => $oPayment->ref,
                    ]),
                ]),
            ], [
                'version'      => static::PAYMENT_SERVICE_VERSION,
                'merchantCode' => $this->getMerchantCode($oScaData->getCurrency()->code),
            ])
        );

        try {

            $oResponse = $this->makeRequest($oRequest, $oScaData->getCurrency(), true, $sMachineCookie);

            $this->processLastEvent($oResponse, $oScaResponse, $oScaData->getPayment());

        } catch (AuthenticationException $e) {

            $this
                ->log(
                    'Caught exception: %s with code: %s and message: %s',
                    [AuthenticationException::class, $e->getCode(), $e->getMessage()]
                )
                ->log('Marking payment as failed');

            $oScaResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    static::REQUEST_ERROR_AUTH
                );

        } catch (ParseException $e) {

            $this
                ->log(
                    'Caught exception: %s with code: %s and message: %s',
                    [ParseException::class, $e->getCode(), $e->getMessage()]
                )
                ->log('Marking payment as failed');

            $oScaResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    sprintf(static::REQUEST_PAYMENT_ERROR_PARSE, $e->getMessage())
                );

        } catch (\Exception $e) {

            $this
                ->log(
                    'Caught exception: %s with code: %s and message: %s',
                    [\Exception::class, $e->getCode(), $e->getMessage()]
                )
                ->log('Marking payment as failed');

            $oScaResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    static::REQUEST_PAYMENT_ERROR_OTHER
                );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Generates a unique session ID for this payment
     *
     * @param Resource\Payment $oPayment The payment being charged
     *
     * @return string
     * @throws FactoryException
     */
    private function getSessionId(Resource\Payment $oPayment): string
    {
        $sSessionId = $oPayment->ref;
        $this->log('Session ID is %s', [$sSessionId]);
        return $sSessionId;
    }

    // --------------------------------------------------------------------------

    /**
     * Extracts, encrypts, and encodes the machine cookie for passing to WorldPay as `MD` data
     *
     * @param \DOMDocument $oDoc the Document to extract from
     *
     * @return string
     * @throws FactoryException
     * @throws NodeNotFoundException
     * @throws EnvironmentException
     */
    private function extractEncryptedMachineCookie(\DOMDocument $oDoc): string
    {
        $this->log('Extracting encrypted machine cookie');
        /** @var Encrypt $oEncrypt */
        $oEncrypt = Factory::service('Encrypt');

        $oPaymentServiceNode = $this->getNodeAtPath($oDoc, 'paymentService');
        $sMachineCookie      = $oPaymentServiceNode->attributes->getNamedItem('machineCookie')->nodeValue;

        return base64_encode($oEncrypt->encode($sMachineCookie));
    }

    // --------------------------------------------------------------------------

    /**
     * Decodes an encrypted/encided cookie generated with extractEncryptedMachineCookie()
     *
     * @param string|null $sEncryptedCookie The encoded Cookie
     *
     * @return string|null
     * @throws EnvironmentException
     * @throws FactoryException
     * @throws DecodeException
     */
    private function decryptMachineCookie(?string $sEncryptedCookie): ?string
    {
        if (!empty($sEncryptedCookie)) {
            /** @var Encrypt $oEncrypt */
            $oEncrypt         = Factory::service('Encrypt');
            $sDecryptedCookie = $oEncrypt->decode(base64_decode($sEncryptedCookie));
        }

        return $sDecryptedCookie ?? null;
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
        /** @var CompleteResponse $oCompleteResponse */
        $oCompleteResponse = Factory::factory('CompleteResponse', Invoice\Constants::MODULE_SLUG);
        $oCompleteResponse->setStatusComplete();
        return $oCompleteResponse;
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
        Resource\Refund $oRefund,
        Resource\Invoice $oInvoice
    ): RefundResponse {

        $this
            ->log('Handling refund')
            ->log('Current URL: %s', [$this->getCurrentUrl()]);

        /** @var RefundResponse $oRefundResponse */
        $oRefundResponse = Factory::factory('RefundResponse', Constants::MODULE_SLUG);

        try {

            //  Check status of payment is CAPTURED
            $this->log('Checking status of payment');
            $oRequest = $this->createXmlDocument();
            $oRequest->appendChild(
                $this->createXmlElement($oRequest, 'paymentService', [
                    $this->createXmlElement($oRequest, 'inquiry', [
                        $this->createXmlElement($oRequest, 'orderInquiry', null, [
                            'orderCode' => $oPayment->ref,
                        ]),
                    ]),
                ], [
                    'version'      => static::PAYMENT_SERVICE_VERSION,
                    'merchantCode' => $this->getMerchantCode(
                        $oCurrency->code,
                        $oPayment->customer_present
                    ),
                ])
            );

            $oResponse = $this->makeRequest($oRequest, $oCurrency);

            $oLastEventNode = $this->getNodeAtPath($oResponse, 'paymentService.reply.orderStatus.payment.lastEvent');

            if (!in_array($oLastEventNode->nodeValue, $this->getRefundableStatuses())) {
                $sError = sprintf(
                    'Payment is not in an acceptable state to be refunded (is %s, must be one of %s)',
                    $oLastEventNode->nodeValue,
                    implode(', ', $this->getRefundableStatuses())
                );
                $this->log($sError);
                throw new WorldPayException($sError);
            }

            //  Check how much can be refunded
            $this->log('Check refundable amount');
            $oRequest = $this->createXmlDocument();
            $oRequest->appendChild(
                $this->createXmlElement($oRequest, 'paymentService', [
                    $this->createXmlElement($oRequest, 'inquiry', [
                        $this->createXmlElement($oRequest, 'refundableAmountInquiry', null, [
                            'orderCode' => $oPayment->ref,
                        ]),
                    ]),
                ], [
                    'version'      => static::PAYMENT_SERVICE_VERSION,
                    'merchantCode' => $this->getMerchantCode($oCurrency->code),
                ])
            );

            $oResponse = $this->makeRequest($oRequest, $oCurrency);

            $oAmountNode       = $this->getNodeAtPath($oResponse, 'paymentService.reply.refundableAmount.amount');
            $iRefundableAmount = (int) $oAmountNode->getAttribute('value');

            if ($iAmount > $iRefundableAmount) {
                $sError = sprintf(
                    'Requested refund amount of %s exceeds allowable amount of %s',
                    $oCurrency->format($iAmount),
                    $oCurrency->format($iRefundableAmount),
                );
                $this->log($sError);
                throw new WorldPayException($sError);
            }

            //  Issue refund request
            $this->log('Issue the refund request');
            $oRequest = $this->createXmlDocument();
            $oRequest->appendChild(
                $this->createXmlElement($oRequest, 'paymentService', [
                    $this->createXmlElement($oRequest, 'modify', [
                        $this->createXmlElement($oRequest, 'orderModification', [
                            $this->createXmlElement($oRequest, 'refund', [
                                $this->createXmlElement($oRequest, 'amount', null, [
                                    'value'        => $iAmount,
                                    'currencyCode' => $oCurrency->code,
                                    'exponent'     => $oCurrency->decimal_precision,
                                ]),
                            ], [
                                'reference' => $oRefund->ref,
                            ]),
                        ], [
                            'orderCode' => $oPayment->ref,
                        ]),
                    ]),
                ], [
                    'version'      => static::PAYMENT_SERVICE_VERSION,
                    'merchantCode' => $this->getMerchantCode($oCurrency->code),
                ])
            );

            $oResponse = $this->makeRequest($oRequest, $oCurrency);

            try {

                $oRefundReceivedNode = $this->getNodeAtPath(
                    $oResponse,
                    'paymentService.reply.ok.refundReceived'
                );

                $oRefundResponse
                    ->setTransactionId($oRefund->ref)
                    ->setStatusComplete();

            } catch (NodeNotFoundException $e) {
                $oRefundResponse
                    ->setStatusFailed(
                        'An error occurred, `paymentService.reply.ok.refundReceived` node not found',
                        null,
                        static::REQUEST_REFUND_ERROR_OTHER
                    );
            }

        } catch (AuthenticationException $e) {

            $this
                ->log(
                    'Caught exception: %s with code: %s and message: %s',
                    [AuthenticationException::class, $e->getCode(), $e->getMessage()]
                )
                ->log('Marking as failed');

            $oRefundResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    static::REQUEST_ERROR_AUTH
                );

        } catch (ParseException $e) {

            $this
                ->log(
                    'Caught exception: %s with code: %s and message: %s',
                    [ParseException::class, $e->getCode(), $e->getMessage()]
                )
                ->log('Marking as failed');

            $oRefundResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    sprintf(static::REQUEST_REFUND_ERROR_PARSE, $e->getMessage())
                );

        } catch (\Exception $e) {

            $this
                ->log(
                    'Caught exception: %s with code: %s and message: %s',
                    [\Exception::class, $e->getCode(), $e->getMessage()]
                )
                ->log('Marking as failed');

            $oRefundResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    static::REQUEST_REFUND_ERROR_OTHER
                );
        }

        $this->log('Returning control to caller');

        return $oRefundResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the statuses a transaction can be in to be accepted for refund
     *
     * @return string[]
     */
    protected function getRefundableStatuses(): array
    {
        return [
            static::XML_TRANSACTION_CAPTURED,
            static::XML_TRANSACTION_PARTIALLY_CAPTURED,
            static::XML_TRANSACTION_SETTLED,
            static::XML_TRANSACTION_SETTLED_BY_MERCHANT,
            static::XML_TRANSACTION_SENT_FOR_REFUND,
            static::XML_TRANSACTION_REFUNDED,
            static::XML_TRANSACTION_REFUNDED_BY_MERCHANT,
            static::XML_TRANSACTION_REFUND_FAILED,
            static::XML_TRANSACTION_CHARGED_BACK,
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new payment source, returns a semi-populated source resource
     *
     * @param Resource\Source $oResource The Resouce object to update
     * @param array           $aData     Data passed from the caller
     *
     * @throws WorldPayException
     */
    public function createSource(
        Resource\Source &$oResource,
        array $aData
    ): void {
        $sToken = getFromArray('worldpay_token', $aData);
        if (empty($sToken)) {
            throw new WorldPayException('"worldpay_token" must be supplied when creating a WorldPay payment source.');
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
        throw new WorldPayException(
            sprintf(static::PAYMENT_SOURCES_ERROR, 'Updating')
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

        /** @var \Nails\Currency\Service\Currency $oCurrencyService */
        $oCurrencyService = Factory::service('Currency', \Nails\Currency\Constants::MODULE_SLUG);
        $sCurrency        = current($this->getSupportedCurrencies());
        $oCurrency        = $oCurrencyService->getByIsoCode($sCurrency);

        $this->deleteToken(
            $oResource->data->token,
            $oResource->customer(),
            $oCurrency
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for creating a new customer on the gateway
     *
     * @param array $aData The driver specific customer data
     *
     * @throws WorldPayException
     */
    public function createCustomer(array $aData = [])
    {
        throw new WorldPayException(
            sprintf(static::CUSTOMERS_ERROR, 'Creating')
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for retrieving an existing customer from the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     * @param array $aData       Any driver specific data
     *
     * @throws WorldPayException
     */
    public function getCustomer($mCustomerId, array $aData = [])
    {
        throw new WorldPayException(
            sprintf(static::CUSTOMERS_ERROR, 'Fetching')
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for updating an existing customer on the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     * @param array $aData       The driver specific customer data
     *
     * @throws WorldPayException
     */
    public function updateCustomer($mCustomerId, array $aData = [])
    {
        throw new WorldPayException(
            sprintf(static::CUSTOMERS_ERROR, 'Updating')
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Convinience method for deleting an existing customer on the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     *
     * @throws WorldPayException
     */
    public function deleteCustomer($mCustomerId)
    {
        throw new WorldPayException(
            sprintf(static::CUSTOMERS_ERROR, 'Deleting')
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the decoded config array
     *
     * @return array
     * @throws ConfigException
     */
    public function getConfig(): array
    {
        $oConfig = json_decode($this->getSetting(Settings\WorldPay::KEY_CONFIG)) ?? [];
        $aConfig = $oConfig->{Environment::get()} ?? null;

        if ($aConfig === null) {
            throw new ConfigException(sprintf(
                'No config set for the %s environment',
                Environment::get(),
            ));
        }

        return $aConfig;
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
            throw new ConfigException(sprintf(
                'Unable to ascertain property `%s` for currency: %s with customer present: %s',
                $sProperty,
                $sCurrency,
                json_encode($bCustomerPresent)
            ));
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

    // --------------------------------------------------------------------------

    /**
     * Returns the decoded 3DS config object
     *
     * @return \stdClass
     * @throws ConfigException
     */
    public function get3dsConfig(): \stdClass
    {
        $oConfig = json_decode($this->getSetting(Settings\WorldPay::KEY_3DS_CONFIG)) ?? [];
        $oConfig = $oConfig->{Environment::get()} ?? null;

        if ($oConfig === null) {
            throw new ConfigException(sprintf(
                'No 3DS config set for the %s environment',
                Environment::get(),
            ));
        }

        return $oConfig;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a 3DS config property
     *
     * @param string $sProperty The property to return
     *
     * @return string|null
     * @throws ConfigException
     */
    protected function get3dsConfigProperty(string $sProperty): ?string
    {
        $oConfig = $this->get3dsConfig();
        $mValue  = $oConfig->{$sProperty} ?? null;

        if (empty($mValue)) {
            throw new ConfigException(sprintf(
                'Unable to ascertain property `%s` ',
                $sProperty
            ));
        }

        return $mValue;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the 3DS issuer
     *
     * @return string|null
     * @throws ConfigException
     */
    public function get3dsIssuer(): ?string
    {
        return (string) $this->get3dsConfigProperty('issuer') ?: null;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the 3DS Organisational ID
     *
     * @return string|null
     * @throws ConfigException
     */
    public function get3dsOrgUnitId(): ?string
    {
        return (string) $this->get3dsConfigProperty('org_unit_id') ?: null;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the 3DS MAC Key
     *
     * @return string|null
     * @throws ConfigException
     */
    public function get3dsMacKey(): ?string
    {
        return (string) $this->get3dsConfigProperty('mac_key') ?: null;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a new DDC object for handling 3DS Flex
     *
     * @return Ddc
     */
    public function ddc(string $sToken): Ddc
    {
        return new Ddc(
            $sToken,
            $this->get3dsIssuer(),
            $this->get3dsOrgUnitId(),
            $this->get3dsMacKey()
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Lists stored tokens on WorldPay for a given Customer ID
     *
     * @param Resource\Customer $oCustomer The customer to query
     * @param Currency          $oCurrency The currency to use for the request (affects merchant code choice)
     *
     * @return array
     * @throws AuthenticationException
     * @throws WorldPayException
     * @throws FactoryException
     * @throws NodeNotFoundException
     * @throws ParseException
     * @throws WorldPayException
     */
    public function listTokens(Resource\Customer $oCustomer, Currency $oCurrency): array
    {
        $oRequest = $this->createXmlDocument();
        $oRequest->appendChild(
            $this->createXmlElement($oRequest, 'paymentService', [
                $this->createXmlElement($oRequest, 'inquiry', [
                    $this->createXmlElement($oRequest, 'shopperTokenRetrieval', [
                        $this->createXmlElement($oRequest, 'authenticatedShopperID', $oCustomer->id),
                    ]),
                ]),
            ], [
                'version'      => static::PAYMENT_SERVICE_VERSION,
                'merchantCode' => $this->getMerchantCode($oCurrency->code),
            ])
        );

        $oResponse = $this->makeRequest($oRequest, $oCurrency);

        $aTokens    = [];
        $oReplyNode = $this->getNodeAtPath($oResponse, 'paymentService.reply');

        /** @var \DOMElement $oChildNode */
        foreach ($oReplyNode->childNodes as $oChildNode) {
            if ($oChildNode->tagName === 'token') {

                $oTokenIdNode = $this->getNodeAtPath($oChildNode, 'tokenDetails.paymentTokenID');
                $oExpireNode  = $this->getNodeAtPath($oChildNode, 'tokenDetails.paymentTokenExpiry.date');

                $aTokens[] = (object) [
                    'id'      => $oTokenIdNode->nodeValue,
                    'expires' => Factory::resource('DateTime', null, [
                        'raw' => sprintf(
                            '%s-%s-%s %s:%s:%s',
                            $oExpireNode->attributes->getNamedItem('year')->nodeValue,
                            $oExpireNode->attributes->getNamedItem('month')->nodeValue,
                            $oExpireNode->attributes->getNamedItem('dayOfMonth')->nodeValue,
                            $oExpireNode->attributes->getNamedItem('hour')->nodeValue,
                            $oExpireNode->attributes->getNamedItem('minute')->nodeValue,
                            $oExpireNode->attributes->getNamedItem('second')->nodeValue,
                        ),
                    ]),
                ];
            }
        }

        return $aTokens;
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes a WorldPay token
     *
     * @param string            $sTokenId  The token to delete
     * @param Resource\Customer $oCustomer The customer the token belongs to
     * @param Currency          $oCurrency The currency to use for the request (affects merchant code choice)
     *
     * @throws AuthenticationException
     * @throws WorldPayException
     * @throws FactoryException
     * @throws ParseException
     * @throws WorldPayException
     */
    public function deleteToken(string $sTokenId, Resource\Customer $oCustomer, Currency $oCurrency)
    {
        /** @var \DateTime $oNow */
        $oNow     = Factory::factory('DateTime');
        $oRequest = $this->createXmlDocument();
        $oRequest->appendChild(
            $this->createXmlElement($oRequest, 'paymentService', [
                $this->createXmlElement($oRequest, 'modify', [
                    $this->createXmlElement($oRequest, 'paymentTokenDelete', [
                        $this->createXmlElement($oRequest, 'paymentTokenID', $sTokenId),
                        $this->createXmlElement($oRequest, 'authenticatedShopperID', $oCustomer->id),
                        $this->createXmlElement($oRequest, 'tokenReason', 'Deleted by application ' . $oNow->format('Y-m-d H:i:s')),
                    ], [
                        'tokenScope' => 'shopper',
                    ]),
                ]),
            ], [
                'version'      => static::PAYMENT_SERVICE_VERSION,
                'merchantCode' => $this->getMerchantCode($oCurrency->code),
            ])
        );

        $oResponse = $this->makeRequest($oRequest, $oCurrency);
    }

    // --------------------------------------------------------------------------

    /**
     * Sets the log reference
     *
     * @param string $sRef
     *
     * @return $this
     */
    protected function setLogRef(string $sRef): self
    {
        $this->sLogRef = $sRef;
        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Write to the payment log
     *
     * @param string $sLine          The text to write
     * @param array  $aSubstitutions Any substitutions to add into the text
     *
     * @return $this
     */
    protected function log(string $sLine, array $aSubstitutions = []): self
    {
        if (empty($this->oLog) && (bool) $this->getSetting(Settings\WorldPay::KEY_DEBUG)) {
            $this->oLog = new Log($this->sLogRef);
        }

        if (!empty($this->oLog)) {
            $this->oLog->line($sLine, $aSubstitutions);
        }

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Retursn the current URL
     *
     * @return string
     */
    protected function getCurrentUrl(): string
    {
        return isCli() ? 'CLI' : current_url();
    }
}
