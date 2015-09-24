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
use ORMiny\Metadata\Field;
use ORMiny\Metadata\Getter;
use ORMiny\Metadata\Relation;
use ORMiny\Metadata\Setter;

class Entity
{
    /**
     * @var EntityManager
     */
    private $manager;

    /**
     * @var \SplObjectStorage
     */
    private $entityStates;

    /**
     * @var string
     */
    private $className;

    /**
     * @var string
     */
    private $tableName;

    private $primaryKey;
    private $fieldNames = [];
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

    public function __construct(EntityManager $manager, $className)
    {
        $this->manager      = $manager;
        $this->className    = $className;
        $this->entityStates = new \SplObjectStorage();
    }

    //Metadata related methods
    /**
     * @param $object
     *
     * @throws \InvalidArgumentException
     */
    public function assertObjectInstance($object)
    {
        if (!$object instanceof $this->className) {
            if (is_object($object)) {
                $className = get_class($object);
            } else {
                $className = gettype($object);
            }
            throw new \InvalidArgumentException("Object must be an instance of {$this->className}, {$className} given");
        }
    }

    public function create(array $data = [], $fromDatabase = false)
    {
        $className = $this->getClassName();
        $object    = new $className;
        foreach ($data as $key => $value) {
            $this->fields[ $key ]->set($object, $value);
        }

        $this->createState($object, $fromDatabase);

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

    public function addField($fieldName, Field $field)
    {
        $this->fieldNames[ $fieldName ] = $fieldName;
        $this->fields[ $fieldName ]     = $field;

        return $fieldName;
    }

    public function addRelation($relationName, $foreignKey, Relation $relation)
    {
        $this->relationNames[]                      = $relationName;
        $this->relations[ $relationName ]           = $relation;
        $this->relationsByForeignKey[ $foreignKey ] = $relation;
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
     * @return Relation[]
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
     * @param $object
     *
     * @return EntityState
     */
    private function getState($object)
    {
        if (!$this->entityStates->contains($object)) {
            $this->createState($object, false);
        }

        return $this->entityStates[ $object ];
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
        return $this->getFieldValue($object, $this->getPrimaryKey());
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
        $this->assertObjectInstance($object);

        return $this->getField($field)->set($object, $value);
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

        return $this->getField($field)->get($object);
    }

    public function getRelationValue($object, $relationName)
    {
        $this->assertObjectInstance($object);

        return $this->getRelation($relationName)->get($object);
    }

    public function setRelationValue($object, $relationName, $value)
    {
        $this->assertObjectInstance($object);

        $relation = $this->getRelation($relationName);

        $relationData = $relation->getForeignKeyValue($value);
        $state        = $this->getState($object);
        $state->setRelationForeignKeys($relationName, $relationData);
        $state->setRelationLoaded($relationName);

        return $relation->set($object, $value);
    }

    //Entity API

    public function handle($object, $fromDatabase = true)
    {
        if (!$this->entityStates->contains($object)) {
            $this->createState($object, $fromDatabase);
        }

        return $object;
    }

    /**
     * @param $object
     * @param $fromDatabase
     */
    private function createState($object, $fromDatabase)
    {
        $this->entityStates[ $object ] = new EntityState($object, $this, $fromDatabase);
    }

    /**
     * @param string $alias Alias to use for the table name in the query
     *
     * @return EntityFinder
     */
    public function find($alias = null)
    {
        $finder = new EntityFinder($this->manager, $this->manager->getDriver(), $this);
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
        $table        = $this->getTable();

        foreach ($this->getRelations() as $relation) {
            $relation->delete($this->manager, $object);
        }

        if ($this->isPrimaryKeySet($object)) {
            $this->manager->postPendingQuery(
                new PendingQuery(
                    $this,
                    PendingQuery::TYPE_DELETE,
                    $queryBuilder
                        ->delete($table)
                        ->where(
                            $queryBuilder->expression()->eq(
                                $this->getPrimaryKey(),
                                $queryBuilder->createPositionalParameter(
                                    $this->getPrimaryKeyValue($object)
                                )
                            )
                        )
                )
            );
            unset($this->entityStates[ $object ]);
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
        array_walk($objects, [$this, 'assertObjectInstance']);

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
        $this->assertObjectInstance($object);

        $state = $this->getState($object);
        if ($state->isReadOnly()) {
            return null;
        }

        $relationsIterator = new \ArrayIterator($this->getRelations());

        $modifiedManyManyRelations = $this->handleManyManyRelations($state, $relationsIterator);
        $this->handleHasManyRelations($state, $relationsIterator);
        $this->handleHasOneRelations($state, $relationsIterator);

        switch ($state->getObjectState()) {
            case EntityState::STATE_NEW:
                $primaryKey = $this->insert($object);
                break;

            case EntityState::STATE_NEW_WITH_PRIMARY_KEY:
                $pkField    = $this->getPrimaryKey();
                $primaryKey = $state->getOriginalFieldData($pkField);

                //Check if the record exists in the database
                if ($this->exists($primaryKey)) {
                    //We may have no way of knowing what has changed, but assume that the primary key hasn't
                    //Let's assume that the original data is actually all new
                    $data = $this->toArray($object);

                    //But only update the primary key if it really has changed
                    if ($data[ $pkField ] === $primaryKey) {
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
            ->insert($this->getTable());

        $query->values(
            $query->createPositionalParameter(
                array_filter(
                    $this->toArray($object),
                    '\\ORMiny\\Utils::notNull'
                )
            )
        );

        return $this->setFieldValue(
            $object,
            $this->getPrimaryKey(),
            $query->query()
        );
    }

    private function update($object, array $data)
    {
        //only save when a change is detected
        if (!empty($data)) {
            $queryBuilder = $this->manager->getDriver()->getQueryBuilder();
            $primaryKey   = $this->getPrimaryKey();

            $this->manager->postPendingQuery(
                new PendingQuery(
                    $this,
                    PendingQuery::TYPE_UPDATE,
                    $queryBuilder
                        ->update($this->getTable())
                        ->values($queryBuilder->createPositionalParameter($data))
                        ->where(
                            $queryBuilder->expression()->eq(
                                $primaryKey,
                                $queryBuilder->createPositionalParameter(
                                    $this->getState($object)->getOriginalFieldData($primaryKey)
                                )
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
        $tableName    = $this->getTable();

        foreach ($modifiedManyManyRelations as $relationName => $keys) {
            /** @var Relation\ManyToMany $relation */
            $relation         = $this->getRelation($relationName);
            $joinTable        = $relation->getJoinTable();
            $relatedTableName = $relation->getEntity()->getTable();

            $leftKey  = $tableName . '_' . $relation->getForeignKey();
            $rightKey = $relatedTableName . '_' . $relation->getTargetKey();

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
                                $expression->eq(
                                    $rightKey,
                                    $queryBuilder->createPositionalParameter($keys['deleted'])
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
        $this->assertObjectInstance($object);

        $this->getState($object)->setReadOnly($readOnly);
    }

    public function loadRelation($object, $relationName, array $with = null)
    {
        $this->assertObjectInstance($object);

        $relation = $this->getRelation($relationName);

        $entityFinder = $relation->getEntity()->find();
        if ($with !== null) {
            $entityFinder->with($with);
        }
        $relationData = $entityFinder
            ->getByField(
                $relation->getTargetKey(),
                $this->getField($relation->getForeignKey())->get($object)
            );

        $this->setRelationValue($object, $relationName, $relationData);
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
                return $relation instanceof Relation\ManyToMany;
            }
        );

        $modifiedManyManyRelations = [];

        /** @var Relation\ManyToMany $relation */
        foreach ($manyManyRelations as $relationName => $relation) {
            $relatedEntity = $relation->getEntity();

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
                $relation->get($object)
            );

            $originalForeignKeys = $state->getRelationForeignKeys($relationName);

            //TODO it is possible that a relation is not loaded but the data contains its keys and/or related objects
            //in this case the records may already be in the database - should not insert them blindly
            $modifiedManyManyRelations[ $relationName ] = [
                'deleted'  => array_diff($originalForeignKeys, $currentForeignKeys),
                'inserted' => array_diff($currentForeignKeys, $originalForeignKeys)
            ];

            $state->setRelationForeignKeys($relationName, $currentForeignKeys);
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
                return $relation instanceof Relation\HasMany;
            }
        );

        //TODO: has many type relations can only be saved when the object's primary key is known
        /** @var Relation $relation */
        foreach ($hasManyRelations as $relationName => $relation) {
            $relatedEntity           = $relation->getEntity();
            $relatedEntityPrimaryKey = $relatedEntity->getPrimaryKey();

            $foreignKeyField = $this->getField($relation->getForeignKey());
            $targetField     = $relatedEntity->getField($relation->getTargetKey());
            $foreignKeyValue = $foreignKeyField->get($object);

            $currentForeignKeys = [];
            foreach ($relation->get($object) as $relatedObject) {
                //record the current primary key
                $currentForeignKeys[] = $relatedEntity->getState($relatedObject)
                                                      ->getOriginalFieldData($relatedEntityPrimaryKey);

                //update the foreign key to match the current object's
                $targetField->set($relatedObject, $foreignKeyValue);
                $relatedEntity->save($relatedObject);
            }

            $deleted = array_diff(
                $state->getRelationForeignKeys($relationName),
                $currentForeignKeys
            );
            if (!empty($deleted)) {
                //TODO there may be cases when it is sensible for a record to not belong to something else
                //in this case only the foreign key should be deleted
                //This may be deduced from whether the related record has a 'has one' or a 'belongs to' type relation
                $relatedEntity->find()->deleteByPrimaryKey($deleted);
            }
            $state->setRelationForeignKeys($relationName, $currentForeignKeys);
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

        /** @var Relation $relation */
        foreach ($hasOneRelations as $relationName => $relation) {
            $relatedEntity = $relation->getEntity();
            //checking the foreign key is not enough here - foreign key is not updated yet.
            $relatedObject = $relation->get($object);
            $foreignKey    = $relation->getForeignKey();

            $foreignKeyField    = $this->getField($foreignKey);
            $originalForeignKey = $state->getOriginalFieldData($foreignKey);

            if ($relatedObject !== null) {
                //Related object is set
                $targetKeyField    = $relatedEntity->getField($relation->getTargetKey());
                $currentForeignKey = $targetKeyField->get($relatedObject);
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
                //The related object has changed, update the foreign key
                $foreignKeyField->set($object, $currentForeignKey);
                $state->setRelationForeignKeys($relationName, $currentForeignKey);

                if ($relatedObject !== null) {
                    $relatedEntity->save($relatedObject);
                }
            }
        }
    }
}
