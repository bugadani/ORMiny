<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use Modules\DBAL\AbstractQueryBuilder;
use Modules\DBAL\Driver;
use Modules\DBAL\Driver\Statement;
use Modules\DBAL\QueryBuilder;
use Modules\DBAL\QueryBuilder\Expression;
use Modules\DBAL\QueryBuilder\Insert;
use Modules\DBAL\QueryBuilder\Select;
use Modules\ORM\Annotations\Relation;

class EntityFinder
{
    /**
     * @var Entity
     */
    private $entity;

    /**
     * @var Driver
     */
    private $driver;
    private $parameters = [];
    private $where;
    private $with = [];
    private $relationStack = [];

    /**
     * @var ResultProcessor
     */
    private $resultProcessor;

    private $limit;
    private $offset;

    private $groupByFields = [];
    private $orderByFields = [];

    private $readOnly = false;

    public function __construct(ResultProcessor $resultProcessor, Driver $driver, Entity $entity)
    {
        $this->resultProcessor = $resultProcessor;
        $this->entity          = $entity;
        $this->driver          = $driver;
    }

    public function with($relationName)
    {
        $with = is_array($relationName) ? $relationName : func_get_args();

        foreach ($with as $name) {
            $str = strtok($name, '.');
            if (!in_array($str, $with)) {
                $with[] = $str;
            }
            while ($token = strtok($name)) {
                $str .= '.' . $token;
                if (!in_array($str, $with)) {
                    $with[] = $str;
                }
            }
        }

        $this->with = $with;

        return $this;
    }

    public function setMaxResults($limit)
    {
        $this->limit = (int)$limit;

        return $this;
    }

    public function setFirstResult($offset)
    {
        $this->offset = (int)$offset;

        return $this;
    }

    public function where($condition)
    {
        $this->where = $condition;

        return $this;
    }

    public function groupBy($field)
    {
        $this->groupByFields = is_array($field) ? $field : func_get_args();

        return $this;
    }

    public function addGroupBy($field)
    {
        $this->groupByFields = array_merge(
            $this->groupByFields,
            is_array($field) ? $field : func_get_args()
        );

        return $this;
    }

    public function orderBy($field, $order = 'ASC')
    {
        $this->orderByFields = [];

        return $this->addOrderBy($field, $order);
    }

    public function addOrderBy($field, $order = 'ASC')
    {
        $this->orderByFields[$field] = [$field, $order];

        return $this;
    }

    public function parameter($value)
    {
        $this->parameters[] = $value;

        return '?';
    }

    public function readOnly()
    {
        $this->readOnly = true;

        return $this;
    }

    public function get()
    {
        if (func_num_args() > 0) {
            if (!is_array(func_get_arg(0))) {
                return $this->getByPrimaryKey(func_get_args());
            }
            $parameters = func_get_arg(0);
        } else {
            $parameters = [];
        }

        $table  = $this->entity->getTable();
        $fields = $this->entity->getFields();
        if (!empty($this->with)) {
            $fields = array_map(
                function ($field) use ($table) {
                    return $table . '.' . $field;
                },
                $fields
            );
        }

        return $this->process(
            $this->joinRelationsToQuery(
                $this->entity,
                $this->applyFilters(
                    $this->driver
                        ->getQueryBuilder()
                        ->select($fields)
                        ->from($table)
                )
            )->query($parameters + $this->parameters)
        );
    }

    private function applyFilters(AbstractQueryBuilder $query)
    {
        if ($query instanceof Insert) {
            return $query;
        }

        //GroupBy is only applicable to Select
        if ($query instanceof Select && isset($this->groupByFields)) {
            $query->groupBy($this->groupByFields);
        }
        if (isset($this->where)) {
            $query->where($this->where);
        }
        if (isset($this->orderByFields)) {
            $first = true;
            foreach ($this->orderByFields as $field) {
                if ($first) {
                    $first = false;
                    $query->orderBy($field[0], $field[1]);
                } else {
                    $query->addOrderBy($field[0], $field[1]);
                }
            }
        }
        if (empty($this->with)) {
            if (isset($this->limit)) {
                $query->setMaxResults($this->limit);
            }
            if (isset($this->offset)) {
                $query->setFirstResult($this->offset);
            }
        }

        return $query;
    }

    private function joinToQuery(Entity $entity, Select $query, Relation $relation, $prefix)
    {
        $entityTable   = $entity->getTable();
        $relatedEntity = $entity->getRelatedEntity($relation->name);
        $relatedTable  = $relatedEntity->getTable();

        if ($prefix !== '') {
            $leftAlias = $prefix;
            $prefix .= '_';
        } else {
            $leftAlias = $entityTable;
        }
        $alias = $prefix . $relation->name;

        $query->addSelect(
            array_map(
                function ($item) use ($alias) {
                    return "{$alias}.{$item} as {$alias}_{$item}";
                },
                array_values($relatedEntity->getFields())
            )
        );

        switch ($relation->type) {
            case Relation::HAS_ONE:
            case Relation::HAS_MANY:
            case Relation::BELONGS_TO:
                $query->leftJoin(
                    $leftAlias,
                    $relatedTable,
                    $alias,
                    (new Expression())->eq(
                        $prefix . $relation->foreignKey,
                        "{$alias}.{$relation->targetKey}"
                    )
                );
                break;

            case Relation::MANY_MANY:
                $joinTable = $entityTable . '_' . $relatedTable;

                $query->leftJoin(
                    $leftAlias,
                    $joinTable,
                    $joinTable,
                    (new Expression())->eq(
                        "{$leftAlias}.{$relation->foreignKey}",
                        "{$joinTable}.{$entityTable}_{$relation->foreignKey}"
                    )
                );
                $query->leftJoin(
                    $joinTable,
                    $relatedTable,
                    $alias,
                    (new Expression())->eq(
                        "{$joinTable}.{$relatedTable}_{$relation->targetKey}",
                        "{$alias}.{$relation->targetKey}"
                    )
                );
                break;
        }
        $this->joinRelationsToQuery($relatedEntity, $query, $alias);
    }

