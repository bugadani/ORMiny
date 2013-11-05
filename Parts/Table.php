<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM\Parts;

use ArrayAccess;
use InvalidArgumentException;
use Iterator;
use Modules\ORM\Manager;
use OutOfBoundsException;
use PDO;
use PDOException;

class Table implements ArrayAccess, Iterator
{
    private static $select_pattern = 'SELECT %s FROM `%s` WHERE %s';
    private static $insert_pattern = 'INSERT INTO `%s` (`%s`) VALUES (%s)';
    private static $update_pattern = 'UPDATE `%s` SET %s WHERE %s';
    private static $delete_pattern = 'DELETE FROM `%s` WHERE %s';

    /**
     * @var Manager
     */
    public $manager;

    /**
     * @var TableDescriptor
     */
    public $descriptor;

    /**
     * @var array
     */
    private $loaded_records = array();

    /**
     * @param Manager $manager
     * @param TableDescriptor $descriptor
     */
    public function __construct(Manager $manager, TableDescriptor $descriptor)
    {
        $this->manager = $manager;
        $this->descriptor = $descriptor;
    }

    public function __call($method, $args)
    {
        $query = new Query($this);
        return call_user_func_array(array($query, $method), $args);
    }

    /**
     * __toString returns the table's ID
     * @return string
     */
    public function __toString()
    {
        return $this->descriptor->name;
    }

    /**
     * @param array $data
     * @return Row
     */
    public function newRow(array $data = array())
    {
        return new Row($this, $data);
    }

    /**
     * @return string
     */
    public function getTableName()
    {
        return sprintf($this->manager->table_format, $this->descriptor->name);
    }

    /**
     * @return string
     */
    public function getPrimaryKey()
    {
        return $this->descriptor->primary_key;
    }

    /**
     * @param Table|TableDescriptor|string $referenced
     * @return string
     */
    public function getForeignKey($referenced)
    {
        $fk = $this->manager->foreign_key;
        if ($referenced instanceof Table) {
            return sprintf($fk, $referenced->descriptor->name);
        }
        if ($referenced instanceof TableDescriptor) {
            return sprintf($fk, $referenced->name);
        }
        return sprintf($fk, $referenced);
    }

    /**
     * @param string $relation
     * @return Table
     * @throws InvalidArgumentException
     */
    public function getRelatedTable($relation)
    {
        if (!isset($this->descriptor->relations[$relation])) {
            throw new InvalidArgumentException('Not related: ' . $relation);
        }
        return $this->manager->$relation;
    }

    /**
     * @param string $relation
     * @return string
     */
    public function getJoinTable($relation)
    {
        $table = $this->getRelatedTable($relation);
        return sprintf($this->manager->table_format, $this . '_' . $table);
    }

    /**
     * @param string $related
     * @return string
     */
    public function getJoinTableName($related)
    {
        $join_table = $this . '_' . $related;
        if (!isset($this->manager->$join_table)) {
            //Let's try the other way around.
            $join_table = $related . '_' . $this;
            if (!isset($this->manager->$join_table)) {
                $message = sprintf('%s and %s is not related.', $this, $related);
                throw new InvalidArgumentException($message);
            }
        }
        return $join_table;
    }

    /**
     * @param string $relation
     * @param mixed $key
     * @return Row
     */
    public function getRelated($relation, $key)
    {
        $related = $this->getRelatedTable($relation);
        return $related[$key];
    }

    /**
     * @param Row $row
     * @param bool $force_insert
     */
    public function save(Row $row, $force_insert = false)
    {
        $pk = $this->getPrimaryKey();
//        foreach ($this->descriptor->relations as $relation => $type) {
//            if ($type == TableDescriptor::RELATION_HAS) {
//                $this->save($row->$relation);
//            }
//        }
        if (!isset($row[$pk]) || $force_insert) {
            return $this->insert($row->toArray());
        } else {
            return $this->update($row[$pk], $row->getChangedValues());
        }
    }

    /**
     * @param array $data
     */
    public function insert(array $data)
    {
        $record_data = array_intersect_key($data, array_flip($this->descriptor->fields));
        $pdo = $this->manager->connection;
        $fields = array();
        $log_fields = array();
        foreach ($record_data as $key => $data) {
            $fields[$key] = ':' . $key;
            $log_fields[] = sprintf(':%s = "%s"', $key, $data);
        }
        $placeholders = implode(', ', $fields);
        $field_list = implode('`, `', array_keys($fields));

        $sql = sprintf(self::$insert_pattern, $this->getTableName(), $field_list, $placeholders);

        $this->manager->log('Executing query: ' . $sql);
        $this->manager->log('Parameters: ' . implode(', ', $log_fields));

        $pdo->prepare($sql)->execute($record_data);
        return $pdo->lastInsertId();
    }

