<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

use Modules\DBAL\Driver;
use ORMiny\Drivers\AnnotationMetadataDriver;

class EntityManager
{
    /**
     * @var Driver
     */
    private $driver;

    /**
     * @var string[]
     */
    private $entityClassMap = [];

    /**
     * @var Entity[]
     */
    private $entities = [];

    /**
     * @var ResultProcessor
     */
    private $resultProcessor;

    private $defaultNamespace = '';

    /**
     * @var AnnotationMetadataDriver
     */
    private $metadataDriver;

    public function __construct(Driver $driver, MetadataDriverInterface $metadataDriver)
    {
        $this->driver          = $driver;
        $this->metadataDriver  = $metadataDriver;
        $this->resultProcessor = new ResultProcessor($this);
    }

    /**
     * @return ResultProcessor
     */
    public function getResultProcessor()
    {
        return $this->resultProcessor;
    }

    /**
     * @return Driver
     */
    public function getDriver()
    {
        return $this->driver;
    }

    public function setDefaultNamespace($namespace)
    {
        $this->defaultNamespace = $namespace;
    }

    public function register($entityName, $className)
    {
        $this->entityClassMap[$entityName] = $className;
    }

    /**
     * Returns the name of class that $entityName handles.
     */
    private function getEntityClassName($entityName)
    {
        if (!isset($this->entityClassMap[$entityName])) {
            $className = $entityName;
            if (!class_exists($className)) {
                if (!class_exists($this->defaultNamespace . $className)) {
                    throw new \OutOfBoundsException("Unknown entity {$entityName}");
                }
                $className = $this->defaultNamespace . $entityName;
            }
            $this->entityClassMap[$entityName] = $className;
        }

        return $this->entityClassMap[$entityName];
    }

    private function load($className)
    {
        $metadata = $this->metadataDriver->readEntityMetadata($className);

        $this->entities[$className] = new Entity($this, $metadata);
    }

    private function getEntityByClass($className)
    {
        if (!isset($this->entities[$className])) {
            $this->load($className);
        }

        return $this->entities[$className];
    }

    public function getEntityForObject($object)
    {
        return $this->getEntityByClass(
            get_class($object)
        );
    }

    /**
     * @param $entityName
     *
     * @return Entity
     */
    public function get($entityName)
    {
        return $this->getEntityByClass(
            $this->getEntityClassName($entityName)
        );
    }

    /**
     * @param $entityName
     *
     * @return EntityFinder
     */
    public function find($entityName)
    {
        return $this->get($entityName)->find();
    }
}
