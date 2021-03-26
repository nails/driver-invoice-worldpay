<?php

namespace Nails\Invoice\Driver\Payment\WorldPay\Settings;

use Nails\Common\Helper\Form;
use Nails\Common\Interfaces;
use Nails\Common\Service\FormValidation;
use Nails\Components\Setting;
use Nails\Currency;
use Nails\Factory;

/**
 * Class WorldPay
 *
 * @package Nails\Invoice\Driver\Payment\WorldPay\Settings
 */
class WorldPay implements Interfaces\Component\Settings
{
    const KEY_LABEL               = 'sLabel';
    const KEY_CONFIG              = 'aConfig';
    const KEY_3DS_JWT_ISS         = 's3dsFlexJwtIss';
    const KEY_3DS_JWT_ORG_UNIT_ID = 's3dsFlexJwtOrgUnitId';
    const KEY_3DS_JWT_MAC         = 's3dsFlexJwtMacKey';

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'WorldPay';
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getPermissions(): array
    {
        return [];
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function get(): array
    {
        /** @var Setting $oLabel */
        $oLabel = Factory::factory('ComponentSetting');
        $oLabel
            ->setKey(static::KEY_LABEL)
            ->setLabel('Label')
            ->setInfo('The name of the provider, as seen by customers.')
            ->setDefault('WorldPay')
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        /** @var Setting $oConfig */
        $oConfig = Factory::factory('ComponentSetting');
        $oConfig
            ->setKey(static::KEY_CONFIG)
            ->setType(Form::FIELD_TEXTAREA)
            ->setLabel('Config')
            ->setInfo('<a href="https://github.com/nails/driver-invoice-worldpay#configuration">Configuration Documentation</a>')
            ->setEncrypted(true)
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        /** @var Setting $o3dsFlexJwtIss */
        $o3dsFlexJwtIss = Factory::factory('ComponentSetting');
        $o3dsFlexJwtIss
            ->setKey(static::KEY_3DS_JWT_ISS)
            ->setLabel('Issuer')
            ->setInfo('An identifier for who is issuing the JWT.')
            ->setFieldset('3DS Flex - JWT');

        /** @var Setting $o3dsFlexJwtOrgUnitId */
        $o3dsFlexJwtOrgUnitId = Factory::factory('ComponentSetting');
        $o3dsFlexJwtOrgUnitId
            ->setKey(static::KEY_3DS_JWT_ORG_UNIT_ID)
            ->setLabel('Organisational Unit ID')
            ->setInfo('An identity associated with your account.')
            ->setFieldset('3DS Flex - JWT');

        /** @var Setting $o3dsFlexJwtMacKey */
        $o3dsFlexJwtMacKey = Factory::factory('ComponentSetting');
        $o3dsFlexJwtMacKey
            ->setKey(static::KEY_3DS_JWT_MAC)
            ->setLabel('MAC Key')
            ->setInfo('The MAC key for signing the JWT.')
            ->setEncrypted(true)
            ->setFieldset('3DS Flex - JWT');

        return [
            $oLabel,
            $oConfig,
            $o3dsFlexJwtIss,
            $o3dsFlexJwtOrgUnitId,
            $o3dsFlexJwtMacKey,
        ];
    }
}
