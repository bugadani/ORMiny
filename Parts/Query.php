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
            $table_id = $descriptor->name;
            $table_name = $table;
            $columns = $this->columns ? : $this->table->descriptor->fields;

            $table_join_field = $this->table->getForeignKey($descriptor->name);
            $primary_key = $descriptor->primary_key;
            foreach ($columns as $k => $name) {
                $columns[$k] = sprintf(self::$table_name_pattern, $table_name, $name, $table_id);
            }

            foreach ($this->with as $name) {
                $relation = $descriptor->getRelation($name);
                $related = $this->table->getRelatedTable($name);
                $related_descriptor = $related->descriptor;
                $related_table = $related->getTableName();
                $related_table_id = $related_descriptor->name;
                $related_primary = $related_descriptor->primary_key;

                foreach ($related_descriptor->fields as $related_field) {
                    $columns[] = sprintf(self::$table_name_pattern, $related_table, $related_field, $related_table_id);
                }

                $foreign_key = $this->table->getForeignKey($name);

                if ($relation == TableDescriptor::RELATION_MANY_MANY) {
                    $join_table = $this->table->getJoinTable($name);

                    $table .= sprintf(self::$join_pattern, $join_table, $table_join_field, $table_name, $primary_key);
                    $table .= sprintf(self::$join_pattern, $related_table, $related_primary, $join_table, $foreign_key);
                } else {
                    $table .= sprintf(self::$join_pattern, $related_table, $related_primary, $table_name, $foreign_key);
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
            if (isset($this->offset)) {
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
    public function execute($single)
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
        $stmt->execute();
        if (empty($this->with)) {
            $rows = $stmt->fetchAll();
            $orm->log('Results: ' . count($rows));
            if (empty($rows)) {
                return $single ? false : array();
            }
            if ($single) {
                return new Row($this->table, current($rows));
            } else {
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
        } else {
            return $this->process($stmt, $single);
        }
    }

    /**
     * @param PDOStatement $statement
     * @param bool $single
     * @return array
     */
    private function process(PDOStatement $statement, $single)
    {
        $table_fields = array();
        $relations_fields = array();
        $descriptor = $this->table->descriptor;
        $table = $descriptor->name;
        $pk_field = $descriptor->primary_key;
        foreach ($descriptor->fields as $name) {
            $table_fields[$name] = $table . '_' . $name;
        }
        foreach ($this->with as $name) {
            $relations_fields[$name] = array();
            foreach ($this->table->getRelatedTable($name)->descriptor->fields as $field) {
                $relations_fields[$name][$field] = $name . '_' . $field;
            }
        }
        $return = array();
        $last_pk = NULL;
        $relation_last_pks = array();
        $relations = array();
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
                $relations[$last_pk] = array();
            }
            foreach ($this->with as $name) {
                $relation_type = $descriptor->getRelation($name);
                $relation_table = $this->table->getRelatedTable($name);
                $relation_pk = $relation_table->getPrimaryKey();
                $relation_pk_alias = $relations_fields[$name][$relation_pk];

                $relation_pk_value = $row[$relation_pk_alias] ? : NULL;

                if (isset($relation_last_pks[$name]) && $relation_last_pks[$name] == $relation_pk_value) {
                    continue;
                }

                if (!isset($relations[$last_pk][$name])) {
                    $relations[$last_pk][$name] = array();
                }

                if (empty($relation_pk_value)) {
                    continue;
                }

                $relation_last_pks[$name] = $relation_pk_value;
                $data = $this->getFieldsFromRow($row, $relations_fields[$name]);
                $relation_row = new Row($relation_table, $data);
                if ($relation_type == TableDescriptor::RELATION_BELONGS_TO) {
                    //no need to store it in $relations - assign directly
                    $return[$last_pk]->$name = $relation_row;
                } else {
                    $relations[$last_pk][$name][$relation_pk_value] = $relation_row;
                }
            }
        }
        foreach ($relations as $pk => $array) {
            if (isset($return[$pk])) {
                foreach ($array as $name => $data) {
                    $return[$pk]->$name = $data;
                }
            }
        }
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
