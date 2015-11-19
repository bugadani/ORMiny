<?php

namespace ORMiny\Metadata\Relation;

use ORMiny\Metadata\Relation;

class HasMany extends Relation
{

    public function getEmptyValue()
    {
        return [];
    }

    public function delete($foreignKey)
    {
        $this->getEntity()
             ->find()
             ->deleteByField(
                 $this->getTargetKey(),
                 $this->entity
                     ->getField($this->getForeignKey())
                     ->get($foreignKey)
             );
    }

    public function getForeignKeyValue($object)
    {
        return array_map([$this->related, 'getPrimaryKeyValue'], (array)$object);
    }
}