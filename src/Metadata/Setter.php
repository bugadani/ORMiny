<?php

/**
 * This file is part of the ORMiny library.
 * (c) D�niel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny\Metadata;

interface Setter
{
    /**
     * @param $object
     * @param $value
     *
     * @return mixed The new value
     */
    public function set($object, $value);
}