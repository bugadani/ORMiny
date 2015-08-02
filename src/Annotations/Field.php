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
    public  $setter;
    public  $getter;

    public function __construct($name = null, $setter = null, $getter = null)
    {
        $this->name   = $name;
        $this->setter = $setter;
        $this->getter = $getter;
    }
}
