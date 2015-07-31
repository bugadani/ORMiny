<?php

/**
 * This file is part of the ORMiny library.
 * (c) D�niel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

class Utils
{
    public static function createStartWith($withPrefix)
    {
        return function ($relationName) use ($withPrefix) {
            return strpos($relationName, $withPrefix) === 0;
        };
    }
}