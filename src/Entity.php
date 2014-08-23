<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use Modules\DBAL\Driver;
use Modules\ORM\Annotations\Relation;

class Entity
{
    private $manager;
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

    /**
     * @var Entity[]
     */
    private $relatedEntities = [];
    private $relationTargets = [];

    public function __construct(EntityManager $manager, $className, $tableName)
    {
        $this->manager   = $manager;
        $this->className = $className;
        $this->tableName = $tableName;
    }

    //Metadata related methods
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

    public function isPrimaryKeySet($object)
    {
        return $this->getPrimaryKeyValue($object) !== null;
    }

    public function getPrimaryKeyValue($object)
    {
        return $this->getFieldValue($object, $this->primaryKey);
    }

    public function setFieldValue($value, $field, $object)
    {
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
        if (isset($this->getters[$field])) {
            return $object->{$this->getters[$field]}();
        }
        if (isset($this->properties[$field])) {
            return $object->{$this->properties[$field]};
        }
        throw new \InvalidArgumentException("Class {$this->className} does not have a property called {$field}");
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
    }

    public function addRelation($property, Relation $relation, $setter = null, $getter = null)
    {
        $relationName = $relation->name;

        $this->relations[$relationName]       = $relation;
        $this->relatedEntities[$relationName] = $this->manager->get($relation->target);
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

    public function getRelationValue($object, $relationName)
    {
        if (isset($this->getters[$relationName])) {
            return $object->{$this->getters[$relationName]}();
        }

        return $object->{$this->relationTargets[$relationName]};
    }

    public function setRelationValue($object, $relationName, $value)
    {
        if (isset($this->setters[$relationName])) {
            return $object->{$this->setters[$relationName]}($value);
        }

        return $object->{$this->relationTargets[$relationName]} = $value;
    }

    public function getRelatedEntity($name)
    {
        if (!isset($this->relatedEntities[$name])) {
            throw new \OutOfBoundsException("Undefined relation: {$name}");
        }

        return $this->relatedEntities[$name];
    }

    public function hasRelation($relationName)
    {
        return isset($this->relations[$relationName]);
    }

    public function getRelations()
    {
        return $this->relations;
    }

    public function getRelatedEntities()
    {
        return $this->relatedEntities;
    }

    public function getFields()
    {
        return $this->fields;
    }

    //Entity API
    public function create(array $data = [])
    {
        $object = new $this->className;
        array_walk($data, [$this, 'setFieldValue'], $object);

        return $object;
    }

    public function toArray($object)
    {
        if (!$object instanceof $this->className) {
            throw new \InvalidArgumentException("Object must be an instance of {$this->className}");
        }
        $data = [];
        foreach ($this->fields as $field) {
            $data[$field] = $this->getFieldValue($object, $field);
        }

        return $data;
    }

    public function get($primaryKey)
    {
        return $this->manager->getByPrimaryKey($this, $primaryKey);
    }

    public function save($object)
    {
        $this->manager->save($this, $object);
    }

    public function delete($object)
    {
        $this->manager->delete($this, $object);
    }

    public function deleteByPrimaryKey($pk)
    {
        $this->manager->deleteByPrimaryKey($this, $pk);
    }

    private function registerSetterAndGetter($fieldName, $setter, $getter)
    {
        if ($setter !== null) {
            if (!method_exists($this->className, $setter)) {
                throw new \InvalidArgumentException("Class {$this->className} does not have a method called {$setter}");
            }
            $this->setters[$fieldName] = $setter;
        }
        if ($getter !== null) {
            if (!method_exists($this->className, $getter)) {
                throw new \InvalidArgumentException("Class {$this->className} does not have a method called {$getter}");
            }
            $this->getters[$fieldName] = $getter;
        }
    }
}
