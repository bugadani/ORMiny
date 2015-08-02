<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny\Metadata;

class Field implements Setter, Getter
{
    /**
     * @var Setter
     */
    private $setter;
    /**
     * @var Getter
     */
    private $getter;

    public function __construct(Setter $setter, Getter $getter)
    {
        $this->setter = $setter;
        $this->getter = $getter;
    }

    /**
     * @param $object
     *
     * @return mixed The field value from $object
     */
    public function get($object)
    {
        return $this->getter->get($object);
    }

    /**
     * @param $object
     * @param $value
     *
     * @return mixed The new value
     */
    public function set($object, $value)
    {
        return $this->setter->set($object, $value);
    }
}