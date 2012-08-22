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
    public $manager;
    public $descriptor;
    private $loaded_records = array();

    public function __construct(Manager $manager, TableDescriptor $descriptor)
    {
        $this->manager = $manager;
        $this->descriptor = $descriptor;
    }

    public function newRow(array $data = array())
    {
        return new Row($this, $data);
    }

    public function getTableName()
    {
        return sprintf($this->manager->table_format, $this->descriptor->name);
    }

    public function getPrimaryKey()
    {
        return $this->descriptor->primary_key;
    }

    public function getForeignKey($referenced)
    {
        return sprintf($this->manager->foreign_key, $referenced);
    }

    public function getRelatedTable($relation)
    {
        if (!isset($this->descriptor->relations[$relation])) {
            throw new InvalidArgumentException('Not related: ' . $relation);
        }
        return $this->manager->$relation;
    }

    public function getJoinTable($relation)
    {
        $table = $this->getRelatedTable($relation);
        return sprintf($this->manager->table_format, $this->descriptor->name . '_' . $table->descriptor->name);
    }

    public function getRelated($relation, $key)
    {
        $related = $this->getRelatedTable($relation);
        return $related[$key];
    }

    public function save(Row $row, $force_insert = false)
    {
        $pk = $this->getPrimaryKey();
        if (!isset($row[$pk]) || $force_insert) {
            $this->insert($row->toArray());
        } else {
            $this->update($row[$pk], $row->getChangedValues());
        }
//        foreach ($this->descriptor->relations as $relation => $type) {
//            if ($type == TableDescriptor::RELATION_HAS) {
//                $this->save($row->$relation);
//            }
//        }
    }

    public function insert(array $data)
    {
        $record_data = array_intersect_key($data, array_flip($this->descriptor->fields));
        $fields = array();
        foreach (array_keys($record_data) as $key) {
            $fields[$key] = ':' . $key;
        }
        $placeholders = implode(', ', $fields);
        $field_list = implode(', ', array_keys($fields));

        $pattern = 'INSERT INTO %s (%s) VALUES (%s)';
        $sql = sprintf($pattern, $this->getTableName(), $field_list, $placeholders);
        $this->manager->connection->prepare($sql)->execute($record_data);
    }

    public function update($pk, array $data)
    {
        if (empty($data)) {
            return;
        }
        $data = array_intersect_key($data, array_flip($this->descriptor->fields));
        $fields = array();
        foreach (array_keys($data) as $key) {
            $fields[] = sprintf('%1$s = :%1$s', $key);
        }
        $pattern = 'UPDATE %s SET %s WHERE %s = :pk';
        $data['pk'] = $pk;

        $sql = sprintf($pattern, $this->getTableName(), implode(', ', $fields), $this->getPrimaryKey());
        $this->manager->connection->prepare($sql)->execute($data);
    }

    public function delete($pk)
    {
        $condition = sprintf('%s = :pk', $this->getPrimaryKey());
        $this->deleteRows($condition, array('pk' => $pk));
    }

    public function deleteRows($condition, array $parameters = NULL)
    {
        $relations_to_delete = array();
        foreach ($this->descriptor->relations as $name => $type) {
            if ($type == TableDescriptor::RELATION_HAS) {
                $relations_to_delete[] = $name;
            }
        }
        if (!empty($relations_to_delete)) {
            $sql = sprintf('SELECT %s FROM %s WHERE %s', $this->getPrimaryKey(), $this->getTableName(), $condition);
            $stmt = $this->manager->connection->prepare($sql);
            $stmt->execute($parameters);
            $deleted_ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } else {
            $deleted_ids = array();
        }
        $sql = sprintf('DELETE FROM %s WHERE %s', $this->getTableName(), $condition);
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

    public function offsetExists($offset)
    {
        return $this->offsetGet($offset) !== false;
    }

    public function offsetGet($offset)
    {
        if (!isset($this->loaded_records[$offset])) {
            $query = new Query($this);
            $condition = sprintf('%s = ?', $this->descriptor->primary_key);
            $record = $query->where($condition, $offset)->get();
            if (empty($record)) {
                throw new OutOfBoundsException('Record not exists: ' . $offset);
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

    public function __call($method, $args)
    {
        $query = new Query($this);
        return call_user_func_array(array($query, $method), $args);
    }

    public function offsetUnset($offset)
    {
        $this->delete($offset);
    }

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