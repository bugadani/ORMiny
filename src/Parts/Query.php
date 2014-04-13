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
use Modules\DBAL\QueryBuilder\Select;
use Modules\DBAL\QueryBuilder;
use Modules\ORM\Manager;

class Query implements Iterator, Countable
{
    private $table;
    private $with = array();
    private $columns = array();
    private $where;
    private $whereParams = array();
    private $having;
    private $havingParams = array();

    /**
     * @var Row[]
     */
    private $rows;

    /**
     * @var QueryBuilder
     */
    private $queryBuilder;

    /**
     * @var Manager
     */
    private $manager;

    /**
     * @var ResultProcessor
     */
    private $processor;

    /**
     * @var Select
     */
    private $select;

    /**
     *
     * @param Manager $manager
     * @param Table   $table
     */
    public function __construct(Manager $manager, Table $table)
    {
        $this->table        = $table;
        $this->manager      = $manager;
        $this->queryBuilder = $manager->connection->getQueryBuilder();

        $this->processor = new ResultProcessor($table);

        $this->select = $this->queryBuilder
            ->select(array())
            ->from($table->getTableName());
    }

    /**
     * @param mixed ...
     *
     * @return Query
     */
    public function with()
    {
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
        $params = array_slice(func_get_args(), 1);
        if (isset($params[0]) && is_array($params[0])) {
            $params = $params[0];
        }
        if (!$this->where) {
            $this->where = true;
            $this->select->where($condition);
            $this->whereParams = $params;
        } else {
            $this->select->andWhere($condition);
            $this->whereParams = array_merge($this->whereParams, $params);
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
        $params = array_slice(func_get_args(), 1);
        if (isset($params[0]) && is_array($params[0])) {
            $params = $params[0];
        }
        if (!$this->having) {
            $this->having = true;
            $this->select->having($condition);
            $this->havingParams = $params;
        } else {
            $this->select->andHaving($condition);
            $this->havingParams = array_merge($this->havingParams, $params);
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
        $this->select->lockForUpdate($lock);

        return $this;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getQuery()->get();
    }

    /**
     * @return Select
     */
    public function getQuery()
    {
        if (!empty($this->with)) {
            if (empty($this->columns)) {
                $this->addSelectedColumnsFromTable($this->table);
            } else {
                $this->addSelectedColumns($this->table);
            }
        } else {
            $this->select->select($this->columns ? : '*');
        }

        return $this->select;
    }

    private function addSelectedColumnsFromTable(Table $table)
    {
        $tableName  = $table->getTableName();
        $tableAlias = $table->descriptor->name;

        $array = array();
        foreach ($table->descriptor->fields as $field) {
            $array[] = $tableName . '.' . $field . ' as ' . $tableAlias . '_' . $field;
        }
        $this->select->addSelect($array);
    }

    private function addSelectedColumns(Table $table)
    {
        $extra      = array();
        $columnList = $this->columns;
        $tableName  = $table->getTableName();
        foreach ($columnList as &$name) {
            if (strpos($name, '(') === false) {
                $alias         = $table->descriptor->name . '_' . $name;
                $extra[$alias] = $alias;
                $name          = $tableName . '.' . $name . ' as ' . $alias;
            } elseif (!in_array($name, $table->descriptor->fields) && strpos($name, ' as ')) {
                list(, $alias) = explode(' as ', $name, 2);
                $extra[$alias] = $alias;
            }
        }
        $this->processor->setExtraFieldsForMainTable($extra);
        $this->select->addSelect($columnList);
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
        $relatedPrimaryKey = $related->descriptor->primary_key;

        $this->addSelectedColumnsFromTable($related);

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
     * @return Row|Row[]
     */
    public function execute($single = false)
    {
        $params = array_merge($this->whereParams, $this->havingParams);
        $stmt   = $this->getQuery()->query($params);

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
                if (isset($single)) {
                    $in = $this->queryBuilder->expression()->in(
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
