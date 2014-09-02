<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

use Modules\DBAL\QueryBuilder;
use Modules\DBAL\QueryBuilder\Expression;
use ORMiny\Annotations\Relation;

class Entity
{
    const STATE_NEW     = 1;
    const STATE_HANDLED = 2;

    private $metadata;
    private $manager;

    private $originalData = [];
    private $objectStates = [];
    private $objectRelations = [];

    private $relatedObjectHandles = [];
    private $readOnlyObjectHandles = [];

    public function __construct(EntityManager $manager, EntityMetadata $metadata)
    {
        $this->manager  = $manager;
        $this->metadata = $metadata;
    }

    //Metadata related methods
    public function getMetadata()
    {
        return $this->metadata;
    }

    public function isPrimaryKeySet($object)
    {
        return $this->getPrimaryKeyValue($object) !== null;
    }

    public function getPrimaryKeyValue($object)
    {
        return $this->getFieldValue($object, $this->metadata->getPrimaryKey());
    }

    public function setFieldValue($value, $field, $object)
    {
        $this->metadata->setFieldValue($value, $field, $object);
    }

    public function getFieldValue($object, $field)
    {
        return $this->metadata->getFieldValue($object, $field);
    }

    public function getRelationValue($object, $relationName)
    {
        return $this->metadata->getRelationValue($object, $relationName);
    }

    public function setRelationValue($object, $relationName, $value)
    {
        $this->metadata->assertObjectInstance($object);

        $relation      = $this->metadata->getRelation($relationName);
        $relatedEntity = $this->manager->get($relation->target);

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

        return $this->metadata->setRelationValue($object, $relationName, $value);
    }

    //Entity API
    public function create(array $data = [])
    {
        $className = $this->metadata->getClassName();
        $object    = new $className;
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
        $this->metadata->assertObjectInstance($object);
        $data = [];
        foreach ($this->metadata->getFields() as $field) {
            $data[$field] = $this->getFieldValue($object, $field);
        }

        return $data;
    }

    public function find()
    {
        return new EntityFinder(
            $this->manager,
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
        return $this->find()->getByPrimaryKey(func_get_args());
    }

    public function delete($object)
    {
        $this->metadata->assertObjectInstance($object);
        $queryBuilder = $this->manager->getDriver()->getQueryBuilder();

        foreach ($this->metadata->getRelations() as $relation) {
            $relatedEntity = $this->manager->get($relation->target);
            $foreignKey    = $this->getFieldValue($object, $relation->foreignKey);
            switch ($relation->type) {
                case Relation::HAS_ONE:
                case Relation::HAS_MANY:
                    $relatedEntity->find()->deleteByField($relation->targetKey, $foreignKey);
                    break;

                case Relation::MANY_MANY:
                    $queryBuilder
                        ->delete($relation->joinTable)
                        ->where(
                            $queryBuilder->expression()->eq(
                                $this->metadata->getTable() . '_' . $relation->foreignKey,
                                $queryBuilder->createPositionalParameter($foreignKey)
                            )
                        )->query();
                    break;

                case Relation::BELONGS_TO:
                    //don't delete the record this one belongs to
                    break;
            }
        }

        if ($this->isPrimaryKeySet($object)) {
            $queryBuilder->delete($this->metadata->getTable())
                ->where(
                    $queryBuilder->expression()->eq(
                        $this->metadata->getPrimaryKey(),
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
        $this->metadata->assertObjectInstance($object);
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
        foreach ($this->metadata->getRelations() as $relationName => $relation) {
            $relatedEntity = $this->manager->get($relation->target);
            switch ($relation->type) {
                case Relation::MANY_MANY:
                    $relatedObjects = $this->getRelationValue($object, $relationName);
                    if ($relatedObjects === null) {
                        $relatedObjects = [];
                    }

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
                        //record the current primary key
                        $currentForeignKeys[] = $relatedEntity->getOriginalData(
                            $relatedObject,
                            $relatedEntity->metadata->getPrimaryKey()
                        );
                        //update the foreign key to match the current object's
                        $relatedEntity->setFieldValue(
                            $this->getFieldValue($object, $relation->foreignKey),
                            $relation->targetKey,
                            $relatedObject
                        );
                        $relatedEntity->save($relatedObject);
                    }

                    $deleted = array_diff($this->objectRelations[$objectId], $currentForeignKeys);
                    $relatedEntity->find()->deleteByPrimaryKey($deleted);
                    $this->objectRelations[$objectId] = array_diff($deleted, $currentForeignKeys);
                    break;

                case Relation::HAS_ONE:
                case Relation::BELONGS_TO:
                    //checking the foreign key is not enough here - foreign key is not updated yet.
                    $foreignKeyValue = $this->metadata->getRelationValue($object, $relationName);

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

        foreach ($this->metadata->getRelations() as $relationName => $relation) {
            switch ($relation->type) {
                case Relation::HAS_ONE:
                case Relation::BELONGS_TO:
                    $relatedEntity = $this->manager->get($relation->target);
                    if ($this->getFieldValue($object, $relation->foreignKey) !== null) {
                        $relatedEntity->save(
                            $this->getRelationValue($object, $relationName)
                        );
                    } else {
                        $relatedEntity
                            ->find()
                            ->delete(
                                $this->getOriginalData($object, $relation->foreignKey)
                            );
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
        $this->metadata->assertObjectInstance($object);

        return $this->originalData[spl_object_hash($object)][$field];
    }

    private function insert($object)
    {
        $queryBuilder = $this->manager->getDriver()->getQueryBuilder();
        $query        = $queryBuilder->insert($this->metadata->getTable());

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

        $this->setFieldValue($primaryKey, $this->metadata->getPrimaryKey(), $object);

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

            $queryBuilder
                ->update($this->metadata->getTable())
                ->values(array_map([$queryBuilder, 'createPositionalParameter'], $data))
                ->where(
                    $queryBuilder->expression()->eq(
                        $this->metadata->getPrimaryKey(),
                        $queryBuilder->createPositionalParameter(
                            $this->getOriginalData($object, $this->metadata->getPrimaryKey())
                        )
                    )
                )->query();
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
            $relation      = $this->metadata->getRelation($relationName);
            $relatedEntity = $this->manager->get($relation->target);

            $leftKey  = $this->metadata->getTable() . '_' . $relation->foreignKey;
            $rightKey = $relatedEntity->metadata->getTable() . '_' . $relation->targetKey;

            if (!empty($keys['deleted'])) {
                $expression = $queryBuilder->expression();
                $queryBuilder
                    ->delete($relation->joinTable)
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
                    ->insert($relation->joinTable)
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
        $this->metadata->assertObjectInstance($object);

        $objectId = spl_object_hash($object);
        if ($readOnly) {
            $this->readOnlyObjectHandles[$objectId] = true;
        } elseif (isset($this->readOnlyObjectHandles[$objectId])) {
            unset($this->readOnlyObjectHandles[$objectId]);
        }
    }

    public function loadRelation($object, $relationName)
    {
        $this->metadata->assertObjectInstance($object);
        $relation      = $this->metadata->getRelation($relationName);
        $relatedEntity = $this->manager->get($relation->target);

        $targetField = $relation->targetKey;
        $foreignKey  = $this->getFieldValue($object, $relation->foreignKey);
        $this->setRelationValue(
            $object,
            $relationName,
            $relatedEntity->find()->getByField($targetField, $foreignKey)
        );
    }
}
