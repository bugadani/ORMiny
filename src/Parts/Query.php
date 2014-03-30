<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM\Parts;

use Countable;
use Iterator;
use Modules\DBAL\Driver\Statement;
use Modules\DBAL\QueryBuilder\Select;
use Modules\ORM\Manager;

class Query implements Iterator, Countable
{
    private $table;
    private $with = array();
    private $columns = array();
    private $where;
    private $where_params = array();
    private $having;
    private $having_params = array();
    private $rows = array();
    private $query;
    private $queryBuilder;

    /**
     * @var ResultProcessor
     */
    private $processor;

    /**
     *
     * @param Table $table
     */
    public function __construct(Table $table)
    {
        $this->table        = $table;
        $this->manager      = $table->manager;
        $this->queryBuilder = $table->manager->connection->getQueryBuilder();

        $this->processor = new ResultProcessor($table);

        $tableName    = $table->getTableName();
        $this->select = $this->queryBuilder
            ->select(array())
            ->from($tableName);
    }

    /**
     * @param mixed ...
     *
     * @return Query
     */
    public function with()
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }

        foreach (func_get_args() as $relatedName) {
            $this->with[] = $relatedName;
            $this->addRelationToQuery($relatedName);
        }

        return $this;
    }

    /**
     * @param mixed ...
     *
     * @return Query
     */
    public function select()
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $arguments = func_get_args();
        if (empty($this->columns)) {
            $this->columns = $arguments;
        } else {
            $this->columns = array_merge($this->columns, $arguments);
        }

        return $this;
    }

    /**
     * @param string $condition,...
     *
     * @return Query
     */
    public function where($condition)
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $params = array_slice(func_get_args(), 1);
        if (isset($params[0]) && is_array($params[0])) {
            $params = $params[0];
        }
        if (!$this->where) {
            $this->where = true;
            $this->select->where($condition);
            $this->where_params = $params;
        } else {
            $this->select->andWhere($condition);
            $this->where_params = array_merge($this->where_params, $params);
        }

        return $this;
    }

    /**
     * @param string $condition,...
     *
     * @return Query
     */
    public function having($condition)
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $params = array_slice(func_get_args(), 1);
        if (isset($params[0]) && is_array($params[0])) {
            $params = $params[0];
        }
        if (!$this->having) {
            $this->having = true;
            $this->select->having($condition);
            $this->having_params = $params;
        } else {
            $this->select->andHaving($condition);
            $this->having_params = array_merge($this->having_params, $params);
        }

        return $this;
    }

    /**
     * @param string $field
     * @param string $order
     *
     * @return Query
     */
    public function order($field, $order = 'ASC')
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $this->select->orderBy($field, $order);

        return $this;
    }

    /**
     * @param int $limit
     * @param int $offset
     *
     * @return Query
     */
    public function limit($limit, $offset = 0)
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        if (empty($this->with)) {
            $this->select->setFirstResult($offset);
            $this->select->setMaxResults($limit);
        }
        $this->processor->setLimits($limit, $offset);

        return $this;
    }

    /**
     * @param string $field
     *
     * @return Query
     */
    public function group($field)
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $this->select->groupBy($field);

        return $this;
    }

    /**
     * @param bool $lock
     *
     * @return Query
     */
    public function lock($lock = true)
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }

        $this->select->lockForUpdate($lock);

        return $this;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getQuery();
    }

    /**
     * @return string
     */
    public function getQuery()
    {
        if (isset($this->query)) {
            return $this->query;
        }

        if (!empty($this->with)) {
            $tableName       = $this->table->getTableName();
            $tableDescriptor = $this->table->descriptor;

            $this->addSelectedColumns($this->select, $tableDescriptor, $tableName);
        } else {
            $this->select->select($this->columns ? : '*');
        }

        $this->query = $this->select->get();

        return $this->query;
    }

    private function addSelectedColumns(
        Select $select,
        TableDescriptor $tableDescriptor,
        $tableName
    ) {
        $columns     = array();
        $extra       = array();
        $tableFields = $tableDescriptor->fields;
        foreach ($this->columns ? : $tableFields as $name) {
            if (strpos($name, '(') === false) {
                $columns[] = sprintf(
                    '%1$s.%2$s as %3$s_%2$s',
                    $tableName,
                    $name,
                    $tableDescriptor->name
                );
            } else {
                $columns[] = $name;
            }
            if (!in_array($name, $tableFields) && strpos($name, ' as ')) {
                list(, $alias) = explode(' as ', $name, 2);
                $extra[$alias] = $alias;
            }
        }

        $this->processor->setExtraFieldsForMainTable($extra);
        $select->addSelect($columns);
    }

    private function addRelationToQuery($relatedName)
    {
        if (is_array($relatedName)) {
            $condition     = $relatedName;
            $relatedName   = array_shift($condition);
            $joinCondition = sprintf(' AND (%s)', implode(') AND (', $condition));
        } else {
            $joinCondition = '';
        }

        $related           = $this->table->getRelatedTable($relatedName);
        $relatedTableName  = $related->getTableName();
        $relatedTableAlias = $related->descriptor->name;
        $relatedPrimaryKey = $related->descriptor->primary_key;

        foreach ($related->descriptor->fields as $field) {
            $this->select->addSelect(
                sprintf(
                    '%s.%s as %s_%s',
                    $relatedTableName,
                    $field,
                    $relatedTableAlias,
                    $field
                )
            );
        }

        $tableName       = $this->table->getTableName();
        $tableDescriptor = $this->table->descriptor;
        switch ($tableDescriptor->getRelation($relatedName)) {
            case TableDescriptor::RELATION_MANY_MANY:

                $joinTable     = $this->table->getJoinTable($relatedName);
                $leftCondition = sprintf(
                    '%s.%s = %s.%s',
                    $tableName,
                    $tableDescriptor->primary_key,
                    $joinTable,
                    $this->table->getForeignKey($tableDescriptor->name)
                );

                $rightCondition = sprintf(
                    '%s.%s = %s.%s',
                    $joinTable,
                    $this->table->getForeignKey($relatedName),
                    $relatedTableName,
                    $relatedPrimaryKey
                );

                $this->select->leftJoin($tableName, $joinTable, null, $leftCondition);
                $this->select->leftJoin($joinTable, $relatedTableName, null, $rightCondition);
                break;

            case TableDescriptor::RELATION_HAS:
                $relatedTableForeignKey = $this->table->getForeignKey($tableName);

                $condition = sprintf(
                    '%s.%s = %s.%s',
                    $tableName,
                    $tableDescriptor->primary_key,
                    $relatedTableName,
                    $relatedTableForeignKey,
                    $joinCondition
                );
                $this->select->leftJoin($tableName, $relatedTableName, null, $condition);
                break;

            case TableDescriptor::RELATION_BELONGS_TO:

                $condition = sprintf(
                    '%s.%s = %s.%s',
                    $tableName,
                    $this->table->getForeignKey($relatedName),
                    $relatedTableName,
                    $relatedPrimaryKey,
                    $joinCondition
                );
                $this->select->leftJoin($tableName, $relatedTableName, null, $condition);
                break;
        }
    }

    /**
     * @param bool $single
     *
     * @return Row
     */
    public function execute($single = false)
    {
        $query = $this->getQuery();
        $this->manager->log('Executing query: %s', $query);

        $stmt = $this->manager->connection->prepare($query);
        $this->bindParameters($stmt, $this->manager);

        $stmt->execute();
        if ($single) {
            $this->processor->setLimits(1);
            $this->manager->log('Single row requested');

            if ($stmt->rowCount() == 0) {
                $this->manager->log('Results: 0');

                return false;
            }
        } else {
            $this->manager->log('Multiple rows requested');

            if ($stmt->rowCount() == 0) {
                $this->manager->log('Results: 0');

                return array();
            }
        }

        if (empty($this->with)) {
            return $this->processor->processResults($stmt);
        }

        return $this->processor->processResultsWithRelatedRecords($stmt, $this->with);
    }

    /**
     * @param Statement $stmt
     * @param Manager   $orm
     */
    protected function bindParameters(Statement $stmt, Manager $orm)
    {
        $i      = 0;
        $params = array();
        foreach ($this->where_params as $param) {
            $stmt->bindValue(++$i, $param);
            $params[] = $param;
        }
        foreach ($this->having_params as $param) {
            $stmt->bindValue(++$i, $param);
            $params[] = $param;
        }
        if (count($params)) {
            $orm->log('Query parameters: "%s"', implode('", "', $params));
        }
    }

    /**
     * @param int|bool $single
     *
     * @return Row|array
     */
    public function get($single = true)
    {
        if ($single === true) {
            if (isset($this->rows)) {
                reset($this->rows);

                return current($this->rows);
            }

            return $this->execute($single);
        } elseif ($single === false) {
            if (!isset($this->rows)) {
                $this->rows = $this->execute($single);
            }

            return $this->rows;
        } else {
            $pk = $this->table->getPrimaryKey(true);
            if (is_array($single)) {
                if (!empty($single)) {
                    $in = $this->manager->getQueryBuilder()->expression()->in(
                        $pk,
                        array_fill(0, count($single), '?')
                    );

                    $this->where($in->get(), $single);
                }

                return $this->execute(false);
            } else {
                $this->where($pk . ' = ? ', (int)$single);

                return $this->execute(true);
            }
        }
    }

    //Iterator methods
    public function current()
    {
        return current($this->rows);
    }

    public function key()
    {
        return key($this->rows);
    }

    public function next()
    {
        return next($this->rows);
    }

    public function rewind()
    {
        if (!isset($this->rows)) {
            $this->rows = $this->execute(false);
            if (!is_array($this->rows)) {
                $this->rows = array($this->rows);
            }
        }
        reset($this->rows);
    }

    public function valid()
    {
        $val = key($this->rows);

        return $val !== null && $val !== false;
    }

    //Countable method
    public function count()
    {
        if (!isset($this->rows)) {
            $this->rows = $this->execute(false);
        }

        return count($this->rows);
    }

}
