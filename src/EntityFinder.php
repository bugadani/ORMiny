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
use Modules\DBAL\QueryBuilder\Update;
use ORMiny\Annotations\Relation as RelationAnnotation;
use ORMiny\Metadata\Relation;

class EntityFinder
{
    /**
     * @var Entity
     */
    private $entity;

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

    public function __construct(EntityManager $manager, Driver $driver, Entity $entity)
    {
        $this->manager      = $manager;
        $this->entity       = $entity;
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
     *
     * @return $this
     */
    public function with($relationName)
    {
        $with = is_array($relationName) ? $relationName : func_get_args();

        $relationNames = [];
        //Parse the passed relation names
        foreach ($with as $relationName) {
            if ($this->alias === $relationName) {
                throw new \InvalidArgumentException(
                    "Cannot use relation name '{$relationName}' because it is used as the table alias"
                );
            }

            $tok         = strtok($relationName, '.');
            $currentName = '';

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
     *
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
     *
     * @return $this
     */
    public function setFirstResult($offset)
    {
        $this->offset = (int)$offset;

        return $this;
    }

    /**
     * Set a WHERE condition to the query
     *
     * Note: this overrider any previous conditions but does not delete query parameters.
     *
     * @param $condition
     *
     * @return $this
     */
    public function where($condition)
    {
        $this->where = $condition;

        return $this;
    }

    /**
     * Set GROUP BY clause to the query
     *
     * @param $field
     *
     * @return $this
     */
    public function groupBy($field)
    {
        $this->groupByFields = is_array($field) ? $field : func_get_args();

        return $this;
    }

    /**
     * Add a GROUP BY clause to the query
     *
     * @param $field
     *
     * @return $this
     */
    public function addGroupBy($field)
    {
        $this->groupByFields = array_merge(
            $this->groupByFields,
            is_array($field) ? $field : func_get_args()
        );

        return $this;
    }

    /**
     * Set ORDER BY clause to the query
     *
     * @param        $field
     * @param string $order
     *
     * @return $this
     */
    public function orderBy($field, $order = 'ASC')
    {
        $this->orderByFields = [];

        return $this->addOrderBy($field, $order);
    }

    /**
     * Add an ORDER BY clause to the query
     *
     * @param        $field
     * @param string $order
     *
     * @return $this
     */
    public function addOrderBy($field, $order = 'ASC')
    {
        $this->orderByFields[ $field ] = [$field, $order];

        return $this;
    }

    /**
     * Insert a parameter placeholder into the query
     *
     * This should be used at places where one would otherwise insert the values into the query string.
     *
     * @param $value
     *
     * @return string
     */
    public function parameter($value)
    {
        if (is_array($value)) {
            if (count($value) === 1) {
                $value = current($value);
            } else {
                return $this->parameters($value);
            }
        }
        $this->parameters[] = $value;

        return '?';
    }

    /**
     * Add multiple parameters to the query
     *
     * @param array $values
     *
     * @return array
     */
    public function parameters(array $values)
    {
        return array_map([$this, 'parameter'], $values);
    }

    /**
     * Set the resulting records a read only
     *
     * @return $this
     */
    public function readOnly()
    {
        $this->readOnly = true;

        return $this;
    }

    /**
     * @param string       $table The table name
     * @param string|array $fields Fields to select
     * @param string       $fieldName The key field
     * @param mixed|array  $keys The key value(s)
     *
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

        $query->where(
            $this->equalsExpression($fieldName, $keys)
        );

        return $query;
    }

    /**
     * @param array $data
     *
     * @return Update
     */
    private function getUpdateQuery(array $data)
    {
        //This is a hack to prevent parameters being mixed up.
        $tempParameters   = $this->parameters;
        $this->parameters = [];

        $query = $this->applyFilters(
            $this->queryBuilder
                ->update($this->entity->getTable())
                ->values($this->parameters($data))
        );

        $this->parameters = array_merge($this->parameters, $tempParameters);

        return $query;
    }

    private function applyFilters(AbstractQueryBuilder $query)
    {
        //GroupBy is only applicable to Select
        if ($query instanceof Select) {
            if (isset($this->groupByFields)) {
                $query->groupBy($this->groupByFields);
            }
            $this->joinRelationsToQuery($this->entity, $query, $this->with);
        }
        if (isset($this->where)) {
            $query->where($this->where);
        }
        foreach ($this->orderByFields as $field) {
            list($fieldName, $order) = $field;
            $query->orderBy($fieldName, $order);
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
     * @param Entity $entity
     * @param Select $query
     * @param array  $with
     * @param string $prefix
     *
     * @return Select
     */
    private function joinRelationsToQuery(Entity $entity, Select $query, array $with, $prefix = '')
    {
        if ($prefix === '') {
            $leftAlias = $this->alias ?: $entity->getTable();
        } else {
            $leftAlias = $prefix;
            $prefix .= '_';
        }

        foreach (array_filter($with, [$entity, 'hasRelation']) as $relationName) {
            $relation      = $entity->getRelation($relationName);
            $relatedEntity = $relation->getEntity();

            $alias = $prefix . $relationName;

            $query->addSelect(
                array_map(
                    function ($item) use ($alias) {
                        return "{$alias}.{$item} as {$alias}_{$item}";
                    },
                    array_values($relatedEntity->getFieldNames())
                )
            );

            $relation->joinToQuery($query, $leftAlias, $alias);

            $strippedWith = Utils::filterPrefixedElements($with, $relationName . '.', Utils::FILTER_REMOVE_PREFIX);
            $this->joinRelationsToQuery($relatedEntity, $query, $strippedWith, $alias);
        }

        return $query;
    }

    private function equalsExpression($field, array $values)
    {
        return $this->queryBuilder->expression()->eq($field, $this->parameter($values));
    }

    private function process(Statement $results)
    {
        return $this->manager
            ->getResultProcessor()
            ->processRecords(
                $this->entity,
                $this->with,
                $this->fetchResults(
                    $results,
                    $this->entity->getPrimaryKey()
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
        //Special statement iterator is needed when relations are loaded _and_ there is a limit and/or an offset
        //TODO: actually, this is only needed when there is at least one 1:N or N:N relation
        if (!empty($this->with) && (isset($this->limit) || (isset($this->offset) && $this->offset > 0))) {
            return new StatementIterator($statement, $pkField, $this->offset, $this->limit);
        }

        if (!$statement instanceof \Traversable) {
            $statement = new \ArrayIterator($statement->fetchAll());
        }

        return $statement;
    }

    private function deleteRecords($records)
    {
        if ($records !== false) {
            if (is_array($records)) {
                array_map([$this->entity, 'delete'], $records);
            } else {
                $this->entity->delete($records);
            }
        }
    }

    /**
     * @param $table
     *
     * @return mixed
     */
    private function getTableAlias($table)
    {
        return $this->alias ?: $table;
    }

    /**
     * @param $table
     *
     * @return array
     */
    private function getFields($table)
    {
        $fields = $this->entity->getFieldNames();
        if (empty($this->with) && $this->alias === null) {
            return $fields;
        }

        $table = $this->getTableAlias($table);

        $prefixIfMissing = function ($field) use ($table) {
            if (strpos($field, '.') !== false) {
                return $field;
            }

            return $table . '.' . $field;
        };

        return array_map($prefixIfMissing, $fields);
    }

    /**
     * @param array|mixed $parameters
     *
     * There are two main cases here:
     * - Nothing, or an array is passed as argument
     * In this case the parameters are treated as query parameters
     * - The method is called with one or more scalar arguments
     * The argument(s) are treated as primary key(s)
     *
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

        $table = $this->entity->getTable();

        return $this->process(
            $this->applyFilters(
                $this->queryBuilder
                    ->select($this->getFields($table))
                    ->from($table, $this->alias)
            )->query(array_merge($this->parameters, $parameters))
        );
    }

    /**
     * Get one or multiple records by primary key
     *
     * Note: previous WHERE clauses are preserved
     *
     * @param array|mixed $primaryKeys
     *
     * @return array|mixed
     */
    public function getByPrimaryKey($primaryKeys)
    {
        if (is_array($primaryKeys) && empty($primaryKeys)) {
            return [];
        }
        $records = $this->getByField($this->entity->getPrimaryKey(), $primaryKeys);

        if (!is_array($primaryKeys)) {
            return current($records);
        }

        return $records;
    }

    /**
     * Get one or multiple records by a specific field
     *
     * Note: previous WHERE clauses are preserved
     *
     * @param string      $fieldName
     * @param array|mixed $keys
     *
     * @return array
     */
    public function getByField($fieldName, $keys)
    {
        $keys = (array)$keys;
        if (empty($keys)) {
            return [];
        }
        $table = $this->entity->getTable();
        if (!empty($this->with)) {
            if (strpos($fieldName, '.') === false) {
                $fieldName = $this->getTableAlias($table) . '.' . $fieldName;
            }
        }
        $this->manager->commit();

        return $this->process(
            $this->getSelectQuery($table, $this->getFields($table), $fieldName, $keys)
                 ->query($this->parameters)
        );
    }

    /**
     * Fetch a single record from the database
     *
     * @param mixed ... Query parameters
     *
     * @return mixed The record object or false on failure
     */
    public function getSingle()
    {
        $record = $this->setMaxResults(1)->get(func_get_args());

        return reset($record);
    }

    /**
     * Fetch a single record from the database by field
     *
     * @param string      $fieldName
     * @param array|mixed $key
     *
     * @return mixed The record object or false on failure
     */
    public function getSingleByField($fieldName, $key)
    {
        $record = $this->setMaxResults(1)->getByField($fieldName, $key);

        return reset($record);
    }

    /**
     * Returns whether a record exists where $fieldName equals $key
     *
     * Note: previous WHERE clauses are preserved
     *
     * @param string $fieldName
     * @param mixed  $key
     *
     * @return bool
     */
    public function existsByField($fieldName, $key)
    {
        $table = $this->entity->getTable();
        if (!empty($this->with)) {
            $fieldName = $this->getTableAlias($table) . '.' . $fieldName;
        }

        $this->manager->commit();

        $rowCount = $this->getSelectQuery($table, $fieldName, $fieldName, [$key])
                         ->query($this->parameters)
                         ->rowCount();

        return $rowCount !== 0;
    }

    /**
     * Returns whether a record exists where the primary key equals $key
     *
     * Note: previous WHERE clauses are preserved
     *
     * @param mixed $key
     *
     * @return bool
     */
    public function existsByPrimaryKey($key)
    {
        return $this->existsByField($this->entity->getPrimaryKey(), $key);
    }

    /**
     * Delete selected records
     *
     * @param array $parameters Query parameters
     */
    public function delete($parameters = [])
    {
        if (!is_array($parameters)) {
            if (func_num_args() !== 1) {
                $parameters = func_get_args();
            }

            $this->deleteByPrimaryKey($parameters);

            return;
        }

        $relations = $this->entity->getRelations();

        if (empty($relations)) {
            $this->manager->postPendingQuery(
                $this->applyFilters(
                    $this->queryBuilder->delete($this->entity->getTable())
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

    /**
     * Delete records with $data where $fieldName equals to or is an element of $keys
     *
     * Note: previous WHERE clauses are preserved
     *
     * @param string      $fieldName
     * @param mixed|array $keys
     */
    public function deleteByField($fieldName, $keys)
    {
        $keys = (array)$keys;
        if (empty($keys)) {
            return;
        }
        //Filter relations so we don't delete records which this one only belongs to
        $relations = array_filter(
            $this->entity->getRelations(),
            function (Relation $relation) {
                return !$relation instanceof Relation\BelongsTo;
            }
        );
        if (empty($relations)) {
            $this->manager->postPendingQuery(
                $this->queryBuilder
                    ->delete($this->entity->getTable())
                    ->where($this->equalsExpression($fieldName, $keys)),
                $this->parameters
            );
        } else {
            $this->deleteRecords(
                $this->with(array_keys($relations))
                     ->getByField($fieldName, $keys)
            );
        }
    }

    /**
     * Delete records with $data where the primary key equals to or is an element of $primaryKeys
     *
     * Note: previous WHERE clauses are preserved
     *
     * @param mixed|array $primaryKeys
     */
    public function deleteByPrimaryKey($primaryKeys)
    {
        $this->deleteByField($this->entity->getPrimaryKey(), $primaryKeys);
    }

    /**
     * Update the selected records with $data
     *
     * @param array $data
     */
    public function update(array $data)
    {
        $this->manager->postPendingQuery(
            $this->getUpdateQuery($data),
            $this->parameters
        );
    }

    /**
     * Update records with $data where $fieldName equals to or is an element of $fieldValue
     *
     * Note: previous WHERE clauses are preserved
     *
     * @param string      $fieldName
     * @param mixed|array $fieldValue
     * @param array       $data
     */
    public function updateByField($fieldName, $fieldValue, array $data)
    {
        $this->manager->postPendingQuery(
            $this->getUpdateQuery($data)
                 ->where(
                     $this->equalsExpression($fieldName, $fieldValue)
                 ),
            $this->parameters
        );
    }

    /**
     * Update records with $data where the primary key equals to or is an element of $fieldValue
     *
     * Note: previous WHERE clauses are preserved
     *
     * @param mixed|array $key
     * @param array       $data
     */
    public function updateByPrimaryKey($key, array $data)
    {
        $this->updateByField($this->entity->getPrimaryKey(), $key, $data);
    }

    /**
     * Count selected records
     *
     * @param array $parameters
     *
     * @return mixed
     */
    public function count(array $parameters = [])
    {
        $this->manager->commit();
        $count = $this->applyFilters(
            $this->queryBuilder
                ->select('count(*) as count')
                ->from($this->entity->getTable(), $this->alias)
        )->query(array_merge($this->parameters, $parameters))->fetch();

        return $count['count'];
    }
}
