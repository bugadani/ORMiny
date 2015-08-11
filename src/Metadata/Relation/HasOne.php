<?php

namespace ORMiny\Metadata\Relation;

use ORMiny\EntityManager;
use ORMiny\Metadata\Relation;

class HasOne extends Relation
{
    /**
     * @return bool
     */
    public function isSingle()
    {
        return true;
    }

    public function getEmptyValue()
    {
        return null;
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
        if (is_array($object)) {
            $object = current($object);
        }

        return $this->related->getPrimaryKeyValue($object);
    }
}