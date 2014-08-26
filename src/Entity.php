<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use Modules\DBAL\Driver;
use Modules\DBAL\QueryBuilder;
use Modules\ORM\Annotations\Relation;

class Entity
{
    const STATE_NEW     = 1;
    const STATE_HANDLED = 2;

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

    private $originalData = [];
    private $objectStates = [];
    private $objectRelations = [];

    private $relatedObjectHandles = [];

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

        return $fieldName;
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
        $relatedEntity = $this->getRelatedEntity($relationName);
        if (is_array($value)) {
            $foreignKeys = array_map([$relatedEntity, 'getPrimaryKeyValue'], $value);
        } else {
            $foreignKeys = $relatedEntity->getPrimaryKeyValue($value);
        }
        $objectId = spl_object_hash($object);

        $this->objectRelations[$objectId]      = $foreignKeys;
        $this->relatedObjectHandles[$objectId] = $object;

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

        $objectId = spl_object_hash($object);

        $this->originalData[$objectId]    = $data;
        $this->objectRelations[$objectId] = [];
        if ($this->isPrimaryKeySet($object)) {
            $this->objectStates[$objectId] = self::STATE_HANDLED;
        } else {
            $this->objectStates[$objectId] = self::STATE_NEW;
        }
        $this->relatedObjectHandles[$objectId] = $object;

