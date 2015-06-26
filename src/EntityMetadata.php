<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

use ORMiny\Annotations\Field;
use ORMiny\Annotations\Relation;

class EntityMetadata
{
    private $className;
    private $tableName;
    private $primaryKey;
    private $fieldNames            = [];
    private $relationTargets       = [];
    private $relationsByForeignKey = [];

    /**
     * @var Field[]
     */
    private $fields = [];

    /**
     * @var Relation[]
     */
    private $relations = [];

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

    public function create(array $data = [])
    {
        $className = $this->getClassName();
        $object    = new $className;
        foreach ($data as $key => $value) {
            $this->fields[ $key ]->setValue($object, $value);
        }

        return $object;
    }

    public function toArray($object)
    {
        $this->assertObjectInstance($object);

        return array_map(
            function (Field $field) use ($object) {
                return $field->getValue($object);
            },
            $this->getFields()
        );
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
        if (!isset($this->fields[ $field ])) {
            throw new \InvalidArgumentException("Class {$this->className} does not have a property called {$field}");
        }
        $this->primaryKey = $field;
    }

    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    public function addField($property, Field $field = null)
    {
        if ($field === null) {
            $field = new Field($property);
        } else if ($field->name === null) {
            $field->name = $property;
        }

        if ($field->setter === null) {
            $field->setter = $property;
        } else if ($field->setter === true) {
            $field->setter = 'set' . ucfirst($property);
        }

        if ($field->getter === null) {
            $field->getter = $property;
        } else if ($field->getter === true) {
            $field->getter = 'get' . ucfirst($property);
        }

        $this->fieldNames[ $field->name ] = $field->name;
        $this->fields[ $field->name ]     = $field;

        return $field->name;
    }

    public function addRelation($property, Relation $relation)
    {
        $relationName = $relation->name;

        if ($relation->setter === null) {
            $relation->setter = $property;
        } else if ($relation->setter === true) {
            $relation->setter = 'set' . ucfirst($property);
        }

        if ($relation->getter === null) {
            $relation->getter = $property;
        } else if ($relation->getter === true) {
            $relation->getter = 'get' . ucfirst($property);
        }

        $this->relations[ $relationName ]                     = $relation;
        $this->relationTargets[ $relationName ]               = $property;
        $this->relationsByForeignKey[ $relation->foreignKey ] = $relation;
    }

    public function getRelation($name)
    {
        if (!isset($this->relations[ $name ])) {
            throw new \OutOfBoundsException("Undefined relation: {$name}");
        }

        return $this->relations[ $name ];
    }

    public function getRelationByForeignKey($foreignKey)
    {
        if (!isset($this->relationsByForeignKey[ $foreignKey ])) {
            throw new \OutOfBoundsException("Undefined foreign key: {$foreignKey}");
        }

        return $this->relationsByForeignKey[ $foreignKey ];
    }

    public function hasRelation($relationName)
    {
        return isset($this->relations[ $relationName ]);
    }

    public function getRelations()
    {
        return $this->relations;
    }

    public function getFieldNames()
    {
        return $this->fieldNames;
    }

    /**
     * @return Annotations\Field[]
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * @param $object
     * @param $field
     * @param $value
     */
    public function setFieldValue($object, $field, $value)
    {
        $this->assertObjectInstance($object);

        $this->fields[ $field ]->setValue($object, $value);
    }

    /**
     * @param $object
     * @param $field
     *
     * @return mixed
     */
    public function getFieldValue($object, $field)
    {
        $this->assertObjectInstance($object);

        return $this->fields[ $field ]->getValue($object);
    }

    public function getRelationValue($object, $relationName)
    {
        $this->assertObjectInstance($object);
        if (!$this->hasRelation($relationName)) {
            throw new \OutOfBoundsException("Undefined relation: {$relationName}");
        }

        return $this->relations[ $relationName ]->getValue($object);
    }

    public function setRelationValue($object, $relationName, $value)
    {
        $this->assertObjectInstance($object);
        if (!$this->hasRelation($relationName)) {
            throw new \OutOfBoundsException("Undefined relation: {$relationName}");
        }

        $this->relations[ $relationName ]->setValue($object, $value);
    }
}
