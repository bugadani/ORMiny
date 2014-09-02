<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

use ORMiny\Annotations\Relation;

class EntityMetadata
{
    private $className;
    private $tableName;
    private $primaryKey;
    private $fields = [];
    private $properties = [];
    private $setters = [];
    private $getters = [];

    /**
     * @var Relation[]
     */
    private $relations = [];
    private $relationTargets = [];

    public function __construct($className)
    {
        $this->className = $className;
    }

    /**
     * @param $object
     *
     * @throws \InvalidArgumentException
     */
    public function assertObjectInstance($object)
    {
        if (!$object instanceof $this->className) {
            throw new \InvalidArgumentException("Object must be an instance of {$this->className}");
        }
    }

    public function getClassName()
    {
        return $this->className;
    }

    public function setTable($tableName)
    {
        $this->tableName = $tableName;
    }

    public function getTable()
    {
        return $this->tableName;
    }

    public function setPrimaryKey($field)
    {
        if (!isset($this->fields[$field])) {
            throw new \InvalidArgumentException("Class {$this->className} does not have a property called {$field}");
        }
        $this->primaryKey = $field;
    }

    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    private function addPropertyField($property, $fieldName = null)
    {
        if (!property_exists($this->className, $property)) {
            throw new \InvalidArgumentException("Class {$this->className} does not have a property called {$property}");
        }
        $this->properties[$fieldName] = $property;
    }

    public function addField($property, $fieldName = null, $setter = null, $getter = null)
    {
        if (!is_string($fieldName) || empty($fieldName)) {
            $fieldName = $property;
        }
        $this->fields[$fieldName] = $fieldName;
        if ($setter === null && $getter === null) {
            $this->addPropertyField($property, $fieldName);
        } else {
            $this->registerSetterAndGetter($fieldName, $setter, $getter);
        }

        return $fieldName;
    }

    private function registerSetterAndGetter($fieldName, $setter, $getter)
    {
        if ($setter !== null) {
            if (!is_callable($this->className, $setter)) {
                throw new \InvalidArgumentException("Class {$this->className} does not have a method called {$setter}");
            }
            $this->setters[$fieldName] = $setter;
        }
        if ($getter !== null) {
            if (!is_callable($this->className, $getter)) {
                throw new \InvalidArgumentException("Class {$this->className} does not have a method called {$getter}");
            }
            $this->getters[$fieldName] = $getter;
        }
    }

    public function addRelation($property, Relation $relation, $setter = null, $getter = null)
    {
        $relationName = $relation->name;

        $this->relations[$relationName]       = $relation;
        $this->relationTargets[$relationName] = $property;

        $this->registerSetterAndGetter($property, $setter, $getter);
    }

    public function getRelation($name)
    {
        if (!isset($this->relations[$name])) {
            throw new \OutOfBoundsException("Undefined relation: {$name}");
        }

        return $this->relations[$name];
    }

    public function hasRelation($relationName)
    {
        return isset($this->relations[$relationName]);
    }

    public function getRelations()
    {
        return $this->relations;
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function setFieldValue($value, $field, $object)
    {
        $this->assertObjectInstance($object);
        if (isset($this->setters[$field])) {
            return $object->{$this->setters[$field]}($value);
        }
        if (isset($this->properties[$field])) {
            return $object->{$this->properties[$field]} = $value;
        }
        throw new \InvalidArgumentException("Class {$this->className} does not have a property called {$field}");
    }

    public function getFieldValue($object, $field)
    {
        $this->assertObjectInstance($object);
        if (isset($this->getters[$field])) {
            return $object->{$this->getters[$field]}();
        }
        if (isset($this->properties[$field])) {
            return $object->{$this->properties[$field]};
        }
        throw new \InvalidArgumentException("Class {$this->className} does not have a property called {$field}");
    }

    public function getRelationValue($object, $relationName)
    {
        $this->assertObjectInstance($object);
        if(!$this->hasRelation($relationName)) {
            throw new \OutOfBoundsException("Undefined relation: {$relationName}");
        }
        $property = $this->relationTargets[$relationName];
        if (isset($this->getters[$property])) {
            return $object->{$this->getters[$property]}();
        }
        if (isset($object->{$property})) {
            return $object->{$property};
        }

        return null;
    }

    public function setRelationValue($object, $relationName, $value)
    {
        $this->assertObjectInstance($object);

        $property = $this->relationTargets[$relationName];
        if (isset($this->setters[$property])) {
            return $object->{$this->setters[$property]}($value);
        }

        return $object->{$property} = $value;
    }
}