        return $object;
    }

    public function toArray($object)
    {
        $this->checkObjectInstance($object);
        $data = [];
        foreach ($this->fields as $field) {
            $data[$field] = $this->getFieldValue($object, $field);
        }

        return $data;
    }

    public function find()
    {
        return new EntityFinder(
            $this->manager->getResultProcessor(),
            $this->manager->getDriver(),
            $this
        );
    }

    public function get($primaryKey)
    {
        return call_user_func_array([$this->find(), 'get'], func_get_args());
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

    public function delete($object)
    {
        $this->checkObjectInstance($object);
        $queryBuilder = $this->manager->getDriver()->getQueryBuilder();
        foreach ($this->getRelatedEntities() as $relationName => $relatedEntity) {
            $relatedObjects = $this->getRelationValue($object, $relationName);
            $relation       = $this->getRelation($relationName);
            switch ($relation->type) {
                case Relation::HAS_ONE:
                    $this->delete($relatedEntity, $relatedObjects);
                    break;

                case Relation::HAS_MANY:
                    array_map([$relatedEntity, 'delete'], $relatedObjects);
                    break;

                case Relation::MANY_MANY:
                    $joinTable = $this->getTable() . '_' . $relatedEntity->getTable();
                    $field     = $this->getTable() . '_' . $relation->foreignKey;

                    $queryBuilder
                        ->delete($joinTable)
                        ->where(
                            $queryBuilder->expression()->in(
                                $field,
                                array_map(
                                    [$queryBuilder, 'createPositionalParameter'],
                                    array_map(
                                        [$relatedEntity, 'getPrimaryKeyValue'],
                                        $relatedObjects
                                    )
                                )
                            )
                        )->query();
                    break;

                case Relation::BELONGS_TO:
                    //don't delete the record this one belongs to
                    break;
            }
        }

        if ($this->isPrimaryKeySet($object)) {
            $queryBuilder->delete($this->getTable())
                ->where(
                    $queryBuilder->expression()->eq(
                        $this->getPrimaryKey(),
                        $queryBuilder->createPositionalParameter(
                            $this->getPrimaryKeyValue($object)
                        )
                    )
                )->query();
        }
    }

    public function save($object)
    {
        $this->checkObjectInstance($object);
        $objectId = spl_object_hash($object);

        if (!isset($this->objectStates[$objectId]) || $this->relatedObjectHandles[$objectId] !== $object) {
            $this->objectStates[$objectId]         = self::STATE_NEW;
            $this->originalData[$objectId]         = [];
            $this->objectRelations[$objectId]      = [];
            $this->relatedObjectHandles[$objectId] = $object;
        }

        $modifiedManyManyRelations = [];
        foreach ($this->getRelations() as $relationName => $relation) {
            $relatedEntity = $this->getRelatedEntity($relationName);
            switch ($relation->type) {
                case Relation::MANY_MANY:
                    $relatedObjects = $this->getRelationValue($object, $relationName);

                    array_map([$relatedEntity, 'save'], $relatedObjects);
                    $currentForeignKeys = array_map(
                        [$relatedEntity, 'getPrimaryKeyValue'],
                        $relatedObjects
                    );

                    $originalForeignKeys = $this->objectRelations[$objectId];

                    $deleted = array_diff($originalForeignKeys, $currentForeignKeys);
                    if (!empty($deleted)) {
                        $modifiedManyManyRelations[$relationName]['deleted'] = $deleted;
                    }

                    $inserted = array_diff($currentForeignKeys, $originalForeignKeys);
                    if (!empty($inserted)) {
                        $modifiedManyManyRelations[$relationName]['inserted'] = $inserted;
                    }
                    break;

                case Relation::HAS_MANY:
                    break;
            }

            //if $object foreign key hasn't changed but related object's pk is different
            //overwrite the local foreign key
            //if the local key changed that change should be respected
        }

        if ($this->objectStates[$objectId] === self::STATE_NEW) {
            $primaryKey = $this->insert($object);
        } else {
            $primaryKey = $this->update($object);
        }

        $this->originalData[$objectId] = $this->toArray($object);

        $this->updateManyToManyRelations($modifiedManyManyRelations, $primaryKey);
    }

    private function createInExpression($field, array $values, QueryBuilder $queryBuilder)
    {
        $expression = $queryBuilder->expression();
        if (count($values) === 1) {
            $expression->eq(
                $field,
                $queryBuilder->createPositionalParameter(current($values))
            );
        } else {
            $expression->in(
                $field,
                array_map([$queryBuilder, 'createPositionalParameter'], $values)
            );
        }

        return $expression;
    }

    private function getOriginalData($object, $field)
    {
        return $this->originalData[spl_object_hash($object)][$field];
    }

    /**
     * @param $object
     *
     * @throws \InvalidArgumentException
     */
    private function checkObjectInstance($object)
    {
        if (!$object instanceof $this->className) {
            throw new \InvalidArgumentException("Object must be an instance of {$this->className}");
        }
    }

    private function insert($object)
    {
        $data = array_filter(
            $this->toArray($object),
            function ($value) {
                return $value !== null;
            }
        );

        $queryBuilder = $this->manager->getDriver()->getQueryBuilder();
        $query        = $queryBuilder->insert($this->getTable());

        $primaryKey = $query
            ->values(array_map([$query, 'createPositionalParameter'], $data))
            ->query();

        $this->setFieldValue($primaryKey, $this->getPrimaryKey(), $object);

        $this->objectStates[spl_object_hash($object)] = self::STATE_HANDLED;

        return $primaryKey;
    }

    private function update($object)
    {
        $queryBuilder = $this->manager->getDriver()->getQueryBuilder();
        $data         = array_diff(
            $this->toArray($object),
            $this->originalData[spl_object_hash($object)]
        );

        //only save when a change is detected
        if (!empty($data)) {
            $query = $queryBuilder
                ->update($this->getTable())
                ->where(
                    $queryBuilder->expression()->eq(
                        $this->getPrimaryKey(),
                        $queryBuilder->createPositionalParameter(
                            $this->getOriginalData($object, $this->getPrimaryKey())
                        )
                    )
                );

            $query->values(array_map([$query, 'createPositionalParameter'], $data))->query();
        }

        return $this->getPrimaryKeyValue($object);
    }

    /**
     * @param $modifiedManyManyRelations
     * @param $primaryKey
     */
    private function updateManyToManyRelations($modifiedManyManyRelations, $primaryKey)
    {
        $queryBuilder = $this->manager->getDriver()->getQueryBuilder();
        foreach ($modifiedManyManyRelations as $relationName => $keys) {
            $relation      = $this->getRelation($relationName);
            $relatedEntity = $this->getRelatedEntity($relationName);

            $entityTable  = $this->getTable();
            $relatedTable = $relatedEntity->getTable();

            $joinTable = $entityTable . '_' . $relatedTable;
            $leftKey   = $entityTable . '_' . $relation->foreignKey;
            $rightKey  = $relatedEntity->getTable() . '_' . $relation->targetKey;

            if (isset($keys['deleted'])) {
                $expression = $queryBuilder->expression();
                $queryBuilder
                    ->delete($joinTable)
                    ->where(
                        $expression
                            ->eq(
                                $leftKey,
                                $queryBuilder->createPositionalParameter($primaryKey)
                            )
                            ->andX(
                                $this->createInExpression(
                                    $rightKey,
                                    $keys['deleted'],
                                    $queryBuilder
                                )
                            )
                    )->query();
            }
            if (isset($keys['inserted'])) {
                $insertQuery = $queryBuilder
                    ->insert($joinTable)
                    ->values(
                        [
                            $leftKey  => $queryBuilder->createPositionalParameter($primaryKey),
                            $rightKey => '?'
                        ]
                    );
                foreach ($keys['inserted'] as $foreignKey) {
                    $insertQuery->query([1 => $foreignKey]);
                }
            }
        }
    }
}
