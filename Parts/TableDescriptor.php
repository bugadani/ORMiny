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

    public function getRelation($relation)
    {
        if (!isset($this->relations[$relation])) {
            throw new OutOfBoundsException('Not related: ' . $relation);
        }
        return $this->relations[$relation];
    }

}