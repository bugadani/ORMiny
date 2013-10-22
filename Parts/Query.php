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
use PDO;
use PDOStatement;

class Query implements Iterator, Countable
{
    private static $select_pattern = 'SELECT %s FROM %s';
    private static $table_name_pattern = '%1$s.%2$s as %3$s_%2$s';
    private static $join_pattern = ' LEFT JOIN %1$s ON (%1$s.%2$s = %3$s.%4$s)';
    private $table;
    private $with;
    private $columns;
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

    /**
     *
     * @param Table $table
     */
    public function __construct(Table $table)
    {
        $this->table = $table;
        $this->manager = $table->manager;
    }

    /**
     * @param mixed ...
     * @return Query
     */
    public function with()
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $this->with = func_get_args();
        return $this;
    }

    /**
     * @param mixed ...
     * @return Query
     */
    public function select()
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $this->columns = func_get_args();
        return $this;
    }

    /**
     * @param string $condition,...
     * @return Query
     */
    public function where($condition)
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $condition = '(' . $condition . ')';
        $params = func_get_args();
        array_shift($params);
        if (is_array($params[0])) {
            $params = $params[0];
        }
        if (is_null($this->where)) {
            $this->where = $condition;
            $this->where_params = $params;
        } else {
            $this->where .= ' AND ' . $condition;
            $this->where_params = array_merge($this->where_params, $params);
        }
        return $this;
    }

    /**
     * @param string $condition,...
     * @return Query
     */
    public function having($condition)
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $condition = '(' . $condition . ')';
        $params = func_get_args();
        array_shift($params);
        if (is_array($params[0])) {
            $params = $params[0];
        }
        if (is_null($this->having)) {
            $this->having = $condition;
            $this->having_params = $params;
        } else {
            $this->having .= ' AND ' . $condition;
            $this->having_params = array_merge($this->having_params, $params);
        }
        return $this;
    }

    /**
     * @param string $order
     * @return Query
     */
    public function order($order)
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $this->order = $order;
        return $this;
    }

    /**
     * @param int $limit
     * @param int $offset
     * @return Query
     */
    public function limit($limit, $offset = 0)
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }

    /**
     * @param string $group
     * @return Query
     */
    public function group($group)
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $this->group = $group;
        return $this;
    }

    /**
     * @param bool $lock
     * @return Query
     */
    public function lock($lock = true)
    {
        if (isset($this->rows)) {
            unset($this->rows);
            unset($this->query);
        }
        $this->lock = $lock;
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
        $table = $this->table->getTableName();
        if (!empty($this->with)) {
            $descriptor = $this->table->descriptor;
            $table_name = $table;
            $table_join_field = $this->table->getForeignKey($descriptor->name);
            $primary_key = $descriptor->primary_key;

            $table_columns = $this->columns ? : $this->table->descriptor->fields;
            $columns = array();
            foreach ($table_columns as $name) {
                $columns[] = sprintf(self::$table_name_pattern, $table_name, $name, $descriptor->name);
            }

            foreach ($this->with as $name) {
                $related = $this->table->getRelatedTable($name);
                $related_descriptor = $related->descriptor;
                $related_table = $related->getTableName();
                $related_table_id = $related_descriptor->name;
                $related_primary = $related_descriptor->primary_key;

                foreach ($related_descriptor->fields as $related_field) {
                    $columns[] = sprintf(self::$table_name_pattern, $related_table, $related_field, $related_table_id);
                }

                $foreign_key = $this->table->getForeignKey($name);
                switch ($descriptor->getRelation($name)) {
                    case TableDescriptor::RELATION_MANY_MANY:
                        $join_table = $this->table->getJoinTable($name);

                        $table .= sprintf(self::$join_pattern, $join_table, $table_join_field, $table_name, $primary_key);
                        $table .= sprintf(self::$join_pattern, $related_table, $related_primary, $join_table,
                                $foreign_key);
                        break;
                    case TableDescriptor::RELATION_HAS:
                        $related_foreign = $this->table->getForeignKey($table_name);
                        $table .= sprintf(self::$join_pattern, $related_table, $related_foreign, $table_name,
                                $primary_key);
                        break;
                    case TableDescriptor::RELATION_BELONGS_TO:
                        $table .= sprintf(self::$join_pattern, $related_table, $related_primary, $table_name,
                                $foreign_key);
                        break;
                }
            }
        } else {
            $columns = $this->columns ? : array('*');
        }
        $sql = sprintf(self::$select_pattern, implode(', ', $columns), $table);
        if (isset($this->where)) {
            $sql .= ' WHERE ' . $this->where;
        }
        if (isset($this->group)) {
            $sql .= ' GROUP BY ' . $this->group;
        }
        if (isset($this->having)) {
            $sql .= ' HAVING ' . $this->having;
        }
        if (isset($this->order)) {
            $sql .= ' ORDER BY ' . $this->order;
        }
        if (isset($this->limit) && empty($this->with)) {
            //Only set limit and offset if we don't query relations. If we do, we'll deal with them later
            $sql .= ' LIMIT ' . $this->limit;
            if (isset($this->offset) && $this->offset != 0) {
                $sql .= ' OFFSET ' . $this->offset;
            }
        }
        if ($this->lock) {
            $sql .= ' FOR UPDATE';
        }
        $this->query = $sql;
        return $this->query;
    }

    /**
     * @param bool $single
     * @return Row
     */
    public function execute($single = false)
    {
        $query = $this->getQuery();
        $orm = $this->table->manager;
        $orm->log('Executing query: ' . $query);
        $stmt = $orm->connection->prepare($query);
        $i = 0;
        $params = array();
        foreach ($this->where_params as $param) {
            $stmt->bindValue( ++$i, $param);
            $params[] = $param;
        }
        foreach ($this->having_params as $param) {
            $stmt->bindValue( ++$i, $param);
            $params[] = $param;
        }
        if (count($params)) {
            $orm->log('Query parameters: ' . implode(', ', $params));
        }
        if (isset($this->limit)) {
            $single = $this->limit == 1;
        }
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $orm->log('Results: 0');
            return $single ? false : array();
        }
        if (empty($this->with)) {
            $rows = $stmt->fetchAll();
            $orm->log('Results: ' . count($rows));
            if (empty($rows)) {
                return $single ? false : array();
            }
            if ($single) {
                return new Row($this->table, current($rows));
            }
            $return = array();
            $pkfield = $this->table->getPrimaryKey();
            foreach ($rows as $row) {
                if (isset($row[$pkfield])) {
                    $return[$row[$pkfield]] = new Row($this->table, $row);
                } else {
                    $return[] = new Row($this->table, $row);
                }
            }
            return $return;
        }
        return $this->process($stmt, $single);
    }

    /**
     * @param PDOStatement $statement
     * @param bool $single
     * @return array
     */
    private function process(PDOStatement $statement, $single)
    {
        $descriptor = $this->table->descriptor;
        $table = $descriptor->name;

        $table_fields = array();
        foreach ($descriptor->fields as $name) {
            $table_fields[$name] = $table . '_' . $name;
        }

        $relation_data = array();
        foreach ($this->with as $name) {
            $relation_table = $this->table->getRelatedTable($name);
            $relation_data[$name] = array(
                'fields' => array(),
                'table'  => $relation_table,
                'type'   => $descriptor->getRelation($name),
            );
            foreach ($relation_table->descriptor->fields as $field) {
                $relation_data[$name]['fields'][$field] = $name . '_' . $field;
            }
            $relation_data[$name]['primary_key_alias'] = $relation_data[$name]['fields'][$relation_table->getPrimaryKey()];
        }
        $pk_field = $descriptor->primary_key;
        $return = array();
        $last_pk = NULL;
        $relation_last_pks = array();
        $row_num = 0;
        $fetched = 0;
        //We fetch rows one-by-one because MANY_MANY relation type cannot be limited by LIMIT
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if ($last_pk != $row[$table_fields[$pk_field]]) {
                if ($this->offset) {
                    if ($row_num < $this->offset) {
                        continue;
                    }
                    ++$row_num;
                }
                if ($this->limit && $fetched == $this->limit) {
                    break;
                }
                if ($single && $fetched == 1) {
                    break;
                }
                $rowdata = $this->getFieldsFromRow($row, $table_fields);
                $last_pk = $rowdata[$pk_field];
                ++$fetched;
                $return[$last_pk] = new Row($this->table, $rowdata);
            }
            foreach ($this->with as $name) {
                $relation_type = $relation_data[$name]['type'];
                $relation_table = $relation_data[$name]['table'];
                $relation_pk_alias = $relation_data[$name]['primary_key_alias'];
                $relation_fields = $relation_data[$name]['fields'];

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
                $data = $this->getFieldsFromRow($row, $relation_fields);
                $relation_row = new Row($relation_table, $data);
                if ($relation_type == TableDescriptor::RELATION_BELONGS_TO) {
                    $return[$last_pk]->$name = $relation_row;
                } else {
                    if (!isset($return[$last_pk]->$name)) {
                        $return[$last_pk]->$name = array();
                    }
                    $var = &$return[$last_pk]->$name;
                    $var[$relation_pk_value] = $relation_row;
                }
            }
        }
        $statement->closeCursor();
        $this->table->manager->log('Results: ' . count($return));
        if ($single) {
            $return = current($return);
        }
        return $return;
    }

    /**
     * @param array $row
     * @param array $fields
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
     * @return Row|array
     */
    public function get($single = true)
    {
        if ($single) {
            if (isset($this->rows)) {
                reset($this->rows);
                return current($this->rows);
            } else {
                return $this->execute($single);
            }
        } else {
            if (!isset($this->rows)) {
                $this->rows = $this->execute($single);
            }
            return $this->rows;
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
        return $val !== NULL && $val !== false;
    }

    //Countable method
    public function count()
    {
        if (!isset($this->rows)) {
            $this->rows = $this->execute(false);
            if (!is_array($this->rows)) {
                $this->rows = array($this->rows);
            }
        }
        return count($this->rows);
    }

}
