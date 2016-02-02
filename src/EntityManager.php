<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

use DBTiny\AbstractQueryBuilder;
use DBTiny\Driver;

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
     * @var MetadataDriverInterface
     */
    private $metadataDriver;

    /**
     * @var PendingQuery[] Pending queries
     */
    private $pendingQueries = [];

    public function __construct(Driver $driver, MetadataDriverInterface $metadataDriver)
    {
        $metadataDriver->setEntityManager($this);
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
        $this->entityClassMap[ $entityName ] = $className;
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
        if (!isset($this->entityClassMap[ $entityName ])) {
            $className = $entityName;
            if (!class_exists($className)) {
                $className = $this->defaultNamespace . $entityName;
                if (!class_exists($className)) {
                    //Cache that the class does not exist
                    $className = false;
                }
            }
            $this->entityClassMap[ $entityName ] = $className;
        }

        if ($this->entityClassMap[ $entityName ] === false) {
            throw new \OutOfBoundsException("Unknown entity {$entityName}");
        }

        return $this->entityClassMap[ $entityName ];
    }

    private function getEntityByClass($className)
    {
        if (!isset($this->entities[ $className ])) {
            $entity                       = new Entity($this, $className);
            $this->entities[ $className ] = $entity;

            $this->metadataDriver->readEntityMetadata($entity);
        }

        return $this->entities[ $className ];
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
     * @param        $entityName
     * @param string $alias the table alias
     *
     * @return EntityFinder
     */
    public function find($entityName, $alias = null)
    {
        return $this->get($entityName)->find($alias);
    }

    public function postPendingQuery(PendingQuery $query)
    {
        $this->pendingQueries[] = $query;
    }

    /**
     * Returns the pending queries
     *
     * The returned array has the structure of [string $query, array $parameters]
     *
     * @return array the pending queries
     */
    public function getPendingQueries()
    {
        return $this->pendingQueries;
    }

    /**
     * Executes pending queries
     */
    public function commit()
    {
        $this->driver->inTransaction(
            function (Driver $driver, array $pendingQueries) {
                /** @var PendingQuery[] $pendingQueries */
                foreach ($pendingQueries as $item) {
                    $item->execute();
                }
            },
            $this->pendingQueries
        );
        $this->pendingQueries = [];
    }
}
