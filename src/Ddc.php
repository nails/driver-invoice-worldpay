<?php

namespace Nails\Invoice\Driver\Payment\WorldPay;

use Firebase\JWT\JWT;
use Nails\Environment;

/**
 * Class Ddc
 *
 * @package Nails\Invoice\Driver\Payment\WorldPay
 */
class Ddc
{
    const WP_DDC_ENDPOINT_TEST = 'https://secure-test.worldpay.com/shopper/3ds/ddc.html';
    const WP_DDC_ENDPOINT_LIVE = 'https://secure.worldpay.com/shopper/3ds/ddc.html';

    // --------------------------------------------------------------------------

    private $sToken;
    private $sIssuer;
    private $sOrgUnitId;
    private $sMacKey;

    // --------------------------------------------------------------------------

    /**
     * Ddc constructor.
     *
     * @param string      $sToken
     * @param string|null $sIssuer
     * @param string|null $sOrgUnitId
     * @param string|null $sMacKey
     */
    public function __construct(string $sToken, ?string $sIssuer, ?string $sOrgUnitId, ?string $sMacKey)
    {
        $this->sToken     = $sToken;
        $this->sIssuer    = $sIssuer;
        $this->sOrgUnitId = $sOrgUnitId;
        $this->sMacKey    = $sMacKey;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the DDC URL appropriate for the environment
     *
     * @return string
     */
    public static function getUrl(): string
    {
        return Environment::is(Environment::ENV_PROD)
            ? static::WP_DDC_ENDPOINT_LIVE
            : static::WP_DDC_ENDPOINT_TEST;
    }

    // --------------------------------------------------------------------------

    /**
     * Rturns the DDC Origin appropriate for the environment
     *
     * @return string
     */
    public static function getOriginUrl(): string
    {
        return sprintf(
            'https://%s',
            parse_url(static::getUrl(), PHP_URL_HOST)
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the token being used for DDC
     *
     * @return string
     */
    public function getToken(): string
    {
        return $this->sToken;
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
                'jti'       => uniqid(),
                'iat'       => time(),
                'exp'       => time() + 7200, // Two hours
                'iss'       => $this->sIssuer,
                'OrgUnitId' => $this->sOrgUnitId,
            ],
            $this->sMacKey,
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Generates the body for the DDC iFrame
     *
     * @param string $sId The id to give the form, for auto-submission
     *
     * @return string
     */
    public function getIframeBody(string $sId = 'ddc-form'): string
    {
        $sUrl = static::getUrl();
        $sJwt = $this->getJwt();
        $sBin = $this->getToken();

        return <<<EOT
        <html>
            <head>
                <title>DDC</title>
            </head>
            <body>
                <form id="$sId" method="POST" action="$sUrl">
                    <input type="hidden" name="Bin" value="$sBin" />
                    <input type="hidden" name="JWT" value="$sJwt" />
                </form>
                <script>
                window.onload = function() {
                    document.getElementById('$sId').submit();
                }
                </script>
            </body>
        </html>
        EOT;
    }
}
