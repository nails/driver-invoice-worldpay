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
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Factory\HttpRequest\Post;
use Nails\Common\Resource\DateTime;
use Nails\Common\Service\HttpCodes;
use Nails\Common\Service\Input;
use Nails\Config;
use Nails\Currency\Resource\Currency;
use Nails\Environment;
use Nails\Factory;
use Nails\Invoice\Constants;
use Nails\Invoice\Driver\Payment\WorldPay\Ddc;
use Nails\Invoice\Driver\Payment\WorldPay\Exceptions\Api\AuthenticationException;
use Nails\Invoice\Driver\Payment\WorldPay\Exceptions\Api\ParseException;
use Nails\Invoice\Driver\Payment\WorldPay\Exceptions\WorldPayException;
use Nails\Invoice\Driver\Payment\WorldPay\Exceptions\Xml\NodeNotFoundException;
use Nails\Invoice\Driver\Payment\WorldPay\Sca;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Exception\DriverException;
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
    const WP_ENDPOINT_TEST      = 'https://secure-test.worldpay.com/jsp/merchant/xml/paymentService.jsp';
    const WP_ENDPOINT_LIVE      = 'https://secure.worldpay.com/jsp/merchant/xml/paymentService.jsp';
    const PAYMENT_SOURCES_ERROR = '%s Payment Sources is not supported by the WorldPay driver';
    const CUSTOMERS_ERROR       = '%s Customers is not supported by the WorldPay driver';

    //  Error Codes:
    //  https://developer.worldpay.com/docs/wpg/troubleshoot#common-error-codes
    const XML_ERROR_AUTHENTICATION          = 401;
    const XML_ERROR_GENERAL                 = 1;
    const XML_ERROR_PARSE                   = 2;
    const XML_ERROR_AMOUNT_INVALID          = 3;
    const XML_ERROR_SECURITY_VIOLATION      = 4;
    const XML_ERROR_INVALID_ORDER_DETAILS   = 5;
    const XML_ERROR_INVALID_BATCH           = 6;
    const XML_ERROR_INVALID_PAYMENT_DETAILS = 7;
    const XML_ERROR_SERVICE_UNAVAILABLE     = 8;

    const XML_TRANSACTION_AUTHORISED = 'AUTHORISED';
    const XML_TRANSACTION_REFUSED    = 'REFUSED';
    const XML_TRANSACTION_ERROR      = 'ERROR';

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

        $oScaData = new WorldPay\Sca\Data(
            $oInvoice,
            $oCurrency,
            $iAmount,
            $oSource,
            $oPayment,
            clone $oPaymentData, // Clone allows CVC data (if supplied) to persist
            $bCustomerPresent
        );

        $oChargeResponse
            ->setIsSca($oScaData->toArray());
    }

    // --------------------------------------------------------------------------

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

            $oResponseDoc = $this
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

            try {

                /**
                 * Determine if the transaction was sucessful:
                 * - No error occured
                 * - lastEvent is AUTHORISED
                 */
                $oLastEventNode = $this->getNodeAtPath(
                    $oResponseDoc,
                    'paymentService.reply.orderStatus.payment.lastEvent'
                );

                $this->processLastEvent($oLastEventNode->nodeValue, $oChargeResponse, $oPayment);

            } catch (NodeNotFoundException $e) {

                /**
                 * If there's no lastEvent node then attempt to see if there's an error at:
                 * paymentService.reply.orderStatus.error which will might give some more information
                 */

                try {

                    $oErrorNode = $this->getNodeAtPath($oResponseDoc, 'paymentService.reply.orderStatus.error');
                    $sError     = $oErrorNode->nodeValue;
                    $mCode      = $oErrorNode->attributes->getNamedItem('code')->nodeValue;

                } catch (NodeNotFoundException $e) {
                }

                $oChargeResponse
                    ->setStatusFailed(
                        $sError ?? 'Unknown error',
                        $mCode ?? null,
                        'Your payment failed; the response from the payment gateway was incomplete. Please try again.'
                    );
            }

        } catch (AuthenticationException $e) {
            $oChargeResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    'There is a configuration error preventing your payment from being processed.'
                );

        } catch (ParseException $e) {
            $oChargeResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    sprintf(
                        'There was a problem processing your payment: %s',
                        $e->getMessage()
                    )
                );

        } catch (\Exception $e) {
            $oChargeResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    'There was a problem processing your payment, you may wish to try again.'
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
        Resource\Source $oSource,
        Resource\Payment $oPayment,
        Resource\Invoice\Data\Payment $oPaymentData,
        bool $bCustomerPresent
    ): \DOMDocument {

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        $oDoc   = $this->createXmlDocument();

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
        Resource\Source $oSource,
        Resource\Invoice\Data\Payment $oPaymentData
    ): \DOMElement {

        if ($oSource || property_exists('worldpay_token', $oPaymentData)) {

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

            $aOverrides = [];

            if (property_exists($oPaymentData, 'expiryDate')) {

                if ($oPaymentData->expiryDate instanceof \Datetime) {
                    $oDate = $oPaymentData->expiryDate;

                } elseif ($oPaymentData->expiryDate instanceof DateTime) {
                    $oDate = $oPaymentData->expiryDate->getDateTimeObject();

                } elseif (is_string($oPaymentData->expiryDate)) {
                    $oDate = $this->parseDateString($oPaymentData->expiryDate);
                }

                if (!empty($oDate)) {
                    $aOverrides[] = $this->createXmlElement($oDoc, 'expiryDate', [
                        $this->createXmlElement($oDoc, 'date', null, [
                            'month' => $oDate->format('m'),
                            'year'  => $oDate->format('Y'),
                        ]),
                    ]);
                }
            }

            if (property_exists($oPaymentData, 'cardHolderName')) {
                $aOverrides[] = $this->createXmlElement($oDoc, 'cardHolderName', $oPaymentData->cardHolderName);
            }

            if (property_exists($oPaymentData, 'cvc')) {
                $aOverrides[] = $this->createXmlElement($oDoc, 'cvc', $oPaymentData->cvc);
            }

            if (property_exists($oPaymentData, 'cardAddress') && $oPaymentData->cardAddress instanceof Address) {
                $aOverrides[] = $this->createXmlElement($oDoc, 'cardAddress', [
                    $this->createXmlElement($oDoc, 'address', [
                        $this->createXmlElement($oDoc, 'address1', $oPaymentData->cardAddress->line_1),
                        $this->createXmlElement($oDoc, 'address2', $oPaymentData->cardAddress->line_2),
                        $this->createXmlElement($oDoc, 'address3', $oPaymentData->cardAddress->line_3),
                        $this->createXmlElement($oDoc, 'postalCode', $oPaymentData->cardAddress->postcode),
                        $this->createXmlElement($oDoc, 'city', $oPaymentData->cardAddress->town),
                        $this->createXmlElement($oDoc, 'state', $oPaymentData->cardAddress->region),
                        $this->createXmlElement($oDoc, 'countryCode', $oPaymentData->cardAddress->country->iso),
                    ]),
                ]);
            }

            if (!empty($aOverrides)) {
                $oPaymentInstrument = $this->createXmlElement($oDoc, 'paymentInstrument', [
                    $this->createXmlElement($oDoc, 'cardDetails', $aOverrides),
                ]);
            }

            return $this->createXmlElement($oDoc, 'TOKEN-SSL', array_filter([
                $this->createXmlElement($oDoc, 'paymentTokenID', $sPaymentToken),
                $oPaymentInstrument ?? null,
            ]), ['tokenScope' => 'shopper']);

        } else {
            throw new DriverException(
                'Must provide a payment source, or `worldpay_token`.'
            );
        }
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
     * @param string                     $sLastEvent
     * @param ChargeResponse|ScaResponse $oResponse
     * @param Resource\Payment           $oPayment
     */
    protected function processLastEvent(string $sLastEvent, ResponseBase $oResponse, Resource\Payment $oPayment)
    {
        switch ($sLastEvent) {
            case static::XML_TRANSACTION_AUTHORISED:
                //  @todo (Pablo 12/02/2021) - calculate the fee charged, if possible
                $oResponse
                    ->setStatusComplete()
                    ->setTransactionId($oPayment->ref);
                break;

            case static::XML_TRANSACTION_REFUSED:
                $oResponse
                    ->setStatusFailed(
                        'Payment was declined',
                        static::XML_TRANSACTION_REFUSED,
                        'Your payment was declined.'
                    );
                break;

            case static::XML_TRANSACTION_ERROR:
                $oResponse
                    ->setStatusFailed(
                        'An error occurred',
                        static::XML_TRANSACTION_REFUSED,
                        'An error occurred, you have not been charged.'
                    );
                break;
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
     * @param \DOMDocument $oDoc  The document to inspect
     * @param string       $sPath The path to retrieve
     *
     * @return \DOMElement
     * @throws NodeNotFoundException
     */
    private function getNodeAtPath(\DOMDocument $oDoc, string $sPath): \DOMElement
    {
        $aPath     = explode('.', $sPath);
        $sRootNode = array_shift($aPath);

        $oRootNode = $oDoc->getElementsByTagName($sRootNode)->item(0);
        if (empty($oRootNode)) {
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
                throw new NodeNotFoundException(sprintf(
                    'Failed to parse XML path; missing node at path "%s"',
                    implode('.', $aCurrentPath)
                ));
            }

            $oPreviousNode = $oCurrentNode;
        }

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
     * @throws DriverException
     * @throws ParseException
     * @throws WorldPayException
     * @throws FactoryException
     */
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
                $this->getXmlUsername($oCurrency->code, $bCustomerPresent),
                $this->getXmlPassword($oCurrency->code, $bCustomerPresent)
            )
            ->setHeader('Content-Type', 'text/xml')
            ->body($oDoc->saveXML(), false);

        $oHttpResponse = $oHttpPost->execute();

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

                default:
                    throw new DriverException(
                        $oErrorNode->nodeValue,
                        $oErrorNode->attributes->getNamedItem('code')->nodeValue
                    );
            }

        } catch (NodeNotFoundException $e) {
            //  No error node detected
        }

        return $oResponseDoc;
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
        if (property_exists($oPaymentData, 'cvc')) {

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
     * @param ScaResponse $oScaResponse The SCA Response object
     * @param array       $aData        Any saved SCA data
     * @param string      $sSuccessUrl  The URL to redirect to after authorisation
     *
     * @return ScaResponse
     */
    public function sca(ScaResponse $oScaResponse, array $aData, string $sSuccessUrl): ScaResponse
    {
        try {

            $oScaData = Sca\Data::buildFromDataArray($aData);

            $oRequestDoc = $this->buildChargeXml(
                $oScaData->getInvoice(),
                $oScaData->getCurrency(),
                $oScaData->getAmount(),
                $oScaData->getSource(),
                $oScaData->getPayment(),
                $oScaData->getPaymentData(),
                $oScaData->isCustomerPresent(),
            );

            //  Add 3DS data
            $oOrderNode = $this->getNodeAtPath($oRequestDoc, 'paymentService.submit.order');

            //  Add riskData node to reduce likelihood of challenge
            //  @todo (Pablo 08/03/2021) - add authenticationRiskData node
            //  @todo (Pablo 08/03/2021) - add shopperAccountRiskData node
            //  @todo (Pablo 08/03/2021) - add transactionRiskData node

            //  Add additional3DSData node
            $oOrderNode
                ->appendChild(
                    $this->createXmlElement($oRequestDoc, 'additional3DSData', null, [
                        'dfReferenceId'       => $oScaData->getPaymentData()->ddc_session_id,
                        'challengeWindowSize' => 'fullPage',
                        //'challengePreference' => 'noPreference',
                        'challengePreference' => 'challengeMandated',
                    ])
                );
            $oResponseDoc = $this->makeRequest(
                $oRequestDoc,
                $oScaData->getCurrency(),
                $oScaData->isCustomerPresent()
            );

            try {

                $oChallengeNode = $this->getNodeAtPath(
                    $oResponseDoc,
                    'paymentService.reply.orderStatus.challengeRequired.threeDSChallengeDetails'
                );

                //  @todo (Pablo 08/03/2021) - Handle challenge request

                dd(
                    '[CHALLENGE REQUIRED]',
                    'REQUEST DOC',
                    htmlentities($oRequestDoc->saveXML()),
                    'RESPONSE DOC',
                    htmlentities($oResponseDoc->saveXML())
                );

            } catch (NodeNotFoundException $e) {

                /**
                 * No challenge is required; check the lastEvent to ensure that
                 * the payment was successful.
                 */
                try {

                    $oLastEventNode = $this->getNodeAtPath(
                        $oResponseDoc,
                        'paymentService.reply.orderStatus.payment.lastEvent'
                    );

                    $this->processLastEvent($oLastEventNode->nodeValue, $oScaResponse, $oScaData->getPayment());

                } catch (NodeNotFoundException $e) {
                    $oScaResponse
                        ->setStatusFailed(
                            'An error occurred, `paymentService.reply.orderStatus.payment.lastEvent` node not found',
                            static::XML_TRANSACTION_REFUSED,
                            'An error occurred.'
                        );
                }
            }

        } catch (\Exception $e) {
            $oScaResponse->setStatusFailed($e->getMessage());
        }

        return $oScaResponse;
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
     * @throws DriverException
     */
    public function getCustomer($mCustomerId, array $aData = [])
    {
        throw new DriverException(
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
     * @throws DriverException
     */
    public function updateCustomer($mCustomerId, array $aData = [])
    {
        throw new DriverException(
            sprintf(static::CUSTOMERS_ERROR, 'Updating')
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
            sprintf(static::CUSTOMERS_ERROR, 'Deleting')
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
            $this->getSetting('s3dsFlexJwtIss'),
            $this->getSetting('s3dsFlexJwtOrdUnitId'),
            $this->getSetting('s3dsFlexJwtMacKey'),
        );
    }
}
