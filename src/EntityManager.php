<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

use Modules\DBAL\AbstractQueryBuilder;
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

    /**
     * @var string
     */
    private $defaultNamespace = '';

    /**
     * @var AnnotationMetadataDriver
     */
    private $metadataDriver;
    private $pendingQueries = [];

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
     *
     * @param $entityName
     *
     * @return string
     */
    private function getEntityClassName($entityName)
    {
        if (!isset($this->entityClassMap[$entityName])) {
            $className = $entityName;
            if (!class_exists($className)) {
                $className = $this->defaultNamespace . $entityName;
                if (!class_exists($className)) {
                    $className = false;
                }
            }
            $this->entityClassMap[$entityName] = $className;
        }

        if ($this->entityClassMap[$entityName] === false) {
            throw new \OutOfBoundsException("Unknown entity {$entityName}");
        }

        return $this->entityClassMap[$entityName];
    }

    private function getEntityByClass($className)
    {
        if (!isset($this->entities[$className])) {
            $metadata = $this->metadataDriver->readEntityMetadata($className);

            $this->entities[$className] = new Entity($this, $metadata);
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

    public function postPendingQuery(AbstractQueryBuilder $query, array $params = [])
    {
        $this->pendingQueries[] = [$query, $params];
    }

    public function commit()
    {
        $this->driver->inTransaction(
            function (Driver $driver, array $pendingQueries) {
                foreach ($pendingQueries as $item) {
                    list($query, $params) = $item;
                    /** @var AbstractQueryBuilder $query */
                    $query->query($params);
                }
            },
            $this->pendingQueries
        );
    }
}
