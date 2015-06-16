<?php

namespace ORMiny;

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
    private $readOnly     = false;

    /**
     * @var array The loaded relations
     */
    private $relations    = [];

    /**
     * @var EntityMetadata metadata for the handled object
     */
    private $metadata;

    /**
     * @var object The handled object
     */
    private $object;

    public function __construct($object, EntityMetadata $metadata, $state = self::STATE_NEW)
    {
        $this->objectState = $state;
        foreach (array_keys($metadata->getRelations()) as $relationName) {
            $this->relations[$relationName] = false;
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

    public function isRelationLoaded($relationName)
    {
        if (!isset($this->relations[$relationName])) {
            throw new \OutOfBoundsException("Unknown relation: {$relationName}");
        }
        return $this->relations[$relationName];
    }

    public function setRelationLoaded($relationName, $isLoaded = true)
    {
        if (!isset($this->relations[$relationName])) {
            throw new \OutOfBoundsException("Unknown relation: {$relationName}");
        }
        $this->relations[$relationName] = $isLoaded;
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

    public function getObject()
    {
        return $this->object;
    }

    public function setState($state)
    {
        $this->objectState = $state;
    }

    public function getOriginalFieldData($field)
    {
        if (isset($this->originalData[$field])) {
            return $this->originalData[$field];
        }

        return null;
    }
}