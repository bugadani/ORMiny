<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny\Metadata\Setter;

use ORMiny\Entity;
use ORMiny\Metadata\Setter;

class MethodSetter implements Setter
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

    public function set($object, $value)
    {
        return $object->{$this->method}($value);
    }
}