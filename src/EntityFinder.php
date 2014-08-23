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

        /*usort(
            $with,
            function ($a, $b) {
                return strlen($b) - strlen($a);
            }
        );*/

        $this->with = $with;

        return $this;
    }

    public function setMaxResults($limit)
    {
        $this->limit = (int) $limit;

        return $this;
    }

    public function setFirstResult($offset)
    {
        $this->offset = (int) $offset;

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
        $fields              = is_array($field) ? $field : func_get_args();
        $this->groupByFields = array_merge($this->groupByFields, $fields);

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

    public function get()
    {
        if (func_num_args() > 0) {
            if (!is_array(func_get_arg(0))) {
                return $this->getByPrimaryKey(func_get_args());
            } else {
                $parameters = func_get_arg(0);
            }
        } else {
            $parameters = [];
        }

        $query = $this->applyFilters(
            $this->driver
                ->getQueryBuilder()
                ->select($this->entity->getFields())
                ->from($this->entity->getTable())
        );
        $this->joinRelationsToQuery($this->entity, $query, '');

        return $this->process($query->query($parameters));
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
        if (isset($this->limit)) {
            $query->setMaxResults($this->limit);
        }
        if (isset($this->offset)) {
            $query->setFirstResult($this->offset);
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
                        "{$alias}.{$relation->foreignKey}",
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
     */
    private function joinRelationsToQuery($entity, $query, $prefix)
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

        $with = array_filter($with, [$entity, 'hasRelation']);

        foreach ($with as $relationName) {
            $this->relationStack[] = $relationName;

            $relation = $entity->getRelation($relationName);
            $this->joinToQuery($entity, $query, $relation, $prefix);

            array_pop($this->relationStack);
        }
    }

    private function getByPrimaryKey($primaryKeys)
    {
        $queryBuilder = $this->driver->getQueryBuilder();

        $query = $queryBuilder
            ->select($this->entity->getFields())
            ->from($this->entity->getTable())
            ->where(
                $this->createInExpression(
                    $this->entity->getPrimaryKey(),
                    $primaryKeys,
                    $queryBuilder
                )
            );

        $this->joinRelationsToQuery($this->entity, $query, '');

        $records = $this->process($query->query());

        if (count($primaryKeys) === 1) {
            return current($records);
        }

        return $records;
    }

    private function deleteByPk($primaryKeys)
    {
        $relations = $this->entity->getRelations();
        if (empty($relations)) {
            $this->entity->deleteByPrimaryKey($primaryKeys);
        } else {
            $this->deleteRecords(
                $this
                    ->with(array_keys($relations))
                    ->getByPrimaryKey($primaryKeys)
            );
        }
    }

    public function delete()
    {
        if (func_num_args() > 0) {
            if (!is_array(func_get_arg(0))) {
                $this->deleteByPk(func_get_args());

                return;
            } else {
                $parameters = func_get_arg(0);
            }
        } else {
            $parameters = [];
        }
        $relations = $this->entity->getRelations();

        if (empty($relations)) {
            $this->applyFilters(
                $this->driver
                    ->getQueryBuilder()
                    ->delete($this->entity->getTable())
            )->query();
        } else {
            $this->deleteRecords(
                $this->with(array_keys($relations))
                    ->get($parameters)
            );
        }
    }

    private function createInExpression($field, array $values, QueryBuilder $queryBuilder)
    {
        $expression = $queryBuilder->expression();
        if (count($values) === 1) {
            $expression->eq(
                $field,
                $queryBuilder->createPositionalParameter(current($values))
            );
        } else {
            $expression->in(
                $field,
                array_map([$queryBuilder, 'createPositionalParameter'], $values)
            );
        }

        return $expression;
    }

    public function count(array $parameters = [])
    {
        $count = $this->applyFilters(
            $this->driver
                ->getQueryBuilder()
                ->select('count(*) as count')
                ->from($this->entity->getTable())
        )->query($parameters + $this->parameters)->fetch();

        return $count['count'];
    }

    private function process(Statement $results)
    {
        $pkField = $this->entity->getPrimaryKey();

        $records = $this->resultProcessor->processRecords(
            $this->entity,
            $this->with,
            $this->fetchResults($results, $pkField)
        );

        return $records;
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

        $key     = null;
        $records = [];
        $index   = -1;
        while ($record = $statement->fetch()) {
            if ($key !== $record[$pkField]) {
                $key = $record[$pkField];
                $index++;
            }
            if (isset($this->offset) && $index < $this->offset) {
                continue;
            }
            if (isset($this->limit) && count($records) > $this->limit) {
                break;
            }
            $records[] = $record;
        }

        $statement->closeCursor();

        return $records;
    }

    /**
     * @param $records
     */
    private function deleteRecords($records)
    {
        if ($records === false) {
            return;
        }
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
