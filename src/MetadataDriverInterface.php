<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

interface MetadataDriverInterface
{
    /**
     * @param $class
     *
     * @return EntityMetadata
     */
    public function readEntityMetadata($class);
}
