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
    private static $select_pattern = 'SELECT %s FROM %s WHERE %s';
    private static $insert_pattern = 'INSERT INTO %s (%s) VALUES (%s)';
    private static $update_pattern = 'UPDATE %s SET %s WHERE %s = :pk';
    private static $delete_pattern = 'DELETE FROM %s WHERE %s';

    /**
     * @var \Modules\ORM\Manager
     */
    public $manager;

    /**
     * @var \Modules\ORM\Parts\TableDescriptor
     */
    public $descriptor;

    /**
     * @var array
     */
    private $loaded_records = array();

    /**
     * @param \Modules\ORM\Manager $manager
     * @param \Modules\ORM\Parts\TableDescriptor $descriptor
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
     * @param array $data
     * @return \Modules\ORM\Parts\Row
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
     * @param string $referenced
     * @return string
     */
    public function getForeignKey($referenced)
    {
        return sprintf($this->manager->foreign_key, $referenced);
    }

    /**
     * @param string $relation
     * @return \Modules\ORM\Parts\Table
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
        return sprintf($this->manager->table_format, $this->descriptor->name . '_' . $table->descriptor->name);
    }

    /**
     * @param string $relation
     * @param mixed $key
     * @return \Modules\ORM\Parts\Row
     */
    public function getRelated($relation, $key)
    {
        $related = $this->getRelatedTable($relation);
        return $related[$key];
    }

    /**
     * @param \Modules\ORM\Parts\Row $row
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
        foreach (array_keys($record_data) as $key) {
            $fields[$key] = ':' . $key;
        }
        $placeholders = implode(', ', $fields);
        $field_list = implode(', ', array_keys($fields));

        $sql = sprintf(self::$insert_pattern, $this->getTableName(), $field_list, $placeholders);
        $pdo->prepare($sql)->execute($record_data);
        return $pdo->lastInsertId();
    }

    public function update($pk, array $data)
    {
        if (empty($data)) {
            return $pk;
        }
        $data = array_intersect_key($data, array_flip($this->descriptor->fields));
        $fields = array();
        foreach (array_keys($data) as $key) {
            $fields[] = sprintf('%1$s = :%1$s', $key);
        }
        $data['pk'] = $pk;

        $sql = sprintf(self::$update_pattern, $this->getTableName(), implode(', ', $fields), $this->getPrimaryKey());
        $this->manager->connection->prepare($sql)->execute($data);
        return $pk;
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
        $relations_to_delete = array();
        foreach ($this->descriptor->relations as $name => $type) {
            if ($type == TableDescriptor::RELATION_HAS) {
                $relations_to_delete[] = $name;
            }
        }
        if (!empty($relations_to_delete)) {
            $sql = sprintf(self::$select_pattern, $this->getPrimaryKey(), $this->getTableName(), $condition);
            $stmt = $this->manager->connection->prepare($sql);
            $stmt->execute($parameters);
            $deleted_ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } else {
            $deleted_ids = array();
        }
        $sql = sprintf(self::$delete_pattern, $this->getTableName(), $condition);
        $this->manager->connection->prepare($sql)->execute($parameters);

        if (empty($deleted_ids)) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($deleted_ids), '?'));

        $this->manager->connection->beginTransaction();
        try {
            foreach ($relations_to_delete as $relation) {
                $table = $this->getRelatedTable($relation);
                $foreign_key = $table->getForeignKey($this->descriptor->name);
                $condition = sprintf('%s IN(%s)', $foreign_key, $placeholders);
                $table->deleteRows($condition, $deleted_ids);
            }
            $this->manager->connection->commit();
        } catch (PDOException $e) {
            $this->manager->connection->rollback();
            throw $e;
        }
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
        } elseif (is_array($row)) {
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
