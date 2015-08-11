<?php

namespace ORMiny\Metadata\Relation;

use ORMiny\EntityManager;
use ORMiny\Metadata\Relation;

class HasMany extends Relation
{

    public function getEmptyValue()
    {
        return [];
    }

    public function delete(EntityManager $manager, $object)
    {
        $this->getEntity()
             ->find()
             ->deleteByField(
                 $this->getTargetKey(),
                 $this->entity
                     ->getField($this->getForeignKey())
                     ->get($object)
             );
    }

    public function getForeignKeyValue($object)
    {
        return array_map([$this->related, 'getPrimaryKeyValue'], (array)$object);
    }
}