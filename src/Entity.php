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
    /**
     * @var EntityMetadata
     */
    private $metadata;

    /**
     * @var EntityManager
     */
    private $manager;

    /**
     * @var EntityState[]
     */
    private $entityStates = [];

    public function __construct(EntityManager $manager, EntityMetadata $metadata)
    {
        $this->manager  = $manager;
        $this->metadata = $metadata;
    }

    //Metadata related methods

    /**
     * @param $object
     *
     * @return EntityState
     */
    private function getState($object)
    {
        $objectId = spl_object_hash($object);
        if (!isset($this->entityStates[ $objectId ])) {
            $this->handle($object);
        }

        return $this->entityStates[ $objectId ];
    }

    /**
     * Return the metadata of the current entity
     *
     * @return EntityMetadata
     */
    public function getMetadata()
    {
        return $this->metadata;
    }

    /**
     * Checks if the primary key is set for the object
     *
     * @param $object
     *
     * @return bool
     */
    public function isPrimaryKeySet($object)
    {
        return $this->getPrimaryKeyValue($object) !== null;
    }

    /**
     * Returns the primary key value
     *
     * @param $object
     *
     * @return mixed
     */
    public function getPrimaryKeyValue($object)
    {
        return $this->getFieldValue($object, $this->metadata->getPrimaryKey());
    }

    /**
     * @param $object
     * @param $field
     * @param $value
     *
     * @return mixed
     */
    public function setFieldValue($object, $field, $value)
    {
        $this->metadata->assertObjectInstance($object);

        return $this->metadata->getField($field)->set($object, $value);
    }

    /**
     * @param $object
     * @param $field
     *
     * @return mixed
     */
    public function getFieldValue($object, $field)
    {
        $this->metadata->assertObjectInstance($object);

        return $this->metadata->getField($field)->get($object);
    }

    public function getRelationValue($object, $relationName)
    {
        $this->metadata->assertObjectInstance($object);

        return $this->metadata->getRelation($relationName)->getValue($object);
    }

    public function setRelationValue($object, $relationName, $value)
    {
        $this->metadata->assertObjectInstance($object);

        $relation      = $this->metadata->getRelation($relationName);
        $relatedEntity = $this->manager->get($relation->target);

        $state = $this->getState($object);

        switch ($relation->type) {
            case Relation::MANY_MANY:
                $targetKeyField = $relatedEntity->metadata->getField($relation->targetKey);
                $state->setRelationData(
                    $relationName,
                    array_map(
                        [$targetKeyField, 'get'],
                        $value
                    )
                );
                break;

            case Relation::HAS_MANY:
                $state->setRelationData(
                    $relationName,
                    array_map(
                        [$relatedEntity, 'getPrimaryKeyValue'],
                        (array)$value
                    )
                );
                break;

            default:
                if (is_array($value)) {
                    $value = current($value);
                }
                $state->setRelationData(
                    $relationName,
                    $relatedEntity->getPrimaryKeyValue(
                        $value
                    )
                );
                break;
        }

        $this->getState($object)->setRelationLoaded($relationName);

        return $relation->setValue($object, $value);
    }

    //Entity API
    public function toArray($object)
    {
        return $this->metadata->toArray($object);
    }

    public function create(array $data = [])
    {
        $object = $this->metadata->create($data);

        return $this->handle($object, false);
    }

    public function handle($object, $fromDatabase = true)
    {
        $objectId = spl_object_hash($object);
        if (!isset($this->entityStates[ $objectId ])) {
            $this->entityStates[ $objectId ] = new EntityState($object, $this->metadata, $fromDatabase);
        }

        return $object;
    }

    /**
     * @param string $alias Alias to use for the table name in the query
     *
     * @return EntityFinder
     */
    public function find($alias = null)
    {
        $finder = new EntityFinder($this->manager, $this->manager->getDriver(), $this->metadata);
        if ($alias !== null) {
            $finder->alias($alias);
        }

        return $finder;
    }

    /**
     * @return Expression
     */
    public function expression()
    {
        return $this->manager->getDriver()->getQueryBuilder()->expression();
    }

    /**
     * @param $primaryKey
     * @param ... other primary keys
     *
     * @return array|mixed
     */
    public function get($primaryKey)
    {
        if (func_num_args() > 1) {
            $primaryKey = func_get_args();
        }

        return $this->find()->getByPrimaryKey($primaryKey);
    }

    public function exists($primaryKey)
    {
        return $this->find()->existsByPrimaryKey($primaryKey);
    }

    public function delete($object)
    {
        $queryBuilder = $this->manager->getDriver()->getQueryBuilder();
        $table        = $this->metadata->getTable();

        foreach ($this->metadata->getRelations() as $relation) {
            $foreignKey = $this->metadata->getField($relation->foreignKey)->get($object);
            switch ($relation->type) {
                case Relation::HAS_ONE:
                case Relation::HAS_MANY:
                    $this->manager
                        ->find($relation->target)
                        ->deleteByField($relation->targetKey, $foreignKey);
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
                $queryBuilder
                    ->delete($table)
                    ->where(
                        $queryBuilder->expression()->eq(
                            $this->metadata->getPrimaryKey(),
                            $queryBuilder->createPositionalParameter(
                                $this->getPrimaryKeyValue($object)
                            )
                        )
                    )
            );
            unset($this->entityStates[ spl_object_hash($object) ]);
        }
    }

    /**
     * Save multiple objects into database
     *
     * @param array $objects
     *
     * @return array The ids of the given objects
     *
     * @throws \PDOException if the transaction fails
     */
    public function saveMultiple(array $objects)
    {
        array_walk($objects, [$this->metadata, 'assertObjectInstance']);

        return $this->manager
            ->getDriver()
            ->inTransaction(
                function (Driver $driver, array $objects) {
                    return array_map([$this, 'save'], $objects);
                },
                $objects
            );
    }

    /**
     * Save an object to the database
     *
     * @todo needs to be refactored
     *
     * @param $object
     *
     * @return int|null
     */
    public function save($object)
    {
        $this->metadata->assertObjectInstance($object);

        $state = $this->getState($object);
        if ($state->isReadOnly()) {
            return null;
        }

        $relationsIterator = new \ArrayIterator($this->metadata->getRelations());

        $modifiedManyManyRelations = $this->handleManyManyRelations($state, $relationsIterator);
        $this->handleHasManyRelations($state, $relationsIterator);
        $this->handleHasOneRelations($state, $relationsIterator);

        switch ($state->getObjectState()) {
            case EntityState::STATE_NEW:
                $primaryKey = $this->insert($object);
                break;

            case EntityState::STATE_NEW_WITH_PRIMARY_KEY:
                $pkField    = $this->metadata->getPrimaryKey();
                $primaryKey = $state->getOriginalFieldData($pkField);

                //Check if the record exists in the database
                if ($this->exists($primaryKey)) {
                    //We may have no way of knowing what has changed, but assume that the primary key hasn't
                    //Let's assume that the original data is actually all new
                    $data = $this->toArray($object);

                    //But only update the primary key if it really has changed
                    if ($data[ $pkField ] === $state->getOriginalFieldData($pkField)) {
                        unset($data[ $pkField ]);
                    }

                    $primaryKey = $this->update($object, $data);
                } else {
                    $primaryKey = $this->insert($object);
                }
                break;

            default:
                $data       = $state->getChangedFields();
                $primaryKey = $this->update($object, $data);
                break;
        }

        $state->setState(EntityState::STATE_HANDLED);
        $state->refreshOriginalData();

        $this->updateManyToManyRelations($modifiedManyManyRelations, $primaryKey);

        return $primaryKey;
    }

    private function insert($object)
    {
        //TODO: this should only be done if there is a Delete query pending for the same entity table
        $this->manager->commit();

        $query = $this->manager
            ->getDriver()
            ->getQueryBuilder()
            ->insert($this->metadata->getTable());

        $query->values(
            $query->createPositionalParameter(
                array_filter(
                    $this->toArray($object),
                    '\\ORMiny\\Utils::notNull'
                )
            )
        );

        $primaryKey = $query->query();

        $this->setFieldValue($object, $this->metadata->getPrimaryKey(), $primaryKey);

        return $primaryKey;
    }

    private function update($object, array $data)
    {
        //only save when a change is detected
        if (!empty($data)) {
            $queryBuilder = $this->manager->getDriver()->getQueryBuilder();
            $primaryKey   = $this->metadata->getPrimaryKey();

            $this->manager->postPendingQuery(
                $queryBuilder
                    ->update($this->metadata->getTable())
                    ->values($queryBuilder->createPositionalParameter($data))
                    ->where(
                        $queryBuilder->expression()->eq(
                            $primaryKey,
                            $queryBuilder->createPositionalParameter(
                                $this->getState($object)->getOriginalFieldData($primaryKey)
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
                                $expression->eq(
                                    $rightKey,
                                    $queryBuilder->createPositionalParameter($keys['deleted'])
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
        $this->getState($object)->setReadOnly($readOnly);
    }

    public function loadRelation($object, $relationName, array $with = null)
    {
        $this->metadata->assertObjectInstance($object);

        $relation = $this->metadata->getRelation($relationName);

        $entityFinder = $this->manager
            ->get($relation->target)
            ->find();
        if ($with !== null) {
            $entityFinder->with($with);
        }
        $relation->setValue(
            $object,
            $entityFinder
                ->getByField(
                    $relation->targetKey,
                    $this->metadata->getField($relation->foreignKey)->get($object)
                )
        );
    }

    /**
     * @param EntityState $state
     * @param             $relationsIterator
     *
     * @return array
     */
    private function handleManyManyRelations(EntityState $state, $relationsIterator)
    {
        $object = $state->getObject();

        $manyManyRelations = new \CallbackFilterIterator(
            $relationsIterator,
            function (Relation $relation) {
                return $relation->type === Relation::MANY_MANY;
            }
        );

        $modifiedManyManyRelations = [];

        foreach ($manyManyRelations as $relationName => $relation) {
            $relatedEntity = $this->manager->get($relation->target);

            $currentForeignKeys = array_map(
                function ($object) use ($relatedEntity) {
                    //TODO should be disallowed? set the foreign key property instead?
                    if (!is_object($object)) {
                        //the foreign key is set directly
                        return $object;
                    }
                    $relatedEntity->save($object);

                    return $relatedEntity->getPrimaryKeyValue($object);
                },
                $relation->getValue($object)
            );

            $originalForeignKeys = $state->getRelationData($relationName);

            //TODO it is possible that a relation is not loaded but the data contains its keys and/or related objects
            //in this case the records may already be in the database - should not insert them blindly
            $modifiedManyManyRelations[ $relationName ] = [
                'deleted'  => array_diff($originalForeignKeys, $currentForeignKeys),
                'inserted' => array_diff($currentForeignKeys, $originalForeignKeys)
            ];

            $state->setRelationData($relationName, $currentForeignKeys);
        }

        return $modifiedManyManyRelations;
    }

    /**
     * @param EntityState $state
     * @param             $relationsIterator
     *
     * @return array
     */
    private function handleHasManyRelations(EntityState $state, $relationsIterator)
    {
        $object = $state->getObject();

        $hasManyRelations = new \CallbackFilterIterator(
            $relationsIterator,
            function (Relation $relation) {
                return $relation->type === Relation::HAS_MANY;
            }
        );

        //TODO: has many type relations can only be saved when the object's primary key is known
        foreach ($hasManyRelations as $relationName => $relation) {
            $relatedEntity      = $this->manager->get($relation->target);
            $currentForeignKeys = [];
            foreach ($relation->getValue($object) as $relatedObject) {
                //record the current primary key
                $currentForeignKeys[] = $relatedEntity->getState($relatedObject)->getOriginalFieldData(
                    $relatedEntity->metadata->getPrimaryKey()
                );

                //update the foreign key to match the current object's
                $relatedEntity->metadata->getField($relation->targetKey)->set(
                    $relatedObject,
                    $this->metadata->getField($relation->foreignKey)->get($object)
                );
                $relatedEntity->save($relatedObject);
            }

            $deleted = array_diff(
                $state->getRelationData($relationName),
                $currentForeignKeys
            );
            if (!empty($deleted)) {
                //TODO there may be cases when it is sensible for a record to not belong to something else
                //in this case only the foreign key should be deleted
                //This may be deduced from whether the related record has a 'has one' or a 'belongs to' type relation
                $relatedEntity->find()->deleteByPrimaryKey($deleted);
            }
            $state->setRelationData($relationName, $currentForeignKeys);
        }
    }

    /**
     * @param EntityState $state
     * @param             $relationsIterator
     */
    private function handleHasOneRelations(EntityState $state, $relationsIterator)
    {
        $object = $state->getObject();

        $hasOneRelations = new \CallbackFilterIterator(
            $relationsIterator,
            function (Relation $relation) {
                return $relation->isSingle();
            }
        );

        foreach ($hasOneRelations as $relationName => $relation) {
            $relatedEntity = $this->manager->get($relation->target);
            //checking the foreign key is not enough here - foreign key is not updated yet.
            $relatedObject = $relation->getValue($object);

            $foreignKeyField    = $this->metadata->getField($relation->foreignKey);
            $originalForeignKey = $state->getOriginalFieldData($relation->foreignKey);

            if ($relatedObject !== null) {
                //Related object has been set
                $currentForeignKey = $relatedEntity->metadata->getField($relation->targetKey)->get($relatedObject);
            } else if ($state->isRelationLoaded($relationName)) {
                if ($originalForeignKey === null) {
                    //Use the directly set foreign key
                    $currentForeignKey = $foreignKeyField->get($object);
                } else {
                    //Related object has been unset
                    $relatedEntity
                        ->find()
                        ->delete($originalForeignKey);
                    $currentForeignKey = null;
                }
            } else {
                continue;
            }

            //TODO: there are cases when this condition is not sufficient
            //e.g. a row should be saved because of a to-be-executed pending query changes a foreign key
            if ($currentForeignKey !== $originalForeignKey) {
                $foreignKeyField->set($object, $currentForeignKey);
                $state->setRelationData($relationName, $currentForeignKey);

                if ($currentForeignKey !== null) {
                    $relatedEntity->save(
                        $relation->getValue($object)
                    );
                }
            }
        }
    }
}
