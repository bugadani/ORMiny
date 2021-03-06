<?php

/**
 * This file is part of the ORMiny library.
 * (c) D�niel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny\Metadata\Getter;

use ORMiny\Entity;
use ORMiny\Metadata\Getter;

class MethodGetter implements Getter
{
    private $method;

    public function __construct(Entity $entity, $method)
    {
        $class = $entity->getClassName();
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