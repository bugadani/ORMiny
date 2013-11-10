<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM\Parts;

use OutOfBoundsException;

class TableDescriptor
{
    const RELATION_HAS = 0;
    const RELATION_BELONGS_TO = 1;
    const RELATION_MANY_MANY = 2;

    public $name;
    public $primary_key = 'id';
    public $fields = array();
    public $relations = array();

    public function __construct($name)
    {
        $this->name = $name;
    }

    /**
     * @param string $relation
     * @return int
     * @throws OutOfBoundsException
     */
    public function getRelation($relation)
    {
        if (!isset($this->relations[$relation])) {
            throw new OutOfBoundsException('Not related: ' . $relation);
        }
        return $this->relations[$relation];
    }

    public function __toString()
    {
        $return = 'Primary key: ' . $this->primary_key . "\n\t";
        $return .= 'Fields: ' . implode(', ', $this->fields) . "\n\t";
        $return .= 'Relations:';
        foreach ($this->relations as $name => $type) {
            switch ($type) {
                case self::RELATION_HAS:
                    $return .= "\n\t\tHas " . $name;
                    break;
                case self::RELATION_BELONGS_TO:
                    $return .= "\n\t\tBelongs to " . $name;
                    break;
                case self::RELATION_MANY_MANY:
                    $return .= "\n\t\tIn many-many type relationship with " . $name;
                    break;
            }
        }
        return $return;
    }

}
