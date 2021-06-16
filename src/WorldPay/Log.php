<?php

namespace Nails\Invoice\Driver\Payment\WorldPay;

use Nails\Common\Exception\FactoryException;
use Nails\Factory;

/**
 * Class Log
 *
 * @package Nails\Invoice\Driver\Payment\WorldPay
 */
class Log
{
    /** @var string|null */
    protected $sRef;

    /** @var \Nails\Common\Factory\Logger */
    protected $oLogger;

    // --------------------------------------------------------------------------

    /**
     * Log constructor.
     *
     * @param string|null $sRef The reference to include on lines
     *
     * @throws FactoryException
     */
    public function __construct(?string $sRef)
    {
        $this->sRef    = $sRef;
        $this->oLogger = Factory::factory('Logger');

        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');
        $this->oLogger->setFile('worldpay-' . $oNow->format('Y-m-d') . '.php');
    }

    // --------------------------------------------------------------------------

    /**
     * Writes lines to the log file
     *
     * @param string $sLine          The line to log
     * @param array  $aSubstitutions Any substitutions to mix into the line
     *
     * @return $this
     * @throws FactoryException
     */
    public function line(string $sLine, array $aSubstitutions): self
    {
        $sLine = sprintf($sLine, ...$aSubstitutions);

        foreach (explode(PHP_EOL, $sLine) as $sLine) {
            $sLine = sprintf(
                '[%s] %s',
                $this->sRef ?: 'NO_REF',
                $sLine
            );

            $this->oLogger->line($sLine);
        }

        return $this;
    }
}