    /**
     * @param Entity $entity
     * @param        $query
     * @param        $prefix
     *
     * @return AbstractQueryBuilder
     */
    private function joinRelationsToQuery($entity, $query, $prefix = '')
    {
        $with = $this->with;
        if (!empty($this->relationStack)) {
            $withPrefix = implode('.', $this->relationStack) . '.';

            $with = array_map(
                function ($relationName) use ($withPrefix) {
                    return substr($relationName, strlen($withPrefix));
                },
                array_filter(
                    $with,
                    function ($relationName) use ($withPrefix) {
                        return strpos($relationName, $withPrefix) === 0;
                    }
                )
            );
        }

        foreach (array_filter($with, [$entity, 'hasRelation']) as $relationName) {
            $this->relationStack[] = $relationName;

            $relation = $entity->getRelation($relationName);
            $this->joinToQuery($entity, $query, $relation, $prefix);

            array_pop($this->relationStack);
        }

        return $query;
    }

    private function getByPrimaryKey($primaryKeys)
    {
        $queryBuilder = $this->driver->getQueryBuilder();

        $table      = $this->entity->getTable();
        $fields     = $this->entity->getFields();
        $primaryKey = $this->entity->getPrimaryKey();
        if (!empty($this->with)) {
            $primaryKey = $table . '.' . $primaryKey;
            $fields     = array_map(
                function ($field) use ($table) {
                    return $table . '.' . $field;
                },
                $fields
            );
        }
        $records = $this->process(
            $this->joinRelationsToQuery(
                $this->entity,
                $queryBuilder
                    ->select($fields)
                    ->from($table)
                    ->where(
                        $this->createInExpression(
                            $primaryKey,
                            $primaryKeys,
                            $queryBuilder
                        )
                    )
            )->query($this->parameters)
        );

        if (count($primaryKeys) === 1) {
            return current($records);
        }

        return $records;
    }

    public function delete()
    {
        if (func_num_args() > 0) {
            if (!is_array(func_get_arg(0))) {
                $this->deleteByPrimaryKey(func_get_args());

                return;
            }
            $parameters = func_get_arg(0);
        } else {
            $parameters = [];
        }
        $relations = $this->entity->getRelations();

        if (empty($relations)) {
            $this->applyFilters(
                $this->driver
                    ->getQueryBuilder()
                    ->delete($this->entity->getTable())
            )->query($this->parameters);
        } else {
            $this->deleteRecords(
                $this->with(array_keys($relations))
                    ->get($parameters + $this->parameters)
            );
        }
    }

    private function deleteByPrimaryKey($primaryKeys)
    {
        $relations = $this->entity->getRelations();
        if (empty($relations)) {
            $queryBuilder = $this->driver->getQueryBuilder();
            $queryBuilder->delete($this->entity->getTable())
                ->where(
                    $this->createInExpression(
                        $this->entity->getPrimaryKey(),
                        (array)$primaryKeys,
                        $queryBuilder
                    )
                )->query();
        } else {
            $this->deleteRecords(
                $this->with(array_keys($relations))
                    ->getByPrimaryKey($primaryKeys)
            );
        }
    }

    private function createInExpression($field, array $values, QueryBuilder $queryBuilder)
    {
        $expression = $queryBuilder->expression();
        if (count($values) === 1) {
            $expression->eq($field, $this->parameter(current($values)));
        } else {
            $expression->in($field, array_map([$this, 'parameter'], $values));
        }

        return $expression;
    }

    public function count(array $parameters = [])
    {
        $count = $this->joinRelationsToQuery(
            $this->entity,
            $this->applyFilters(
                $this->driver
                    ->getQueryBuilder()
                    ->select('count(*) as count')
                    ->from($this->entity->getTable())
            )
        )->query($parameters + $this->parameters)->fetch();

        return $count['count'];
    }

    private function process(Statement $results)
    {
        return $this->resultProcessor->processRecords(
            $this->entity,
            $this->with,
            $this->readOnly,
            $this->fetchResults(
                $results,
                $this->entity->getPrimaryKey()
            )
        );
    }

    /**
     * @param Statement $statement
     * @param           $pkField
     *
     * @return array
     */
    private function fetchResults($statement, $pkField)
    {
        if (empty($this->with)) {
            return $statement->fetchAll();
        }
        if (!isset($this->limit) && (!isset($this->offset) || $this->offset === 0)) {
            return $statement->fetchAll();
        }

        $key        = null;
        $records    = [];
        $count      = 0;
        $index      = 0;
        $rowSkipped = false;

        while ($record = $statement->fetch()) {
            if ($key !== $record[$pkField]) {
                $key = $record[$pkField];
                if (isset($this->offset)) {
                    $rowSkipped = $index++ < $this->offset;
                }
                if ($rowSkipped) {
                    continue;
                }
                if (isset($this->limit) && $count++ === $this->limit) {
                    break;
                }
            } elseif ($rowSkipped) {
                continue;
            }
            $records[] = $record;
        }

        $statement->closeCursor();

        return $records;
    }

    private function deleteRecords($records)
    {
        if ($records !== false) {
            if (is_array($records)) {
                array_map(
                    [$this->entity, 'delete'],
                    $records
                );
            } else {
                $this->entity->delete($records);
            }
        }
    }
}
