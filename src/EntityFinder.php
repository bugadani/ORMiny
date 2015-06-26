<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

use Modules\DBAL\AbstractQueryBuilder;
use Modules\DBAL\Driver;
use Modules\DBAL\Driver\Statement;
use Modules\DBAL\QueryBuilder;
use Modules\DBAL\QueryBuilder\Expression;
use Modules\DBAL\QueryBuilder\Select;
use ORMiny\Annotations\Relation;

class EntityFinder
{
    /**
     * @var EntityMetadata
     */
    private $metadata;

    /**
     * @var EntityManager
     */
    private $manager;

    /**
     * @var QueryBuilder
     */
    private $queryBuilder;
    private $alias;
    private $parameters = [];
    private $where;
    private $with       = [];

    private $limit;
    private $offset;

    private $groupByFields = [];
    private $orderByFields = [];

    private $readOnly = false;

    public function __construct(EntityManager $manager, Driver $driver, EntityMetadata $metadata)
    {
        $this->manager      = $manager;
        $this->metadata     = $metadata;
        $this->queryBuilder = $driver->getQueryBuilder();
    }

    public function alias($alias)
    {
        $alias = (string)$alias;
        if (in_array($alias, $this->with)) {
            throw new \InvalidArgumentException("Cannot use alias '{$alias}' because a relation already uses it");
        }

        $this->alias = $alias;

        return $this;
    }

    /**
     * Specifies which relations should be queried. Nested relations may be given,
     * by separating relation names with a dot (.).
     *
     * Multiple relations may be passed as an array of strings or as multiple string arguments.
     *
     * @param $relationName
     * @param ...
     * @return $this
     */
    public function with($relationName)
    {
        $with = is_array($relationName) ? $relationName : func_get_args();

        $relationNames = [];
        //Parse the passed relation names
        foreach ($with as $relationName) {
            if ($this->alias === $relationName) {
                throw new \InvalidArgumentException("Cannot use relation name '{$relationName}' because it is used as the table alias");
            }
            $currentName = '';

            $tok = strtok($relationName, '.');
            while ($tok !== false) {
                $currentName .= $tok;

                $relationNames[ $currentName ] = true;

                $currentName .= '.';
                $tok = strtok('.');
            }
        }
        $this->with = array_keys($relationNames);

        return $this;
    }

    /**
     * Sets the number of results
     *
     * @param $limit
     * @return $this
     */
    public function setMaxResults($limit)
    {
        $this->limit = (int)$limit;

        return $this;
    }

    /**
     * Sets the offset for the first result
     *
     * @param $offset
     * @return $this
     */
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
        $this->orderByFields[ $field ] = [$field, $order];

