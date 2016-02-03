<?php

namespace ORMiny;

/**
 * Class EntityState
 *
 * @package ORMiny
 */
class EntityState
{
    const STATE_NEW                  = 1;
    const STATE_HANDLED              = 2;
    const STATE_NEW_WITH_PRIMARY_KEY = 3;

    /**
     * @var int
     */
    private $objectState;

    /**
     * @var array
     */
    private $originalData = [];

    /**
     * @var bool Whether the object is read-only
     */
    private $readOnly = false;

    /**
     * @var array The loaded relations
     */
    private $loadedRelations = [];

    /**
     * @var array Relation data
     */
    private $relationForeignKeys = [];

    /**
     * @var Entity metadata for the handled object
     */
    private $metadata;

    /**
     * @var object The handled object
     */
    private $object;

    /**
     * EntityState constructor.
     *
     * @param        $object
     * @param Entity $metadata
     * @param bool   $fromDatabase
     */
    public function __construct($object, Entity $metadata, $fromDatabase = false)
    {
        $isPrimaryKeySet = $metadata->getPrimaryKeyField()->get($object) !== null;
        if ($isPrimaryKeySet) {
            if ($fromDatabase) {
                $this->objectState = EntityState::STATE_HANDLED;
            } else {
                $this->objectState = EntityState::STATE_NEW_WITH_PRIMARY_KEY;
            }
        } else {
            $this->objectState = EntityState::STATE_NEW;
        }

        foreach ($metadata->getRelations() as $relationName => $relation) {
            $this->loadedRelations[ $relationName ]     = false;
            $this->relationForeignKeys[ $relationName ] = $relation->get($object);
        }
        $this->metadata = $metadata;
        $this->object   = $object;
        $this->refreshOriginalData();
    }

    /**
     * @return mixed
     */
    public function getObjectState()
    {
        return $this->objectState;
    }

    /**
     * @return boolean
     */
    public function isReadOnly()
    {
        return $this->readOnly;
    }

    /**
     * @param boolean $readOnly
     */
    public function setReadOnly($readOnly = true)
    {
        $this->readOnly = $readOnly;
    }

    /**
     * @param $relationName
     *
     * @return mixed
     */
    public function isRelationLoaded($relationName)
    {
        if (!isset($this->loadedRelations[ $relationName ])) {
            throw new \OutOfBoundsException("Unknown relation: {$relationName}");
        }

        return $this->loadedRelations[ $relationName ];
    }

    /**
     * @param      $relationName
     * @param bool $isLoaded
     */
    public function setRelationLoaded($relationName, $isLoaded = true)
    {
        if (!isset($this->loadedRelations[ $relationName ])) {
            throw new \OutOfBoundsException("Unknown relation: {$relationName}");
        }
        $this->loadedRelations[ $relationName ] = $isLoaded;
    }

    /**
     * @param $relationName
     *
     * @return mixed
     */
    public function getRelationForeignKeys($relationName)
    {
        if (!isset($this->relationForeignKeys[ $relationName ])) {
            throw new \OutOfBoundsException("Unknown relation: {$relationName}");
        }

        return $this->relationForeignKeys[ $relationName ];
    }

    /**
     * @param $relationName
     * @param $data
     */
    public function setRelationForeignKeys($relationName, $data)
    {
        $this->relationForeignKeys[ $relationName ] = $data;
    }

    public function refreshOriginalData()
    {
        $this->originalData = $this->metadata->toArray($this->object);
    }

    /**
     * @return array
     */
    public function getOriginalData()
    {
        return $this->originalData;
    }

    /**
     * @return array
     */
    public function getChangedFields()
    {
        return array_diff_assoc(
            $this->metadata->toArray($this->object),
            $this->originalData
        );
    }

    /**
     * @return object
     */
    public function getObject()
    {
        return $this->object;
    }

    /**
     * @param $state
     */
    public function setState($state)
    {
        $this->objectState = $state;
    }

    /**
     * @param $field
     *
     * @return null
     */
    public function getOriginalFieldData($field)
    {
        if (isset($this->originalData[ $field ])) {
            return $this->originalData[ $field ];
        }

        return null;
    }
}