<?php

declare(strict_types=1);

namespace Shopgate\Framework\Core;

if (!defined('SHOPGATE_PLUGIN_VERSION')) {
    define("SHOPGATE_PLUGIN_VERSION", "3.0.1-alpha2");
}

class ModuleVersion
{
    /**
     * @return string
     */
    public static function getVersion(): string
    {
        return SHOPGATE_PLUGIN_VERSION;
    }
}
