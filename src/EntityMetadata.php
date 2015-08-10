<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

use ORMiny\Annotations\Field as FieldAnnotation;
use ORMiny\Annotations\Relation;
use ORMiny\Metadata\Field;
use ORMiny\Metadata\Getter;
use ORMiny\Metadata\Getter\MethodGetter;
use ORMiny\Metadata\Getter\PropertyGetter;
use ORMiny\Metadata\Setter;
use ORMiny\Metadata\Setter\MethodSetter;
use ORMiny\Metadata\Setter\PropertySetter;

class EntityMetadata
{
    private $className;
    private $tableName;
    private $primaryKey;
    private $fieldNames    = [];
    private $relationNames = [];

    /**
     * @var Field[]
     */
    private $fields = [];

    /**
     * @var Relation[]
     */
    private $relations = [];

    /**
     * @var Relation[]
     */
    private $relationsByForeignKey = [];

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
            $this->fields[ $key ]->set($object, $value);
        }

        return $object;
    }

    public function toArray($object)
    {
        $this->assertObjectInstance($object);

        return array_map(
            function (Field $field) use ($object) {
                return $field->get($object);
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

    public function addField($property, FieldAnnotation $fieldAnnotation = null)
    {
        if ($fieldAnnotation === null) {
            $fieldAnnotation = new FieldAnnotation($property);
        } else if ($fieldAnnotation->name === null) {
            $fieldAnnotation->name = $property;
        }

        $this->fieldNames[ $fieldAnnotation->name ] = $fieldAnnotation->name;
        $this->fields[ $fieldAnnotation->name ]     = new Field(
            $this->createSetter($property, $fieldAnnotation),
            $this->createGetter($property, $fieldAnnotation)
        );

        return $fieldAnnotation->name;
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

        $this->relationNames[]                                = $relationName;
        $this->relations[ $relationName ]                     = $relation;
        $this->relationsByForeignKey[ $relation->foreignKey ] = $relation;
    }

    /**
     * @param $name
     *
     * @return Relation
     */
    public function getRelation($name)
    {
        if (!$this->hasRelation($name)) {
            throw new \OutOfBoundsException("Undefined relation: {$name}");
        }

        return $this->relations[ $name ];
    }

    public function getRelationNames()
    {
        return $this->relationNames;
    }

    /**
     * @param $foreignKey
     *
     * @return Relation
     */
    public function getRelationByForeignKey($foreignKey)
    {
        if (!isset($this->relationsByForeignKey[ $foreignKey ])) {
            throw new \OutOfBoundsException("Undefined foreign key: {$foreignKey}");
        }

        return $this->relationsByForeignKey[ $foreignKey ];
    }

    /**
     * @param $relationName
     *
     * @return bool
     */
    public function hasRelation($relationName)
    {
        return isset($this->relations[ $relationName ]);
    }

    /**
     * @return Annotations\Relation[]
     */
    public function getRelations()
    {
        return $this->relations;
    }

    /**
     * @return array
     */
    public function getFieldNames()
    {
        return $this->fieldNames;
    }

    /**
     * @return Field[]
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * @param $field
     *
     * @return Field
     */
    public function getField($field)
    {
        return $this->fields[ $field ];
    }

    /**
     * @return Field
     */
    public function getPrimaryKeyField()
    {
        return $this->fields[ $this->primaryKey ];
    }

    /**
     * @param                 $property
     * @param FieldAnnotation $fieldAnnotation
     * @return Setter
     */
    private function createSetter($property, FieldAnnotation $fieldAnnotation)
    {
        if ($fieldAnnotation->setter === null) {
            $setter = new PropertySetter($this, $property);
        } else {
            if ($fieldAnnotation->setter === true) {
                $methodName = 'set' . ucfirst($property);
            } else {
                $methodName = $fieldAnnotation->setter;
            }
            $setter = new MethodSetter($this, $methodName);
        }

        return $setter;
    }

    /**
     * @param                 $property
     * @param FieldAnnotation $fieldAnnotation
     * @return Getter
     */
    private function createGetter($property, FieldAnnotation $fieldAnnotation)
    {
        if ($fieldAnnotation->getter === null) {
            $getter = new PropertyGetter($this, $property);
        } else {
            if ($fieldAnnotation->getter === true) {
                $methodName = 'get' . ucfirst($property);
            } else {
                $methodName = $fieldAnnotation->getter;
            }
            $getter = new MethodGetter($this, $methodName);
        }

        return $getter;
    }
}
