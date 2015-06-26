<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny\Annotations;

/**
 * @Annotation
 * @DefaultAttribute name
 * @Attribute('name', type: 'string')
 * @Attribute('setter')
 * @Attribute('getter')
 * @Target('property')
 */
class Field
{
    public  $name;
    public  $property;
    public  $setter;
    public  $getter;
    private $setterIsMethod;
    private $getterIsMethod;

    public function __construct($name = null, $setter = null, $getter = null)
    {
        $this->name     = $name;
        $this->setter   = $setter;
        $this->getter   = $getter;
    }

    public function setValue($object, $value)
    {
        if ($this->setterIsMethod === null) {
            if (is_callable([$object, $this->setter])) {
                $this->setterIsMethod = true;
            } else {
                $this->setterIsMethod = false;
            }
        }

        if ($this->setterIsMethod) {
            return $object->{$this->setter}($value);
        } else {
            return $object->{$this->setter} = $value;
        }
    }

    public function getValue($object)
    {
        if ($this->getterIsMethod === null) {
            if (is_callable([$object, $this->getter])) {
                $this->getterIsMethod = true;
            } else {
                $this->getterIsMethod = false;
            }
        }

        if ($this->getterIsMethod) {
            return $object->{$this->getter}();
        } else {
            return $object->{$this->getter};
        }
    }
}
