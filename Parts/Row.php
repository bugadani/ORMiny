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
     * @var \Modules\ORM\Parts\Table
     */
    private $table;

    /**
     * @var array
     */
    private $data;

    /**
     * @var array
     */
    private $related = array();

    /**
     * @var array
     */
    private $changed = array();

    /**
     * @param \Modules\ORM\Parts\Table $table
     * @param array $data
     */
    public function __construct(Table $table, array $data = array())
    {
        $this->table = $table;
        $this->data = $data;
    }

    /**
     * @return \Modules\ORM\Parts\Table
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

    /**
     * @param bool $force_insert
     */
    public function save($force_insert = false)
    {
        $this->table->save($this, $force_insert);
    }

    public function delete()
    {
        $this->table->delete($this->data[$this->table->getPrimaryKey()]);
    }

    //ArrayAccess methods
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
        return $this->data;
    }

}