    public function update($pk, array $data)
    {
        if (!empty($data)) {
            $condition = sprintf('%s = :pk', $this->getPrimaryKey());
            $this->updateRows($condition, array('pk' => $pk), $data);
        }
        return $pk;
    }

    public function updateRows($condition, array $parameters, array $data)
    {
        $data = array_intersect_key($data, array_flip($this->descriptor->fields));
        $fields = array();
        foreach ($data as $key => $value) {
            $fields[] = sprintf('%1$s = :%1$s', $key);
            $parameters[$key] = $value;
        }

        if (count($fields) > 0) {
            $sql = sprintf(self::$update_pattern, $this->getTableName(), implode(', ', $fields), $condition);
            $this->manager->log($sql);
            $this->manager->connection->prepare($sql)->execute($parameters);
        } else {
            $this->manager->log('Update cancelled. No valid fields were set.');
        }
    }

    /**
     *
     * @param type $pk
     */
    public function delete($pk)
    {
        $condition = sprintf('%s = :pk', $this->getPrimaryKey());
        $this->deleteRows($condition, array('pk' => $pk));
    }

    /**
     * @param string $condition
     * @param array $parameters
     * @throws PDOException
     */
    public function deleteRows($condition, array $parameters = NULL)
    {
        $message = sprintf('Deleting rows from %s', $this->descriptor->name);
        $this->manager->log($message);
        $relations_to_delete = array();
        foreach ($this->descriptor->relations as $name => $type) {
            if ($type == TableDescriptor::RELATION_HAS) {
                $relations_to_delete[] = $name;
            }
        }
        if (!empty($relations_to_delete)) {
            $message = sprintf('Also delete rows from %s', implode(', ', $relations_to_delete));
            $this->manager->log($message);
        }
        $pdo = $this->manager->connection;
        if (!empty($relations_to_delete)) {
            $sql = sprintf(self::$select_pattern, $this->getPrimaryKey(), $this->getTableName(), $condition);
            $this->manager->log($sql);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($parameters);
            $deleted_ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } else {
            $deleted_ids = array();
        }

        if (!empty($deleted_ids)) {
            $placeholders = implode(', ', array_fill(0, count($deleted_ids), '?'));

            foreach ($relations_to_delete as $relation) {
                $table = $this->getRelatedTable($relation);
                $foreign_key = $table->getForeignKey($this->descriptor->name);
                $rel_condition = sprintf('%s IN(%s)', $foreign_key, $placeholders);
                $table->deleteRows($rel_condition, $deleted_ids);
            }
        }
        $sql = sprintf(self::$delete_pattern, $this->getTableName(), $condition);
        $this->manager->log($sql);
        $pdo->prepare($sql)->execute($parameters);
    }

    //ArrayAccess methods
    public function offsetExists($offset)
    {
        try {
            $this->offsetGet($offset);
            return true;
        } catch (OutOfBoundsException $ex) {
            return false;
        }
    }

    public function offsetGet($offset)
    {
        if (!isset($this->loaded_records[$offset])) {
            $query = new Query($this);
            $condition = sprintf('%s = ?', $this->descriptor->primary_key);
            $record = $query->where($condition, $offset)->get();
            if (empty($record)) {
                throw new OutOfBoundsException('Record does not exist: ' . $offset);
            }
            $this->loaded_records[$offset] = $record;
        }
        return $this->loaded_records[$offset];
    }

    public function offsetSet($offset, $row)
    {
        if ($row instanceof Row) {
            if ($row->getTable() != $this) {
                throw new InvalidArgumentException('Cannot save row: table mismatch.');
            }
        } else if (is_array($row)) {
            $row = new Row($this, $row);
        } else {
            throw new InvalidArgumentException('Value should be a Row or an array');
        }

        $row[$this->getPrimaryKey()] = $offset;
        $row->save();
    }

    public function offsetUnset($offset)
    {
        $this->delete($offset);
    }

    //Iterator methods
    public function current()
    {
        return current($this->loaded_records);
    }

    public function key()
    {
        return key($this->loaded_records);
    }

    public function next()
    {
        return next($this->loaded_records);
    }

    public function rewind()
    {
        if (empty($this->loaded_records)) {
            $query = new Query($this);
            $this->loaded_records = $query->execute();
            if (!is_array($this->loaded_records)) {
                $pk = $this->loaded_records[$this->getPrimaryKey()];
                $this->loaded_records = array($pk => $this->loaded_records);
            }
        }
        reset($this->loaded_records);
    }

    public function valid()
    {
        $val = key($this->loaded_records);
        return $val !== NULL && $val !== false;
    }

}
