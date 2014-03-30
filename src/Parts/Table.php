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
use PDOException;

class Table implements ArrayAccess, Iterator
{
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
    private $loadedRecords = array();

    /**
     * @param Manager         $manager
     * @param TableDescriptor $descriptor
     */
    public function __construct(Manager $manager, TableDescriptor $descriptor)
    {
        $this->manager    = $manager;
        $this->descriptor = $descriptor;
    }

    public function __call($method, $args)
    {
        $query = new Query($this);

        return call_user_func_array(array($query, $method), $args);
    }

    /**
     * __toString returns the table's ID
     *
     * @return string
     */
    public function __toString()
    {
        return $this->descriptor->name;
    }

    /**
     * @param array $data
     *
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
        return sprintf($this->manager->getTableNameFormat(), $this->descriptor->name);
    }

    /**
     * @param bool $prependTableName
     *
     * @return string
     */
    public function getPrimaryKey($prependTableName = false)
    {
        if ($prependTableName) {
            return $this->getTableName() . '.' . $this->descriptor->primary_key;
        }

        return $this->descriptor->primary_key;
    }

    /**
     * @param Table|TableDescriptor|string $referenced
     *
     * @return string
     */
    public function getForeignKey($referenced)
    {
        $fk = $this->manager->getForeignKeyFormat();
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
     *
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
     *
     * @return string
     */
    public function getJoinTable($relation)
    {
        $table = $this->getRelatedTable($relation);

        return sprintf($this->manager->getTableNameFormat(), $this . '_' . $table);
    }

    /**
     * @param string $related
     *
     * @throws \InvalidArgumentException
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
     * @param mixed  $key
     *
     * @return Row
     */
    public function getRelated($relation, $key)
    {
        $related = $this->getRelatedTable($relation);

        return $related[$key];
    }

    /**
     * @param Row  $row
     * @param bool $force_insert
     *
     * @return int
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
     *
     * @return int
     */
    public function insert(array $data)
    {
        $record_data = array_intersect_key($data, array_flip($this->descriptor->fields));
        $pdo         = $this->manager->connection;

        $insert = $pdo->getQueryBuilder()->insert($this->getTableName());

        $log_fields = array();
        foreach ($record_data as $key => $data) {
            $insert->set($pdo->quoteIdentifier($key), ':' . $key);

            $log_fields[] = sprintf(':%s = "%s"', $key, $data);
        }

        $sql = $insert->get();
        $this->manager->log('Executing query: ' . $sql);
        $this->manager->log('Parameters: ' . implode(', ', $log_fields));

        $pdo->query($sql, $record_data);

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

        $pdo    = $this->manager->connection;
        $update = $pdo->getQueryBuilder()
            ->update($this->getTableName())
            ->where($condition);

        foreach ($data as $key => $value) {
            $update->set($pdo->quoteIdentifier($key), ':' . $key);
            $parameters[$key] = $value;
        }

        if (count($parameters) > 1) {
            $sql = $update->get();
            $this->manager->log($sql);
            $this->manager->connection->query($sql, $parameters);
        } else {
            $this->manager->log('Update cancelled. No valid fields were set.');
        }
    }

    /**
     *
     * @param mixed $pk
     */
    public function delete($pk)
    {
        $condition = sprintf('%s = :pk', $this->getPrimaryKey());
        $this->deleteRows($condition, array('pk' => $pk));
    }

    /**
     * @param string $condition
     * @param array  $parameters
     *
     * @throws PDOException
     */
    public function deleteRows($condition, array $parameters = null)
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
        $pdo          = $this->manager->connection;
        $queryBuilder = $pdo->getQueryBuilder();

        if (!empty($relations_to_delete)) {
            $sql = $queryBuilder
                ->select($this->getPrimaryKey())
                ->from($this->getTableName())
                ->where($condition)->get();

            $this->manager->log($sql);
            $deleted_ids = $pdo->fetchColumn($sql, $parameters, 0);

            if (!empty($deleted_ids)) {
                $placeholders = array_fill(0, count($deleted_ids), '?');

                foreach ($relations_to_delete as $relation) {
                    $table       = $this->getRelatedTable($relation);
                    $foreign_key = $table->getForeignKey($this->descriptor->name);
                    $expression  = $queryBuilder->expression()->in($foreign_key, $placeholders);
                    $table->deleteRows($expression->get(), (array)$deleted_ids);
                }
            }
        }

        $sql = $queryBuilder->delete($this->getTableName())->where($condition)->get();

        $this->manager->log($sql);
        $pdo->query($sql, $parameters);
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
        if (!isset($this->loadedRecords[$offset])) {
            $query     = new Query($this);
            $condition = sprintf('%s = ?', $this->descriptor->primary_key);
            $record    = $query->where($condition, $offset)->get();
            if (empty($record)) {
                throw new OutOfBoundsException('Record does not exist: ' . $offset);
            }
            $this->loadedRecords[$offset] = $record;
        }

        return $this->loadedRecords[$offset];
    }

    public function offsetSet($offset, $row)
    {
        if ($row instanceof Row) {
            if ($row->getTable() !== $this) {
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
        return current($this->loadedRecords);
    }

    public function key()
    {
        return key($this->loadedRecords);
    }

    public function next()
    {
        return next($this->loadedRecords);
    }

    public function rewind()
    {
        if (empty($this->loadedRecords)) {
            $query               = new Query($this);
            $this->loadedRecords = $query->execute();
            if (!is_array($this->loadedRecords)) {
                $pk                  = $this->loadedRecords[$this->getPrimaryKey()];
                $this->loadedRecords = array($pk => $this->loadedRecords);
            }
        }
        reset($this->loadedRecords);
    }

    public function valid()
    {
        $val = key($this->loadedRecords);

        return $val !== null && $val !== false;
    }

}
