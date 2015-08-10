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

    public static function filterPrefixedElements(array $unfiltered, $prefix, $flag = 0)
    {
        $useKeys      = $flag & Utils::FILTER_USE_KEYS;
        $removePrefix = $flag & Utils::FILTER_REMOVE_PREFIX;

        $startsWithPrefix = Utils::createStartWithFunction($prefix);
        if ($useKeys) {
            $filtered = [];
            foreach ($unfiltered as $key => $value) {
                if ($startsWithPrefix($key)) {
                    $filtered[ $key ] = $value;
                }
            }
        } else {
            $filtered = array_filter($unfiltered, $startsWithPrefix);
        }
        if (!$removePrefix) {
            return $filtered;
        }

        $prefixLength = strlen($prefix);

        $filteredAndStripped = [];
        if ($useKeys) {
            foreach ($filtered as $key => $value) {
                $key                         = substr($key, $prefixLength);
                $filteredAndStripped[ $key ] = $value;
            }
        } else {
            foreach ($filtered as $key => $value) {
                $value                       = substr($value, $prefixLength);
                $filteredAndStripped[ $key ] = $value;
            }
        }

        return $filteredAndStripped;
    }

    public static function notNull($value)
    {
        return $value !== null;
    }
}