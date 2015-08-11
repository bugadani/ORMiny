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

class PropertySetter implements Setter
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

    public function set($object, $value)
    {
        return $object->{$this->property} = $value;
    }
}