        return $this;
    }

    public function parameter($value)
    {
        $this->parameters[] = $value;

        return '?';
    }

    public function parameters(array $values)
    {
        return array_map([$this, 'parameter'], $values);
    }

    public function readOnly()
    {
        $this->readOnly = true;

        return $this;
    }

    /**
     * @param array $parameters There are two main cases here:
     *  - Nothing, or an array is passed as argument
     *    In this case the parameters are treated as query parameters
     *  - The method is called with one or more scalar arguments
     *    The argument(s) are treated as primary key(s)
     * @return array|mixed
     */
    public function get($parameters = [])
    {
        if (!is_array($parameters)) {
            if (func_num_args() !== 1) {
                $parameters = func_get_args();
            }

            return $this->getByPrimaryKey($parameters);
        }

        $table = $this->metadata->getTable();

        return $this->process(
            $this->applyFilters(
                $this->queryBuilder
                    ->select($this->getFields($table))
                    ->from($table, $this->alias)
            )->query(array_merge($this->parameters, $parameters))
        );
    }

    public function getByPrimaryKey($primaryKeys)
    {
        if (is_array($primaryKeys) && empty($primaryKeys)) {
            return [];
        }
        $records = $this->getByField($this->metadata->getPrimaryKey(), $primaryKeys);

        if (!is_array($primaryKeys)) {
            return current($records);
        }

        return $records;
    }

    public function getByField($fieldName, $keys)
    {
        $keys = (array)$keys;
        if (empty($keys)) {
            return [];
        }
        $table = $this->metadata->getTable();
        if (!empty($this->with)) {
            if (strpos($fieldName, '.') === false) {
                $fieldName = $this->getTableAlias($table) . '.' . $fieldName;
            }
        }
        $query = $this->getSelectQuery($table, $this->getFields($table), $fieldName, $keys);

        $this->manager->commit();

        return $this->process(
            $query->query($this->parameters)
        );
    }

    public function getSingle()
    {
        $record = $this->setMaxResults(1)->get(func_get_args());

        return reset($record);
    }

    public function getSingleByField($fieldName, $key)
    {
        $record = $this->setMaxResults(1)->getByField($fieldName, $key);

        return reset($record);
    }

    public function existsByField($fieldName, $key)
    {
        $table = $this->metadata->getTable();
        if (!empty($this->with)) {
            $fieldName = $this->getTableAlias($table) . '.' . $fieldName;
        }

        $query = $this->getSelectQuery($table, $fieldName, $fieldName, [$key]);

        $this->manager->commit();

        return $query->query($this->parameters)->rowCount() !== 0;
    }

    public function existsByPrimaryKey($key)
    {
        return $this->existsByField($this->metadata->getPrimaryKey(), $key);
    }

    /**
     * @param string       $table The table name
     * @param string|array $fields Fields to select
     * @param string       $fieldName The key field
     * @param mixed|array  $keys The key value(s)
     * @return Select
     */
    private function getSelectQuery($table, $fields, $fieldName, $keys)
    {
        /** @var Select $query */
        $query = $this->applyFilters(
            $this->queryBuilder
                ->select($fields)
                ->from($table, $this->alias)
        );

        $expr = $this->createInExpression($fieldName, $keys);

        if ($query->getWhere() === '') {
            $query->where($expr);
        } else {
            $query->andWhere($expr);
        }

        return $query;
    }

    private function applyFilters(AbstractQueryBuilder $query)
    {
        //GroupBy is only applicable to Select
        if ($query instanceof Select) {
            if (isset($this->groupByFields)) {
                $query->groupBy($this->groupByFields);
            }
            $this->joinRelationsToQuery($this->metadata, $query, $this->with);
        }
        if (isset($this->where)) {
            if ($query->getWhere() === '') {
                $query->where($this->where);
            } else {
                $query->andWhere($this->where);
            }
        }
        if (isset($this->orderByFields)) {
            $first = true;
            foreach ($this->orderByFields as $field) {
                list($fieldName, $order) = $field;
                if ($first) {
                    $first = false;
                    $query->orderBy($fieldName, $order);
                } else {
                    $query->addOrderBy($fieldName, $order);
                }
            }
        }
        if (empty($this->with)) {
            //Limits for joined queries are handled in a different way
            if (isset($this->limit)) {
                $query->setMaxResults($this->limit);
            }
            if (isset($this->offset)) {
                $query->setFirstResult($this->offset);
            }
        }

        return $query;
    }

    /**
     * @param EntityMetadata $metadata
     * @param Select         $query
     * @param array          $with
     * @param string         $prefix
     *
     * @return Select
     */
    private function joinRelationsToQuery(
        EntityMetadata $metadata,
        Select $query,
        array $with,
        $prefix = ''
    )
    {
        $entityTable = $metadata->getTable();
        if ($prefix === '') {
            $leftAlias = $this->alias ?: $entityTable;
        } else {
            $leftAlias = $prefix;
            $prefix .= '_';
        }

        foreach (array_filter($with, [$metadata, 'hasRelation']) as $relationName) {
            $relation        = $metadata->getRelation($relationName);
            $relatedMetadata = $this->manager->get($relation->target)->getMetadata();
            $relatedTable    = $relatedMetadata->getTable();

            $alias = $prefix . $relation->name;

            $query->addSelect(
                array_map(
                    function ($item) use ($alias) {
                        return "{$alias}.{$item} as {$alias}_{$item}";
                    },
                    array_values($relatedMetadata->getFields())
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
                            "{$leftAlias}.{$relation->foreignKey}",
                            "{$alias}.{$relation->targetKey}"
                        )
                    );
                    break;

                case Relation::MANY_MANY:
                    if ($relation->joinTableForeignKey === null) {
                        $joinTableForeignKey = "{$entityTable}_{$relation->foreignKey}";
                    } else {
                        $joinTableForeignKey = $relation->joinTableForeignKey;
                    }

                    if ($relation->joinTableTargetKey === null) {
                        $joinTableTargetKey = "{$relatedTable}_{$relation->targetKey}";
                    } else {
                        $joinTableTargetKey = $relation->joinTableTargetKey;
                    }

                    $query->leftJoin(
                        $leftAlias,
                        $relation->joinTable,
                        $relation->joinTable,
                        (new Expression())->eq(
                            "{$leftAlias}.{$relation->foreignKey}",
                            "{$relation->joinTable}.{$joinTableForeignKey}"
                        )
                    );
                    $query->leftJoin(
                        $relation->joinTable,
                        $relatedTable,
                        $alias,
                        (new Expression())->eq(
                            "{$relation->joinTable}.{$joinTableTargetKey}",
                            "{$alias}.{$relation->targetKey}"
                        )
                    );
                    break;
            }
            $withPrefix   = $relation->name . '.';
            $prefixLength = strlen($withPrefix);

            $strippedWith = array_map(
                function ($relationName) use ($prefixLength) {
                    return substr($relationName, $prefixLength);
                },
                array_filter(
                    $with,
                    function ($relationName) use ($withPrefix) {
                        return strpos($relationName, $withPrefix) === 0;
                    }
                )
            );
            $this->joinRelationsToQuery($relatedMetadata, $query, $strippedWith, $alias);
        }

        return $query;
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
        $relations = $this->metadata->getRelations();

        if (empty($relations)) {
            $this->manager->postPendingQuery(
                $this->applyFilters(
                    $this->queryBuilder->delete($this->metadata->getTable())
                ),
                $this->parameters
            );
        } else {
            $this->deleteRecords(
                $this->with(array_keys($relations))
                     ->get(array_merge($this->parameters, $parameters))
            );
        }
    }

    public function deleteByPrimaryKey($primaryKeys)
    {
        $this->deleteByField($this->metadata->getPrimaryKey(), $primaryKeys);
    }

    public function deleteByField($fieldName, $keys)
    {
        $keys = (array)$keys;
        if (empty($keys)) {
            return;
        }
        $relations = $this->metadata->getRelations();

        //We don't want to delete records which this one only belongs to
        foreach ($relations as $relName => $relation) {
            if ($relation->type === Relation::BELONGS_TO) {
                unset($relations[ $relName ]);
            }
        }
        if (empty($relations)) {
            $this->manager->postPendingQuery(
                $this->queryBuilder
                    ->delete($this->metadata->getTable())
                    ->where($this->createInExpression($fieldName, $keys)),
                $this->parameters
            );
        } else {
            $this->deleteRecords(
                $this->with(array_keys($relations))
                     ->getByField($fieldName, $keys)
            );
        }
    }

    public function update($data)
    {
        //This is a hack to prevent parameters being mixed up.
        $tempParameters   = $this->parameters;
        $this->parameters = [];

        $this->manager->postPendingQuery(
            $this->applyFilters(
                $this->queryBuilder
                    ->update($this->metadata->getTable())
                    ->values($this->parameters($data))
            ),
            array_merge($this->parameters, $tempParameters)
        );
    }

    private function createInExpression($field, array $values)
    {
        $expression = $this->queryBuilder->expression();
        if (count($values) === 1) {
            $expression->eq($field, $this->parameter(current($values)));
        } else {
            $expression->in($field, $this->parameters($values));
        }

        return $expression;
    }

    public function count(array $parameters = [])
    {
        $this->manager->commit();
        $count = $this->applyFilters(
            $this->queryBuilder
                ->select('count(*) as count')
                ->from($this->metadata->getTable(), $this->alias)
        )->query(array_merge($this->parameters, $parameters))->fetch();

        return $count['count'];
    }

    private function process(Statement $results)
    {
        return $this->manager
            ->getResultProcessor()
            ->processRecords(
                $this->manager->get($this->metadata->getClassName()),
                $this->with,
                $this->fetchResults(
                    $results,
                    $this->metadata->getPrimaryKey()
                ),
                $this->readOnly
            );
    }

    /**
     * @param Statement $statement
     * @param           $pkField
     *
     * @return \Iterator
     */
    private function fetchResults(Statement $statement, $pkField)
    {
        if (empty($this->with) || (!isset($this->limit) && (!isset($this->offset) || $this->offset === 0))) {
            if ($statement instanceof \Traversable) {
                return $statement;
            }

            return new \ArrayIterator($statement->fetchAll());
        }

        $iterator = new StatementIterator($statement, $pkField);
        if (isset($this->limit)) {
            $iterator->setLimit($this->limit);
        }
        $iterator->setOffset($this->offset);

        return $iterator;
    }

    private function deleteRecords($records)
    {
        if ($records === false) {
            return;
        }
        $entity = $this->manager->get($this->metadata->getClassName());
        if (is_array($records)) {
            array_map([$entity, 'delete'], $records);
        } else {
            $entity->delete($records);
        }
    }

    /**
     * @param $table
     * @return mixed
     */
    private function getTableAlias($table)
    {
        return $this->alias ?: $table;
    }

    /**
     * @param $table
     * @return array
     */
    private function getFields($table)
    {
        $fields = $this->metadata->getFields();
        if (empty($this->with) && $this->alias === null) {
            return $fields;
        }

        $table = $this->getTableAlias($table);

        return array_map(
            function ($field) use ($table) {
                if (strpos($field, '.') !== false) {
                    return $field;
                }

                return $table . '.' . $field;
            },
            $fields
        );
    }
}
