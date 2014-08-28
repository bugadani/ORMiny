<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

use Modules\DBAL\Driver;
use Modules\DBAL\QueryBuilder;
use Modules\DBAL\QueryBuilder\Expression;
use ORMiny\Annotations\Relation;

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
    private $readOnlyObjectHandles = [];

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
        $this->checkObjectInstance($object);
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
        $this->checkObjectInstance($object);
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
        $this->checkObjectInstance($object);
        $property = $this->relationTargets[$relationName];
        if (isset($this->getters[$property])) {
            return $object->{$this->getters[$property]}();
        }

        return $object->{$property};
    }

    public function setRelationValue($object, $relationName, $value)
    {
        $this->checkObjectInstance($object);

        $relatedEntity = $this->getRelatedEntity($relationName);
        $relation      = $this->getRelation($relationName);

        $objectId = spl_object_hash($object);

        $this->relatedObjectHandles[$objectId] = $object;

        switch ($relation->type) {
            case Relation::MANY_MANY:
                $targetKey = $relation->targetKey;

                $this->objectRelations[$objectId] = [];
                foreach ($value as $relatedObject) {
                    $this->objectRelations[$objectId][] = $relatedEntity->getFieldValue(
                        $relatedObject,
                        $targetKey
                    );
                }
                break;

            case Relation::HAS_MANY:
                $this->objectRelations[$objectId] = array_map(
                    [$relatedEntity, 'getPrimaryKeyValue'],
                    (array)$value
                );
                break;

            default:
                $this->objectRelations[$objectId] = $relatedEntity->getPrimaryKeyValue($value);
                break;
        }

        $property = $this->relationTargets[$relationName];
        if (isset($this->setters[$property])) {
            return $object->{$this->setters[$property]}($value);
        }

        return $object->{$property} = $value;
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

        if ($this->isPrimaryKeySet($object)) {
            $this->objectStates[$objectId] = self::STATE_HANDLED;
        } else {
            $this->objectStates[$objectId] = self::STATE_NEW;
        }

        $this->originalData[$objectId]         = $data;
        $this->objectRelations[$objectId]      = [];
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

    /**
     * @return Expression
     */
    public function expression()
    {
        return $this->manager->getDriver()->getQueryBuilder()->expression();
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
            if(empty($relatedObjects)) {
                continue;
            }
            switch ($relation->type) {
                case Relation::HAS_MANY:
                    call_user_func_array(
                        [$relatedEntity->find(), 'delete'],
                        array_map(
                            function ($object) use ($relatedEntity) {
                                if (!is_object($object)) {
                                    return $object;
                                }

                                return $relatedEntity->getPrimaryKeyValue($object);
                            },
                            $relatedObjects
                        )
                    );
                    break;

                case Relation::MANY_MANY:
                    $queryBuilder
                        ->delete($this->getTable() . '_' . $relatedEntity->getTable())
                        ->where(
                            $queryBuilder->expression()->in(
                                $this->getTable() . '_' . $relation->foreignKey,
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

                case Relation::HAS_ONE:
                    if ($relatedObjects) {
                        $relatedEntity->delete($relatedObjects);
                    }
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
            unset($this->relatedObjectHandles[spl_object_hash($object)]);
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
        } elseif (isset($this->readOnlyObjectHandles[$objectId])) {
            return;
        }

        $modifiedManyManyRelations = [];
        foreach ($this->getRelations() as $relationName => $relation) {
            $relatedEntity = $this->getRelatedEntity($relationName);
            switch ($relation->type) {
                case Relation::MANY_MANY:
                    $relatedObjects = $this->getRelationValue($object, $relationName);

                    $currentForeignKeys = array_map(
                        function ($object) use ($relatedEntity) {
                            if (!is_object($object)) {
                                return $object;
                            }
                            $relatedEntity->save($object);

                            return $relatedEntity->getPrimaryKeyValue($object);
                        },
                        $relatedObjects
                    );

                    $originalForeignKeys = $this->objectRelations[$objectId];

                    $deleted  = array_diff($originalForeignKeys, $currentForeignKeys);
                    $inserted = array_diff($currentForeignKeys, $originalForeignKeys);

                    $modifiedManyManyRelations[$relationName]['deleted']  = $deleted;
                    $modifiedManyManyRelations[$relationName]['inserted'] = $inserted;

                    $this->objectRelations[$objectId] = array_diff($deleted, $currentForeignKeys);
                    break;

                case Relation::HAS_MANY:
                    $currentForeignKeys = [];
                    foreach ($this->getRelationValue($object, $relationName) as $relatedObject) {
                        $currentForeignKeys[] = $relatedEntity->getOriginalData(
                            $relatedObject,
                            $relatedEntity->getPrimaryKey()
                        );
                        $relatedEntity->setFieldValue(
                            $this->getFieldValue($object, $relation->foreignKey),
                            $relation->targetKey,
                            $relatedObject
                        );
                        $relatedEntity->save($relatedObject);
                    }

                    $deleted = array_diff($this->objectRelations[$objectId], $currentForeignKeys);
                    call_user_func_array([$relatedEntity->find(), 'delete'], $deleted);
                    $this->objectRelations[$objectId] = array_diff($deleted, $currentForeignKeys);
                    break;

                case Relation::HAS_ONE:
                case Relation::BELONGS_TO:
                    //checking the foreign key is not enough here - foreign key is not updated yet.
                    if (isset($this->getters[$relationName])) {
                        $foreignKeyValue = $object->{$this->getters[$relationName]}();
                    } elseif (isset($object->{$this->relationTargets[$relationName]})) {
                        $foreignKeyValue = $object->{$this->relationTargets[$relationName]};
                    } else {
                        $foreignKeyValue = null;
                    }

                    $this->setFieldValue($foreignKeyValue, $relation->foreignKey, $object);
                    $this->objectRelations[$objectId] = $foreignKeyValue;
                    break;
            }
        }

        if ($this->objectStates[$objectId] === self::STATE_NEW) {
            $primaryKey = $this->insert($object);
        } else {
            $primaryKey = $this->update($object);
        }

        foreach ($this->getRelations() as $relationName => $relation) {
            switch ($relation->type) {
                case Relation::HAS_ONE:
                case Relation::BELONGS_TO:
                    $relatedEntity = $this->getRelatedEntity($relationName);
                    if ($this->getFieldValue($object, $relation->foreignKey) !== null) {
                        $relatedEntity->save(
                            $this->getRelationValue($object, $relationName)
                        );
                    } else {
                        $relatedEntity
                            ->find()
                            ->delete($this->objectRelations[$objectId][$relationName]);
                    }
                    break;
            }
        }

        $this->objectStates[$objectId] = self::STATE_HANDLED;
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
        $this->checkObjectInstance($object);

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
        $queryBuilder = $this->manager->getDriver()->getQueryBuilder();
        $query        = $queryBuilder->insert($this->getTable());

        $primaryKey = $query->values(
            array_map(
                [$query, 'createPositionalParameter'],
                array_filter(
                    $this->toArray($object),
                    function ($value) {
                        return $value !== null;
                    }
                )
            )
        )->query();

        $this->setFieldValue($primaryKey, $this->getPrimaryKey(), $object);

        return $primaryKey;
    }

    private function update($object)
    {
        $data = array_diff(
            $this->toArray($object),
            $this->originalData[spl_object_hash($object)]
        );

        //only save when a change is detected
        if (!empty($data)) {
            $queryBuilder = $this->manager->getDriver()->getQueryBuilder();
            $query        = $queryBuilder
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

            $joinTable = $this->getTable() . '_' . $relatedEntity->getTable();
            $leftKey   = $this->getTable() . '_' . $relation->foreignKey;
            $rightKey  = $relatedEntity->getTable() . '_' . $relation->targetKey;

            if (!empty($keys['deleted'])) {
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
            if (!empty($keys['inserted'])) {
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

    public function setReadOnly($object, $readOnly = true)
    {
        $this->checkObjectInstance($object);

        $objectId = spl_object_hash($object);
        if ($readOnly) {
            $this->readOnlyObjectHandles[$objectId] = true;
        } elseif (isset($this->readOnlyObjectHandles[$objectId])) {
            unset($this->readOnlyObjectHandles[$objectId]);
        }
    }
}
