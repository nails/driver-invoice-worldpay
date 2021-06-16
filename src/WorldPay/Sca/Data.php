<?php

namespace Nails\Invoice\Driver\Payment\WorldPay\Sca;

use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Currency;
use Nails\Factory;
use Nails\Invoice\Constants;
use Nails\Invoice\Model;
use Nails\Invoice\Resource;

/**
 * Class Data
 *
 * @package Nails\Invoice\Driver\Payment\WorldPay\Sca
 */
class Data
{
    protected $oInvoice;
    protected $oCurrency;
    protected $iAmount;
    protected $oSource;
    protected $oPayment;
    protected $oPaymentData;
    protected $bCustomerPresent;

    // --------------------------------------------------------------------------

    /**
     * Data constructor.
     *
     * @param Resource\Invoice              $oInvoice
     * @param Currency\Resource\Currency    $oCurrency
     * @param int                           $iAmount
     * @param Resource\Source               $oSource
     * @param Resource\Payment              $oPayment
     * @param Resource\Invoice\Data\Payment $oPaymentData
     * @param bool                          $bCustomerPresent
     */
    public function __construct(
        Resource\Invoice $oInvoice,
        Currency\Resource\Currency $oCurrency,
        int $iAmount,
        Resource\Source $oSource,
        Resource\Payment $oPayment,
        Resource\Invoice\Data\Payment $oPaymentData,
        bool $bCustomerPresent
    ) {
        $this->oInvoice         = $oInvoice;
        $this->oCurrency        = $oCurrency;
        $this->iAmount          = $iAmount;
        $this->oSource          = $oSource;
        $this->oPayment         = $oPayment;
        $this->oPaymentData     = $oPaymentData;
        $this->bCustomerPresent = $bCustomerPresent;
    }

    // --------------------------------------------------------------------------

    /**
     * Instantiates a new Data object from the values generated by toArray
     *
     * @param Resource\Payment\Data\Sca $oData
     *
     * @return static
     * @throws Currency\Exception\CurrencyException
     * @throws FactoryException
     * @throws ModelException
     */
    public static function buildFromPaymentData(Resource\Payment\Data\Sca $oData): self
    {
        /** @var Model\Invoice $oInvoiceModel */
        $oInvoiceModel = Factory::model('Invoice', Constants::MODULE_SLUG);
        /** @var Currency\Service\Currency $oCurrencyService */
        $oCurrencyService = Factory::service('Currency', Currency\Constants::MODULE_SLUG);
        /** @var Model\Source $oSourceModel */
        $oSourceModel = Factory::model('Source', Constants::MODULE_SLUG);
        /** @var Model\Payment $oPaymentModel */
        $oPaymentModel = Factory::model('Payment', Constants::MODULE_SLUG);

        return new self(
            $oInvoiceModel->getById($oData->invoice_id),
            $oCurrencyService->getByIsoCode($oData->currency_code),
            $oData->amount,
            $oSourceModel->getById($oData->source_id),
            $oPaymentModel->getById($oData->payment_id),
            Factory::resource('InvoiceDataPayment', Constants::MODULE_SLUG, $oData->payment_data),
            $oData->customer_present,
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the properties as an array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'invoice_id'       => $this->oInvoice->id,
            'currency_code'    => $this->oCurrency->code,
            'amount'           => $this->iAmount,
            'source_id'        => $this->oSource->id,
            'payment_id'       => $this->oPayment->id,
            'payment_data'     => $this->oPaymentData,
            'customer_present' => $this->bCustomerPresent,
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the properties as a [JSON] string
     *
     * @return string
     */
    public function __toString()
    {
        return (string) json_encode($this->toArray());
    }

    // --------------------------------------------------------------------------

    /**
     * @return Resource\Invoice
     */
    public function getInvoice(): Resource\Invoice
    {
        return $this->oInvoice;
    }

    // --------------------------------------------------------------------------

    /**
     * @return Currency\Resource\Currency
     */
    public function getCurrency(): Currency\Resource\Currency
    {
        return $this->oCurrency;
    }

    // --------------------------------------------------------------------------

    /**
     * @return int
     */
    public function getAmount(): int
    {
        return $this->iAmount;
    }

    // --------------------------------------------------------------------------

    /**
     * @return Resource\Source
     */
    public function getSource(): Resource\Source
    {
        return $this->oSource;
    }

    // --------------------------------------------------------------------------

    /**
     * @return Resource\Payment
     */
    public function getPayment(): Resource\Payment
    {
        return $this->oPayment;
    }

    // --------------------------------------------------------------------------

    /**
     * @return Resource\Invoice\Data\Payment
     */
    public function getPaymentData(): Resource\Invoice\Data\Payment
    {
        return $this->oPaymentData;
    }

    // --------------------------------------------------------------------------

    /**
     * @return bool
     */
    public function isCustomerPresent(): bool
    {
        return $this->bCustomerPresent;
    }
}
