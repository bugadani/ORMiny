<?php

namespace ORMiny\Metadata\Relation;

use DBTiny\QueryBuilder\Expression;
use DBTiny\QueryBuilder\Select;
use ORMiny\Metadata\Relation;
use ORMiny\PendingQuery;

class ManyToMany extends Relation
{

    public function getEmptyValue()
    {
        return [];
    }

    public function delete($foreignKey)
    {
        $manager      = $this->entity->getManager();
        $queryBuilder = $manager->getDriver()->getQueryBuilder();
        $table        = $this->entity->getTable();

        $manager->postPendingQuery(
            new PendingQuery(
                $this->entity,
                PendingQuery::TYPE_DELETE,
                $queryBuilder
                    ->delete($this->getJoinTable())
                    ->where(
                        $queryBuilder->expression()->eq(
                            $table . '_' . $this->getForeignKey(),
                            $queryBuilder->createPositionalParameter(
                                $this->entity
                                    ->getField($this->getForeignKey())
                                    ->get($foreignKey)
                            )
                        )
                    )
            )
        );
    }

    public function joinToQuery(Select $query, $leftAlias, $alias)
    {
        $joinTable = $this->getJoinTable();
        $query->leftJoin(
            $leftAlias,
            $joinTable,
            $joinTable,
            (new Expression())->eq(
                "{$leftAlias}.{$this->getForeignKey()}",
                "{$joinTable}.{$this->getJoinTableForeignKey()}"
            )
        );
        $query->leftJoin(
            $joinTable,
            $this->related->getTable(),
            $alias,
            (new Expression())->eq(
                "{$joinTable}.{$this->getJoinTableTargetKey()}",
                "{$alias}.{$this->getTargetKey()}"
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