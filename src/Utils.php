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
    const FILTER_USE_KEYS      = 1;
    const FILTER_REMOVE_PREFIX = 2;

    public static function createStartWithFunction($withPrefix)
    {
        return function ($relationName) use ($withPrefix) {
            return strpos($relationName, $withPrefix) === 0;
        };
    }

    public static function filterPrefixedElements(array $elements, $prefix, $flag = 0)
    {
        $useKeys      = $flag & Utils::FILTER_USE_KEYS;
        $removePrefix = $flag & Utils::FILTER_REMOVE_PREFIX;

        $elements = array_filter(
            $elements,
            Utils::createStartWithFunction($prefix),
            $useKeys ? ARRAY_FILTER_USE_KEY : 0
        );

        if ($removePrefix) {
            $prefixLength = strlen($prefix);
            $record       = [];

            if ($useKeys) {
                foreach ($elements as $key => $value) {
                    $key            = substr($key, $prefixLength);
                    $record[ $key ] = $value;
                }
            } else {
                foreach ($elements as $key => $value) {
                    $value          = substr($value, $prefixLength);
                    $record[ $key ] = $value;
                }
            }

            return $record;
        } else {
            return $elements;
        }
    }

    public static function notNull($value)
    {
        return $value !== null;
    }
}