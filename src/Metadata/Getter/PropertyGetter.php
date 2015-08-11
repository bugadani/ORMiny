<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny\Metadata\Getter;

use ORMiny\Entity;
use ORMiny\Metadata\Getter;

class PropertyGetter implements Getter
{
    private $property;

    public function __construct(Entity $entity, $property)
    {
        $class = $entity->getClassName();
        if (!property_exists($class, $property)) {
            throw new \InvalidArgumentException("{$class}::{$property} is not a property");
        }
        $this->property = $property;
    }

    public function get($object)
    {
        return isset($object->{$this->property}) ? $object->{$this->property} : null;
    }
}