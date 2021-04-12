<?php

namespace Nails\Invoice\Driver\Payment\WorldPay;

use Firebase\JWT\JWT;
use Nails\Environment;

/**
 * Class ThreeDSChallenge
 *
 * @package Nails\Invoice\Driver\Payment\WorldPay
 */
class ThreeDSChallenge
{
    const WP_3DS_ENDPOINT_TEST = 'https://secure-test.worldpay.com/shopper/3ds/challenge.html';
    const WP_3DS_ENDPOINT_LIVE = 'https://centinelapi.cardinalcommerce.com/V2/Cruise/StepUp';

    // --------------------------------------------------------------------------

    private $sIssuer;
    private $sOrgUnitId;
    private $sReturnUrl;
    private $sACSUrl;
    private $sPayload;
    private $sTransactionId;
    private $sMacKey;

    // --------------------------------------------------------------------------

    /**
     * ThreeDSChallenge constructor.
     *
     * @param string|null $sIssuer
     * @param string|null $sOrgUnitId
     * @param string|null $sReturnUrl
     * @param string|null $sACSUrl
     * @param string|null $sPayload
     * @param string|null $sTransactionId
     * @param string|null $sMacKey
     */
    public function __construct(
        ?string $sIssuer,
        ?string $sOrgUnitId,
        ?string $sReturnUrl,
        ?string $sACSUrl,
        ?string $sPayload,
        ?string $sTransactionId,
        ?string $sMacKey
    ) {
        $this->sIssuer        = $sIssuer;
        $this->sOrgUnitId     = $sOrgUnitId;
        $this->sReturnUrl     = $sReturnUrl;
        $this->sACSUrl        = $sACSUrl;
        $this->sPayload       = $sPayload;
        $this->sTransactionId = $sTransactionId;
        $this->sMacKey        = $sMacKey;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the 3DS URL appropriate for the environment
     *
     * @return string
     */
    public static function getUrl(): string
    {
        return Environment::is(Environment::ENV_PROD)
            ? static::WP_3DS_ENDPOINT_LIVE
            : static::WP_3DS_ENDPOINT_TEST;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the encoded JWT for the token
     *
     * @return string
     */
    public function getJwt(): string
    {
        return JWT::encode(
            [
                'jti'              => uniqid(),
                'iat'              => time(),
                'iss'              => $this->sIssuer,
                'OrgUnitId'        => $this->sOrgUnitId,
                'ReturnUrl'        => $this->sReturnUrl,
                'Payload'          => [
                    'ACSUrl'        => $this->sACSUrl,
                    'Payload'       => $this->sPayload,
                    'TransactionId' => $this->sTransactionId,
                ],
                'ObjectifyPayload' => true,
            ],
            $this->sMacKey,
        );
    }
}
