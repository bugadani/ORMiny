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
    private $parameters = [];
    private $where;
    private $with = [];

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

    public function with($relationName)
    {
        $with = is_array($relationName) ? $relationName : func_get_args();

        $this->with  = [];
        $namePresent = [];
        foreach ($with as $relationName) {
            $currentName = '';
            foreach (explode('.', $relationName) as $namePart) {
                $currentName .= $namePart;
                if (!isset($namePresent[$currentName])) {
                    $namePresent[$currentName] = true;
                    $this->with[]              = $currentName;
                }
                $currentName .= '.';
            }
        }

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

    public function parameters(array $values)
    {
        return array_map([$this, 'parameter'], $values);
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

        $table  = $this->metadata->getTable();
        $fields = $this->metadata->getFields();
        if (!empty($this->with)) {
            $fields = array_map(
                function ($field) use ($table) {
                    return $table . '.' . $field;
                },
                $fields
            );
        }

        return $this->process(
            $this->applyFilters(
                $this->queryBuilder
                    ->select($fields)
                    ->from($table)
            )->query(array_merge($this->parameters, $parameters))
        );
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
    ) {
        $entityTable = $metadata->getTable();
        if ($prefix === '') {
            $leftAlias = $entityTable;
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
                            $prefix . $relation->foreignKey,
                            "{$alias}.{$relation->targetKey}"
                        )
                    );
                    break;

                case Relation::MANY_MANY:
                    $query->leftJoin(
                        $leftAlias,
                        $relation->joinTable,
                        $relation->joinTable,
                        (new Expression())->eq(
                            "{$leftAlias}.{$relation->foreignKey}",
                            "{$relation->joinTable}.{$entityTable}_{$relation->foreignKey}"
                        )
                    );
                    $query->leftJoin(
                        $relation->joinTable,
                        $relatedTable,
                        $alias,
                        (new Expression())->eq(
                            "{$relation->joinTable}.{$relatedTable}_{$relation->targetKey}",
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

    public function getByPrimaryKey($primaryKeys)
    {
        $records = $this->getByField($this->metadata->getPrimaryKey(), $primaryKeys);

        if (!is_array($primaryKeys) || count($primaryKeys) === 1) {
            return current($records);
        }

        return $records;
    }

    public function getByField($fieldName, $keys)
    {
        $table  = $this->metadata->getTable();
        $fields = $this->metadata->getFields();
        if (!empty($this->with)) {
            $fieldName = $table . '.' . $fieldName;
            $fields    = array_map(
                function ($field) use ($table) {
                    return $table . '.' . $field;
                },
                $fields
            );
        }

        return $this->process(
            $this->applyFilters(
                $this->queryBuilder
                    ->select($fields)
                    ->from($table)
                    ->where($this->createInExpression($fieldName, (array)$keys))
            )->query($this->parameters)
        );
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
            $this->applyFilters(
                $this->queryBuilder->delete($this->metadata->getTable())
            )->query($this->parameters);
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
        $relations = $this->metadata->getRelations();
        if (empty($relations)) {
            $this->queryBuilder
                ->delete($this->metadata->getTable())
                ->where($this->createInExpression($fieldName, (array)$keys))
                ->query($this->parameters);
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

        $this->applyFilters(
            $this->queryBuilder
                ->update($this->metadata->getTable())
                ->values($this->parameters($data))
        )->query(array_merge($this->parameters, $tempParameters));
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
        $count = $this->applyFilters(
            $this->queryBuilder
                ->select('count(*) as count')
                ->from($this->metadata->getTable())
        )->query(array_merge($this->parameters, $parameters))->fetch();

        return $count['count'];
    }

    private function process(Statement $results)
    {
        return $this->manager
            ->getResultProcessor()
            ->processRecords(
                $this->metadata,
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
     * @return array
     */
    private function fetchResults(Statement $statement, $pkField)
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
            $entity = $this->manager->get($this->metadata->getClassName());
            if (is_array($records)) {
                array_map([$entity, 'delete'], $records);
            } else {
                $entity->delete($records);
            }
        }
    }
}
