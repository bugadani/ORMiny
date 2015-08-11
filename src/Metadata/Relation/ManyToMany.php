<?php

namespace ORMiny\Metadata\Relation;

use ORMiny\EntityManager;
use ORMiny\Metadata\Relation;

class ManyToMany extends Relation
{

    public function getEmptyValue()
    {
        return [];
    }

    public function delete(EntityManager $manager, $object)
    {
        $queryBuilder = $manager->getDriver()->getQueryBuilder();
        $table        = $this->entity->getTable();

        $manager->postPendingQuery(
            $queryBuilder
                ->delete($this->getJoinTable())
                ->where(
                    $queryBuilder->expression()->eq(
                        $table . '_' . $this->getForeignKey(),
                        $queryBuilder->createPositionalParameter($this->entity
                            ->getField($this->getForeignKey())
                            ->get($object))
                    )
                )
        );
    }

    public function getJoinTable()
    {
        return $this->relationAnnotation->joinTable;
    }

    public function getJoinTableForeignKey()
    {
        if ($this->relationAnnotation->joinTableForeignKey === null) {
            return $this->entity->getTable() . '_' . $this->relationAnnotation->foreignKey;
        }

        return $this->relationAnnotation->joinTableForeignKey;
    }

    public function getJoinTableTargetKey()
    {
        if ($this->relationAnnotation->joinTableTargetKey === null) {
            return $this->related->getTable() . '_' . $this->relationAnnotation->targetKey;
        }

        return $this->relationAnnotation->joinTableTargetKey;
    }

    public function getForeignKeyValue($object)
    {
        $targetKeyField = $this->related->getField($this->getTargetKey());
        return array_map([$targetKeyField, 'get'], $object);
    }
}