<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny\Metadata\Getter;

use ORMiny\EntityMetadata;
use ORMiny\Metadata\Getter;

class MethodGetter implements Getter
{
    private $method;

    public function __construct(EntityMetadata $metadata, $method)
    {
        $class = $metadata->getClassName();
        if (!is_callable([$class, $method])) {
            throw new \InvalidArgumentException("{$class}::{$method} is not callable");
        }
        $this->method = $method;
    }

    public function get($object)
    {
        return $object->{$this->method}();
    }
}