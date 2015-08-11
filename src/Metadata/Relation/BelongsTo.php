<?php

namespace ORMiny\Metadata\Relation;

use ORMiny\EntityManager;
use ORMiny\Metadata\Relation;

class BelongsTo extends Relation
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

    public function delete(EntityManager $manager, $foreignKey)
    {
        // Nothing to do
    }

    public function getForeignKeyValue($object)
    {
        if (is_array($object)) {
            $object = current($object);
        }

        return $this->related->getPrimaryKeyValue($object);
    }
}