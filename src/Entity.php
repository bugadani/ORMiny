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

    /**
     * @var EntityMetadata
     */
    private $metadata;

    /**
     * @var EntityManager
     */
    private $manager;

    private $originalData    = [];
    private $objectStates    = [];
    private $objectRelations = [];

    private $objectHandles         = [];
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
        return $this->metadata->getFieldValue($object, $this->metadata->getPrimaryKey());
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

        $this->objectHandles[$objectId] = $object;

        switch ($relation->type) {
            case Relation::MANY_MANY:
                $targetKey = $relation->targetKey;

                $this->objectRelations[$objectId][$relationName] = [];
                foreach ($value as $relatedObject) {
                    $this->objectRelations[$objectId][$relationName][] = $relatedEntity->metadata->getFieldValue(
                        $relatedObject,
                        $targetKey
                    );
                }
                break;

            case Relation::HAS_MANY:
                $this->objectRelations[$objectId][$relationName] = array_map(
                    [$relatedEntity, 'getPrimaryKeyValue'],
                    (array)$value
                );
                break;

            default:
                $this->objectRelations[$objectId][$relationName] = $relatedEntity->getPrimaryKeyValue(
                    $value
                );
                break;
        }

        return $this->metadata->setRelationValue($object, $relationName, $value);
    }

    //Entity API
    public function toArray($object)
    {
        $this->metadata->assertObjectInstance($object);

        return array_map(
            function ($field) use ($object) {
                return $this->metadata->getFieldValue($object, $field);
            },
            $this->metadata->getFields()
        );
    }

    /**
     * @return array
     */
    private function createEmptyRelationsArray()
    {
        return array_map(
            function (Relation $relation) {
                return $relation->isSingle() ? null : [];
            },
            $this->metadata->getRelations()
        );
    }

    public function create(array $data = [], $forceNew = false)
    {
        $object   = $this->metadata->create($data);
        $objectId = spl_object_hash($object);

        if ($this->isPrimaryKeySet($object) && !$forceNew) {
            $this->objectStates[$objectId] = self::STATE_HANDLED;
        } else {
            $this->objectStates[$objectId] = self::STATE_NEW;
        }

        $this->originalData[$objectId]    = $data;
        $this->objectHandles[$objectId]   = $object;
        $this->objectRelations[$objectId] = $this->createEmptyRelationsArray($objectId);

        return $object;
    }

    public function find()
    {
        return new EntityFinder($this->manager, $this->manager->getDriver(), $this->metadata);
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

    public function exists($primaryKey)
    {
        return $this->find()->existsByPrimaryKey(func_get_args());
    }

    public function delete($object)
    {
        $queryBuilder = $this->manager->getDriver()->getQueryBuilder();

        $table = $this->metadata->getTable();
        foreach ($this->metadata->getRelations() as $relation) {
            $foreignKey    = $this->metadata->getFieldValue($object, $relation->foreignKey);
            $relatedEntity = $this->manager->get($relation->target);
            switch ($relation->type) {
                case Relation::HAS_ONE:
                case Relation::HAS_MANY:
                    $relatedEntity->find()->deleteByField($relation->targetKey, $foreignKey);
                    break;

                case Relation::MANY_MANY:
                    $this->manager->postPendingQuery(
                        $queryBuilder
                            ->delete($relation->joinTable)
                            ->where(
                                $queryBuilder->expression()->eq(
                                    $table . '_' . $relation->foreignKey,
                                    $queryBuilder->createPositionalParameter($foreignKey)
                                )
                            )
                    );
                    break;

                case Relation::BELONGS_TO:
                    //don't delete the record this one belongs to
                    break;
            }
        }

        if ($this->isPrimaryKeySet($object)) {
            $this->manager->postPendingQuery(
                $queryBuilder->delete($table)
                    ->where(
                        $queryBuilder->expression()->eq(
                            $this->metadata->getPrimaryKey(),
                            $queryBuilder->createPositionalParameter(
                                $this->getPrimaryKeyValue($object)
                            )
                        )
                    )
            );
            unset($this->objectHandles[spl_object_hash($object)]);
        }
    }

    public function save($object)
    {
        $this->metadata->assertObjectInstance($object);
        $objectId = spl_object_hash($object);

        if (!isset($this->objectStates[$objectId]) || $this->objectHandles[$objectId] !== $object) {
            $this->objectStates[$objectId]    = self::STATE_NEW;
            $this->originalData[$objectId]    = [];
            $this->objectRelations[$objectId] = $this->createEmptyRelationsArray();
            $this->objectHandles[$objectId]   = $object;
        } elseif (isset($this->readOnlyObjectHandles[$objectId])) {
            return;
        }

        $relationsIterator = new \ArrayIterator($this->metadata->getRelations());

        $hasOneRelations   = new \CallbackFilterIterator(
            $relationsIterator,
            function (Relation $relation) {
                return $relation->isSingle();
            });
        $hasManyRelations  = new \CallbackFilterIterator(
            $relationsIterator,
            function (Relation $relation) {
                return $relation->type === Relation::HAS_MANY;
            });
        $manyManyRelations = new \CallbackFilterIterator(
            $relationsIterator,
            function (Relation $relation) {
                return $relation->type === Relation::MANY_MANY;
            });

        $modifiedManyManyRelations = [];
        foreach ($manyManyRelations as $relationName => $relation) {
            $relatedEntity  = $this->manager->get($relation->target);
            $relatedObjects = $this->metadata->getRelationValue($object, $relationName);

            $currentForeignKeys = array_map(
                function ($object) use ($relatedEntity) {
                    if (!is_object($object)) {
                        //the foreign key is set directly
                        return $object;
                    }
                    $relatedEntity->save($object);

                    return $relatedEntity->getPrimaryKeyValue($object);
                },
                $relatedObjects
            );

            $originalForeignKeys = $this->objectRelations[$objectId][$relationName];

            $modifiedManyManyRelations[$relationName] = [
                'deleted' => array_diff($originalForeignKeys, $currentForeignKeys),
                'inserted' => array_diff($currentForeignKeys, $originalForeignKeys)
            ];

            $this->objectRelations[$objectId][$relationName] = $currentForeignKeys;
        }

        foreach ($hasManyRelations as $relationName => $relation) {
            $relatedEntity      = $this->manager->get($relation->target);
            $currentForeignKeys = [];
            foreach ($this->metadata->getRelationValue($object, $relationName) as $relatedObject) {
                //record the current primary key
                $currentForeignKeys[] = $relatedEntity->getOriginalData(
                    $relatedObject,
                    $relatedEntity->metadata->getPrimaryKey()
                );

                //update the foreign key to match the current object's
                $relatedEntity->metadata->setFieldValue(
                    $this->metadata->getFieldValue($object, $relation->foreignKey),
                    $relation->targetKey,
                    $relatedObject
                );
                $relatedEntity->save($relatedObject);
            }

            $deleted = array_diff(
                $this->objectRelations[$objectId][$relationName],
                $currentForeignKeys
            );
            if (!empty($deleted)) {
                $relatedEntity->find()->deleteByPrimaryKey($deleted);
            }
            $this->objectRelations[$objectId][$relationName] = $currentForeignKeys;
        }

        foreach ($hasOneRelations as $relationName => $relation) {
            $relatedEntity = $this->manager->get($relation->target);
            //checking the foreign key is not enough here - foreign key is not updated yet.
            $relatedObject = $this->metadata->getRelationValue($object, $relationName);

            $originalForeignKey = $this->getOriginalData(
                $object,
                $relation->foreignKey
            );

            if ($relatedObject !== null) {
                //Related object has been set
                $currentForeignKey = $relatedEntity->metadata->getFieldValue(
                    $relatedObject,
                    $relation->targetKey
                );
            } else {
                if ($originalForeignKey === null) {
                    //Use the directly set foreign key
                    $currentForeignKey = $this->metadata->getFieldValue(
                        $object,
                        $relation->foreignKey
                    );
                } else {
                    //Related object has been unset
                    if ($originalForeignKey !== null) {
                        $relatedEntity
                            ->find()
                            ->delete($originalForeignKey);
                    }
                    $currentForeignKey = null;
                }
            }

            if ($currentForeignKey !== $originalForeignKey) {
                $this->metadata->setFieldValue(
                    $currentForeignKey,
                    $relation->foreignKey,
                    $object
                );
                $this->objectRelations[$objectId][$relationName] = $currentForeignKey;

                if ($currentForeignKey !== null) {
                    $relatedEntity->save(
                        $this->metadata->getRelationValue($object, $relationName)
                    );
                }
            }
        }

        if ($this->objectStates[$objectId] === self::STATE_NEW) {
            $primaryKey = $this->insert($object);
        } else {
            $primaryKey = $this->update($object);
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
        $objectId = spl_object_hash($object);
        if (isset($this->originalData[$objectId][$field])) {
            return $this->originalData[$objectId][$field];
        }

        return null;
    }

    private function insert($object)
    {
        $query = $this->manager
            ->getDriver()
            ->getQueryBuilder()
            ->insert($this->metadata->getTable());

        $query->values(
            array_map(
                [$query, 'createPositionalParameter'],
                array_filter(
                    $this->toArray($object),
                    function ($value) {
                        return $value !== null;
                    }
                )
            )
        );

        $primaryKey = $query->query();

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
            $primaryKey   = $this->metadata->getPrimaryKey();

            $this->manager->postPendingQuery(
                $queryBuilder
                    ->update($this->metadata->getTable())
                    ->values(array_map([$queryBuilder, 'createPositionalParameter'], $data))
                    ->where(
                        $queryBuilder->expression()->eq(
                            $primaryKey,
                            $queryBuilder->createPositionalParameter(
                                $this->getOriginalData($object, $primaryKey)
                            )
                        )
                    )
            );
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
        $tableName    = $this->metadata->getTable();

        foreach ($modifiedManyManyRelations as $relationName => $keys) {
            $relation         = $this->metadata->getRelation($relationName);
            $relatedTableName = $this->manager->get($relation->target)->metadata->getTable();

            $leftKey  = $tableName . '_' . $relation->foreignKey;
            $rightKey = $relatedTableName . '_' . $relation->targetKey;

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
                            $leftKey => $queryBuilder->createPositionalParameter($primaryKey),
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
        } else {
            unset($this->readOnlyObjectHandles[$objectId]);
        }
    }

    public function loadRelation($object, $relationName, array $with = null)
    {
        $relation = $this->metadata->getRelation($relationName);

        $entityFinder = $this->manager
            ->get($relation->target)
            ->find();
        if ($with !== null) {
            $entityFinder->with($with);
        }
        $this->setRelationValue(
            $object,
            $relationName,
            $entityFinder
                ->getByField(
                    $relation->targetKey,
                    $this->metadata->getFieldValue($object, $relation->foreignKey)
                )
        );
    }
}
