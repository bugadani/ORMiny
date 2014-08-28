<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

use Modules\Annotation\Comment;
use Modules\Annotation\Reader;
use Modules\DBAL\Driver;
use Modules\DBAL\QueryBuilder;
use ORMiny\Exceptions\EntityDefinitionException;

class EntityManager
{
    const RELATION_ANNOTATION = 'ORMiny\\Annotations\\Relation';
    const FIELD_ANNOTATION    = 'ORMiny\\Annotations\\Field';

    /**
     * @var Driver
     */
    private $driver;

    /**
     * @var Reader
     */
    private $annotationReader;

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

    public function __construct(Driver $driver, Reader $annotationReader)
    {
        $this->driver           = $driver;
        $this->annotationReader = $annotationReader;
        $this->resultProcessor  = new ResultProcessor;
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
        try {
            $classAnnotations = $this->annotationReader->readClass($className);
            $entity           = new Entity($this, $className, $classAnnotations->get('Table'));

            $this->entities[$className] = $entity;
        } catch (\OutOfBoundsException $e) {
            throw new EntityDefinitionException("Missing Table annotation of {$className}", 0, $e);
        }

        $filter = \ReflectionProperty::IS_PRIVATE
            | \ReflectionProperty::IS_PROTECTED
            | \ReflectionProperty::IS_PUBLIC;

        $properties = $this->annotationReader->readProperties($className, $filter);

        $primaryKey = null;
        foreach ($properties as $property => $comment) {
            if ($comment->hasAnnotationType(self::FIELD_ANNOTATION)) {
                $fieldName = $this->processField($comment, $property, $entity);
                if ($comment->has('Id')) {
                    if (isset($primaryKey)) {
                        throw new EntityDefinitionException("Class {$className} must only have one primary key.");
                    }
                    $primaryKey = $fieldName;
                }
            } elseif ($comment->hasAnnotationType(self::RELATION_ANNOTATION)) {
                $this->processRelation($comment, $property, $entity);
            }
        }
        if (!isset($primaryKey)) {
            throw new EntityDefinitionException("Class {$className} must have a primary key.");
        }
        $entity->setPrimaryKey($primaryKey);
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
        return $this->getEntityByClass(get_class($object));
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
     * @param Comment $comment
     * @param         $property
     * @param Entity  $entity
     *
     * @return string The field name.
     */
    private function processField($comment, $property, $entity)
    {
        $fieldAnnotation = current($comment->getAnnotationType(self::FIELD_ANNOTATION));

        $setter = $fieldAnnotation->setter;
        $getter = $fieldAnnotation->getter;

        if ($setter === true) {
            $setter = 'set' . ucfirst($property);
        }
        if ($getter === true) {
            $getter = 'get' . ucfirst($property);
        }

        return $entity->addField($property, $fieldAnnotation->name, $setter, $getter);
    }

    /**
     * @param Comment $comment
     * @param         $property
     * @param Entity  $entity
     */
    private function processRelation($comment, $property, $entity)
    {
        $relation = current($comment->getAnnotationType(self::RELATION_ANNOTATION));
        $entity->addRelation($property, $relation);

        $setter = $relation->setter;
        $getter = $relation->getter;

        if ($setter === true) {
            $setter = 'set' . ucfirst($property);
        }
        if ($getter === true) {
            $getter = 'get' . ucfirst($property);
        }

        $entity->addRelation($property, $relation, $setter, $getter);
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
