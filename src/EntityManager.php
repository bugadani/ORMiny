<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use Modules\Annotation\Comment;
use Modules\Annotation\Reader;
use Modules\DBAL\Driver;
use Modules\DBAL\QueryBuilder;
use Modules\ORM\Exceptions\EntityDefinitionException;

class EntityManager
{
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
            throw new \OutOfBoundsException("Unknown entity {$entityName}");
        }

        return $this->entityClassMap[$entityName];
    }

    private function load($className)
    {
        $classAnnotations = $this->annotationReader->readClass($className);

        $filter = \ReflectionProperty::IS_PRIVATE
            | \ReflectionProperty::IS_PROTECTED
            | \ReflectionProperty::IS_PUBLIC;

        $properties = $this->annotationReader->readProperties($className, $filter);

        if (!$classAnnotations->has('Table')) {
            throw new EntityDefinitionException("Missing Table annotation of {$className}");
        }
        if (!$classAnnotations->has('PrimaryKey')) {
            throw new EntityDefinitionException("Class {$className} must have a primary key.");
        }
        $entity = new Entity($this, $className, $classAnnotations->get('Table'));

        foreach ($properties as $property => $comment) {
            if ($comment->has('Field')) {
                $this->processField($comment, $property, $entity);
            } elseif ($comment->hasAnnotationType('Modules\\ORM\\Annotations\\Relation')) {
                $this->processRelation($comment, $property, $entity);
            }
        }
        $entity->setPrimaryKey($classAnnotations->get('PrimaryKey'));

        return $entity;
    }

    private function getEntityByClass($className)
    {
        if (!isset($this->entities[$className])) {
            $this->entities[$className] = $this->load($className);
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
     * @param string  $tag
     *
     * @return mixed
     */
    private function getAnnotationTag($comment, $tag)
    {
        if ($comment->has($tag)) {
            return $comment->get($tag);
        } else {
            return null;
        }
    }

    /**
     * @param Comment $comment
     * @param         $property
     * @param Entity  $entity
     */
    private function processField($comment, $property, $entity)
    {
        $fieldName = $comment->get('Field');
        if ($comment->has('AutomaticSetterAndGetter')) {
            $temp   = ucfirst($property);
            $setter = 'set' . $temp;
            $getter = 'get' . $temp;
        } else {
            $setter = $this->getAnnotationTag($comment, 'Setter');
            $getter = $this->getAnnotationTag($comment, 'Getter');
        }
        $entity->addField($property, $fieldName, $setter, $getter);
    }

    /**
     * @param Comment $comment
     * @param         $property
     * @param Entity  $entity
     */
    private function processRelation($comment, $property, $entity)
    {
        $relation = current($comment->getAnnotationType('Modules\\ORM\\Annotations\\Relation'));
        $entity->addRelation($property, $relation);

        if ($comment->has('AutomaticSetterAndGetter')) {
            $temp   = ucfirst($property);
            $setter = 'set' . $temp;
            $getter = 'get' . $temp;
        } else {
            $setter = $this->getAnnotationTag($comment, 'Setter');
            $getter = $this->getAnnotationTag($comment, 'Getter');
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
