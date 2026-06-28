<?php

declare(strict_types=1);

namespace Planx\Shipping\Facades;

use Illuminate\Support\Facades\Facade;

class Shipping extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'shipping';
    }
}
