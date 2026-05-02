<?php

namespace App\Support;

class AccountContext
{
    /**
     * The currently active account type for this request lifecycle.
     * Set by the AccountScope middleware.
     */
    public static ?string $current = null;

    public static function set(?string $type): void
    {
        self::$current = $type;
    }

    public static function get(): ?string
    {
        return self::$current;
    }
}
