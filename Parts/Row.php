<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM\Parts;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use OutOfBoundsException;

class Row implements ArrayAccess, IteratorAggregate
{
    /**
     * @var Table
     */
    private $table;

    /**
     * @var array
     */
    private $data = array();

    /**
     * @var array
     */
    private $related = array();

    /**
     * @var array
     */
    private $changed = array();

    /**
     * @param Table $table
     * @param array $data
     */
    public function __construct(Table $table, array $data = array())
    {
        $this->table = $table;
        //Set existing field keys.
        foreach ($table->descriptor->fields as $field) {
            $this->data[$field] = null;
        }
        foreach ($data as $field => $value) {
            $this->data[$field] = $value;
        }
    }

    /**
     * @return Table
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * @return array
     */
    public function getChangedValues()
    {
        $return = array();
        foreach ($this->changed as $key) {
            $return[$key] = $this->data[$key];
        }
        return $return;
    }

    private function getRelatedRows($related, $condition = NULL)
    {
        $table = $this->getTable();
        $related_table = $table->getRelatedTable($related);
        switch ($table->descriptor->getRelation($related)) {
            case TableDescriptor::RELATION_HAS: {
                    $foreign_key = $table->getForeignKey($table);
                    $where = sprintf('`%s` = ?', $foreign_key);
                    if ($condition) {
                        $where .= ' AND ' . $condition;
                    }
                    $key = $table->getPrimaryKey();
                    return $related_table->where($where, $this[$key])->get(false);
                }
            case TableDescriptor::RELATION_BELONGS_TO: {
                    $key = $table->getForeignKey($related);
                    $where = sprintf('`%s` = ?', $related_table->getPrimaryKey());
                    if ($condition) {
                        $where .= ' AND ' . $condition;
                    }
                    return $related_table->where($where, $this[$key])->get();
                }
            case TableDescriptor::RELATION_MANY_MANY: {
                    $join_table = $table->getJoinTableName($related);
                    //query the join table for the ids - this in turn fills up the record with the join table data
                    $ids = array();
                    $fk = $table->getForeignKey($related);
                    foreach ($this->$join_table as $join_record) {
                        $ids[] = $join_record[$fk];
                    }
                    if (count($ids) > 0) {
                        //query the related table for records
                        $qms = array_fill(0, count($ids), '?');
                        $where = sprintf('`%s` IN(%s)', $related_table->getPrimaryKey(), implode(',', $qms));
                        if ($condition) {
                            $where .= ' AND ' . $condition;
                        }
                        return $related_table->where($where, $ids)->get(false);
                    }
                }
        }
    }

    public function __call($related, array $args)
    {
        $where = implode(' AND ', $args);
        return $this->getRelatedRows($related, $where);
    }

    public function &__get($related)
    {
        if (!isset($this->related[$related])) {
            $this->related[$related] = $this->getRelatedRows($related);
        }
        return $this->related[$related];
    }

    public function __set($related, $value)
    {
        $this->related[$related] = $value;
    }

    public function __isset($related)
    {
        return isset($this->related[$related]);
    }

    /**
     * @param bool $force_insert
     */
    public function save($force_insert = false)
    {
        return $this->getTable()->save($this, $force_insert);
    }

    public function delete()
    {
        $this->getTable()->delete($this->data[$this->table->getPrimaryKey()]);
    }

    //ArrayAccess methods
    public function offsetExists($offset)
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet($offset)
    {
        if (!array_key_exists($offset, $this->data)) {
            throw new OutOfBoundsException(sprintf('Key "%s" is not set', $offset));
        }
        return $this->data[$offset];
    }

    public function offsetSet($offset, $value)
    {
        if (in_array($offset, $this->table->descriptor->fields)) {
            if (array_key_exists($offset, $this->data)) {
                if ($this->data[$offset] == $value) {
                    //Don't flag unchanged values as changed.
                    return;
                }
            }
            if (!in_array($offset, $this->changed)) {
                $this->changed[] = $offset;
            }
        }
        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset)
    {
        $this->offsetSet($offset, NULL);
    }

    //IteratorAggregate method
    public function getIterator()
    {
        return new ArrayIterator($this->data);
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return array_merge($this->data, $this->related);
    }

}
