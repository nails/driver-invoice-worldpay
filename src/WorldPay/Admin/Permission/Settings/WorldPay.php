<?php

namespace Nails\Invoice\Driver\Payment\WorldPay\Admin\Permission\Settings;

use Nails\Admin\Interfaces\Permission;

class WorldPay implements Permission
{
    public function label(): string
    {
        return 'Can manage WorldPay settings';
    }

    public function group(): string
    {
        return 'Settings';
    }
}
