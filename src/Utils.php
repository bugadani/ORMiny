<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

class Utils
{
    public static function createStartWithFunction($withPrefix)
    {
        return function ($relationName) use ($withPrefix) {
            return strpos($relationName, $withPrefix) === 0;
        };
    }

    public static function notNull($value)
    {
        return $value !== null;
    }
}