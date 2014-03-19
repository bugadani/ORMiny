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
use PDO;

class Query implements Iterator, Countable
{
    private $table;
    private $with = array();
    private $columns = array();
    private $selectedExtraFields = array();
    private $where;
    private $where_params = array();
    private $having;
    private $having_params = array();
    private $order;
    private $group;
    private $limit;
    private $offset;
    private $lock = false;
    private $rows = array();
    private $query;
    private $queryBuilder;
    private $select;

    /**
     *
     * @param Table $table
     */
    public function __construct(Table $table)
    {
        $this->table        = $table;
        $this->manager      = $table->manager;
        $this->queryBuilder = $table->manager->connection->getQueryBuilder();

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
        if (empty($this->with)) {
            $this->with = func_get_args();
        } else {
            $this->with = array_merge($this->with, func_get_args());
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
        $this->select->setFirstResult($offset);
        $this->select->setMaxResults($limit);

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

        $tableName = $this->table->getTableName();
        $select    = $this->select;

        if (!empty($this->with)) {
            $tableDescriptor = $this->table->descriptor;

            $this->addSelectedColumns($select, $tableDescriptor, $tableName);

            foreach ($this->with as $name) {
                $this->addRelationToQuery(
                    $select,
                    $tableDescriptor,
                    $name,
                    $tableName
                );
            }
        } else {
            $select->select($this->columns ? : '*');
        }

        $this->query = $select->get();

        return $this->query;
    }

    private function addSelectedColumns(
        Select $select,
        TableDescriptor $tableDescriptor,
        $tableName
    ) {
        $columns     = array();
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
                $this->selectedExtraFields[$alias] = $alias;
            }
        }
        $select->addSelect($columns);
    }

    private function addRelationToQuery(
        Select $select,
        TableDescriptor $tableDescriptor,
        $relatedName,
        $tableName
    ) {
        if (is_array($relatedName)) {
            $condition     = $relatedName;
            $relatedName   = array_shift($condition);
            $joinCondition = sprintf(' AND (%s)', implode(') AND (', $condition));
        } else {
            $joinCondition = '';
        }

        $primaryKey = $tableDescriptor->primary_key;

        $related      = $this->table->getRelatedTable($relatedName);
        $descriptor   = $related->descriptor;
        $relatedTable = $related->getTableName();

        $foreignKey        = $this->table->getForeignKey($relatedName);
        $relatedPrimaryKey = $descriptor->primary_key;
        foreach ($descriptor->fields as $field) {
            $column = sprintf(
                '%s.%s as %s_%s',
                $relatedTable,
                $field,
                $descriptor->name,
                $field
            );
            $select->addSelect($column);
        }

        switch ($tableDescriptor->getRelation($relatedName)) {
            case TableDescriptor::RELATION_MANY_MANY:
                $joinTable = $this->table->getJoinTable($relatedName);

                $condition = sprintf(
                    '%s.%s = %s.%s',
                    $tableName,
                    $primaryKey,
                    $joinTable,
                    $this->table->getForeignKey($tableDescriptor->name)
                );
                $select->leftJoin($tableName, $joinTable, null, $condition);

                $condition = sprintf(
                    '%s.%s = %s.%s',
                    $joinTable,
                    $foreignKey,
                    $relatedTable,
                    $relatedPrimaryKey
                );
                $select->leftJoin($joinTable, $relatedTable, null, $condition);

                break;
            case TableDescriptor::RELATION_HAS:
                $related_foreign = $this->table->getForeignKey($tableName);

                $condition = sprintf(
                    '%s.%s = %s.%s',
                    $tableName,
                    $primaryKey,
                    $relatedTable,
                    $related_foreign,
                    $joinCondition
                );
                $select->leftJoin($tableName, $relatedTable, null, $condition);
                break;
            case TableDescriptor::RELATION_BELONGS_TO:

                $condition = sprintf(
                    '%s.%s = %s.%s',
                    $tableName,
                    $foreignKey,
                    $relatedTable,
                    $relatedPrimaryKey,
                    $joinCondition
                );
                $select->leftJoin($tableName, $relatedTable, null, $condition);
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
        if (isset($this->limit)) {
            $single = $this->limit == 1;
        } elseif ($single) {
            $this->limit = 1;
        }
        $this->manager->log('%s row requested', ($single ? 'Single' : 'Multiple'));
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $this->manager->log('Results: 0');

            return $single ? false : array();
        }
        if (empty($this->with)) {
            return $this->processResults($single, $stmt, $this->manager);
        }

        return $this->processResultsWithRelatedRecords($stmt, $single);
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
     * @param bool      $single
     * @param Statement $stmt
     * @param Manager   $orm
     *
     * @return array|bool|Row
     */
    protected function processResults($single, Statement $stmt, $orm)
    {
        $rows = $stmt->fetchAll();
        $orm->log('Results: %d', count($rows));
        if (empty($rows)) {
            return $single ? false : array();
        }
        if ($single) {
            return new Row($this->table, current($rows));
        }
        $return  = array();
        $pkField = $this->table->getPrimaryKey();
        foreach ($rows as $row) {
            if (isset($row[$pkField])) {
                $return[$row[$pkField]] = new Row($this->table, $row);
            } else {
                $return[] = new Row($this->table, $row);
            }
        }

        return $return;
    }

    /**
     * @param Statement $statement
     * @param bool      $single
     *
     * @return array
     */
    private function processResultsWithRelatedRecords(Statement $statement, $single)
    {
        $descriptor = $this->table->descriptor;
        $table      = $descriptor->name;

        $table_fields = array();
        foreach ($descriptor->fields as $name) {
            $table_fields[$name] = $table . '_' . $name;
        }

        $relation_data     = $this->getRelationData($descriptor);
        $pk_field          = $descriptor->primary_key;
        $records           = array();
        $last_pk           = null;
        $relation_last_pks = array();
        $row_num           = 0;
        $fetched           = 0;

        $query_fields = array_merge($table_fields, $this->selectedExtraFields);
        $row_skipped  = false;

        //We fetch rows one-by-one because MANY_MANY relation type cannot be limited by LIMIT
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if ($last_pk !== $row[$table_fields[$pk_field]]) {
                $last_pk = $row[$table_fields[$pk_field]];

                if (isset($this->offset)) {
                    $row_skipped = ($row_num++ < $this->offset);
                }
                if (!$row_skipped) {
                    if (isset($this->limit) && $fetched++ == $this->limit) {
                        break;
                    }
                    $records[$last_pk] = new Row($this->table, $this->getFieldsFromRow(
                        $row,
                        $query_fields
                    ));
                    $relation_last_pks = array();
                }
            }

            if (!$row_skipped) {
                $relation_last_pks = $this->processRelatedRecords(
                    $relation_data,
                    $records[$last_pk],
                    $row,
                    $relation_last_pks
                );
            }
        }
        $statement->closeCursor();
        $this->table->manager->log('Results: %d', count($records));
        if ($single) {
            $records = current($records);
        }

        return $records;
    }

    /**
     * @param $relation_data
     * @param $return
     * @param $row
     * @param $relation_last_pks
     *
     * @return mixed
     */
    private function processRelatedRecords($relation_data, $return, $row, $relation_last_pks)
    {
        foreach ($this->with as $name) {
            if (is_array($name)) {
                $name = $name[0];
            }
            $relation_type     = $relation_data[$name]['type'];
            $relation_table    = $relation_data[$name]['table'];
            $relation_pk_alias = $relation_data[$name]['primary_key_alias'];
            $relation_fields   = $relation_data[$name]['fields'];

            if ($relation_type !== TableDescriptor::RELATION_BELONGS_TO) {
                if (!isset($return->$name)) {
                    $return->$name = array();
                }
            }

            if ($row[$relation_pk_alias]) {
                $relation_pk_value = $row[$relation_pk_alias];
            } else {
                continue;
            }

            if (isset($relation_last_pks[$name]) && $relation_last_pks[$name] == $relation_pk_value) {
                //This row is present multiple times and we have already processed it.
                continue;
            }

            $relation_last_pks[$name] = $relation_pk_value;
            $relation_row             = new Row($relation_table, $this->getFieldsFromRow(
                $row,
                $relation_fields
            ));
            if ($relation_type == TableDescriptor::RELATION_BELONGS_TO) {
                $return->$name = $relation_row;
            } else {
                $var                     = & $return->$name;
                $var[$relation_pk_value] = $relation_row;
            }
        }

        return $relation_last_pks;
    }

    /**
     * @param TableDescriptor $descriptor
     *
     * @return array
     */
    private function getRelationData(TableDescriptor $descriptor)
    {
        $relation_data = array();
        foreach ($this->with as $name) {
            if (is_array($name)) {
                $name = $name[0];
            }
            $relation_table       = $this->table->getRelatedTable($name);
            $relation_data[$name] = array(
                'fields' => array(),
                'table'  => $relation_table,
                'type'   => $descriptor->getRelation($name),
            );
            foreach ($relation_table->descriptor->fields as $field) {
                $relation_data[$name]['fields'][$field] = $name . '_' . $field;
            }
            $primaryKey                                = $relation_table->getPrimaryKey();
            $relation_data[$name]['primary_key_alias'] = $relation_data[$name]['fields'][$primaryKey];
        }

        return $relation_data;
    }

    /**
     * @param array $row
     * @param array $fields
     *
     * @return array
     */
    private function getFieldsFromRow(array $row, array $fields)
    {
        $rowdata = array();
        foreach ($fields as $field => $alias) {
            if (isset($row[$alias])) {
                $rowdata[$field] = $row[$alias];
            }
        }

        return $rowdata;
    }

    /**
     * @param bool $single
     *
     * @return Row|array
     */
    public function get($single = true)
    {
        if ($single) {
            if (isset($this->rows)) {
                reset($this->rows);

                return current($this->rows);
            }

            return $this->execute($single);
        }
        if (!isset($this->rows)) {
            $this->rows = $this->execute($single);
        }

        return $this->rows;
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
