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
    const KEY_LABEL      = 'sLabel';
    const KEY_CONFIG     = 'aConfig';
    const KEY_3DS_CONFIG = 'a3dsConfig';
    const KEY_DEBUG      = 'bDebug';

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
            ->setInfo(anchor(
                'https://github.com/nails/driver-invoice-worldpay#configuration',
                'Configuration Documentation &nbsp;<b class="fa fa-external-link-alt"></b>',
                'class="btn btn-xs btn-default" target="_blank"'
            ))
            ->setEncrypted(true)
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        /** @var Setting $oDebug */
        $oDebug = Factory::factory('ComponentSetting');
        $oDebug
            ->setKey(static::KEY_DEBUG)
            ->setType(Form::FIELD_BOOLEAN)
            ->setLabel('Debug Mode')
            ->setInfo('When enabled, the driver will generate verbose log files')
            ->setDefault(false)
            ->setValidation([
                FormValidation::RULE_IS_BOOL,
            ]);

        /** @var Setting $o3dsFlexConfig */
        $o3dsFlexConfig = Factory::factory('ComponentSetting');
        $o3dsFlexConfig
            ->setKey(static::KEY_3DS_CONFIG)
            ->setType(Form::FIELD_TEXTAREA)
            ->setLabel('Config')
            ->setInfo(anchor(
                'https://github.com/nails/driver-invoice-worldpay#3ds-configuration',
                'Configuration Documentation &nbsp;<b class="fa fa-external-link-alt"></b>',
                'class="btn btn-xs btn-default" target="_blank"'
            ))
            ->setEncrypted(true)
            ->setFieldset('3DS Flex')
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        return [
            $oLabel,
            $oConfig,
            $oDebug,
            $o3dsFlexConfig,
        ];
    }
}
