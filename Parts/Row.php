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
    private $table;
    private $data;
    private $related;
    private $changed = array();

    public function __construct(Table $table, array $data = array())
    {
        $this->table = $table;
        $this->data = $data;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function getChangedValues()
    {
        $return = array();
        foreach ($this->changed as $key) {
            $return[$key] = $this->data[$key];
        }
        return $return;
    }

    public function __get($related)
    {
        if (!isset($this->related[$related])) {
            $foreign_key = $this->table->getForeignKey($related);
            if (!isset($this->data[$foreign_key])) {
                throw new OutOfBoundsException('Foreign key is not set: ' . $foreign_key);
            }
            $this->related[$related] = $this->table->getRelated($related, $this->data[$foreign_key]);
        }
        return $this->related[$related];
    }

    public function save($force_insert = false)
    {
        $this->table->save($this, $force_insert);
    }

    public function delete()
    {
        $this->table->delete($this->data[$this->table->getPrimaryKey()]);
    }

    public function offsetExists($offset)
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet($offset)
    {
        if (!isset($this->data[$offset])) {
            throw new OutOfBoundsException('Key not set: ' . $offset);
        }
        return $this->data[$offset];
    }

    public function offsetSet($offset, $value)
    {
        if (in_array($offset, $this->table->descriptor->fields)) {
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

    public function getIterator()
    {
        return new ArrayIterator($this->data);
    }

    public function toArray()
    {
        return $this->data;
    }

}