<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny\Drivers;

use Annotiny\Comment;
use Annotiny\Reader;
use ORMiny\Annotations\Field as FieldAnnotation;
use ORMiny\Annotations\Relation as RelationAnnotation;
use ORMiny\Entity;
use ORMiny\EntityManager;
use ORMiny\Exceptions\EntityDefinitionException;
use ORMiny\Metadata\Field;
use ORMiny\Metadata\Getter;
use ORMiny\Metadata\Getter\MethodGetter;
use ORMiny\Metadata\Getter\PropertyGetter;
use ORMiny\Metadata\Relation;
use ORMiny\Metadata\Setter;
use ORMiny\Metadata\Setter\MethodSetter;
use ORMiny\Metadata\Setter\PropertySetter;
use ORMiny\MetadataDriverInterface;

/**
 * Class AnnotationMetadataDriver
 *
 * @package ORMiny\Drivers
 */
class AnnotationMetadataDriver implements MetadataDriverInterface
{
    /**
     * @var Reader
     */
    private $annotationReader;

    /**
     * @var Entity[]
     */
    private $entities = [];

    /**
     * @var EntityManager
     */
    private $manager;

    /**
     * AnnotationMetadataDriver constructor.
     *
     * @param Reader $annotationReader
     */
    public function __construct(Reader $annotationReader)
    {
        $this->annotationReader = $annotationReader;
    }

    /**
     * @param EntityManager $manager
     */
    public function setEntityManager(EntityManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * @param Entity $entity
     *
     * @throws EntityDefinitionException
     */
    public function readEntityMetadata(Entity $entity)
    {
        $className = $entity->getClassName();
        if (!isset($this->entities[ $className ])) {
            $this->entities[ $className ] = $entity;

            $this->readTableName($entity);

            $filter = \ReflectionProperty::IS_PRIVATE
                      | \ReflectionProperty::IS_PROTECTED
                      | \ReflectionProperty::IS_PUBLIC;

            $properties = $this->annotationReader->readProperties($className, $filter);

            try {
                foreach ($properties as $property => $comment) {
                    if ($comment->hasAnnotationType(FieldAnnotation::class)) {
                        $this->processField($comment, $property, $entity);
                    } else if ($comment->hasAnnotationType(RelationAnnotation::class)) {
                        $this->processRelation($comment, $property, $entity);
                    }
                }
                if ($entity->getPrimaryKey() === null) {
                    throw new EntityDefinitionException("Class {$className} must have a primary key.");
                }
            } catch (EntityDefinitionException $e) {
                throw new EntityDefinitionException("Could not instantiate metadata for {$className}", 0, $e);
            }
        }
    }

    /**
     * @param Comment $comment
     * @param         $property
     * @param Entity  $entity
     *
     * @return string The field name.
     */
    private function processField(Comment $comment, $property, Entity $entity)
    {
        /** @var \ORMiny\Annotations\Field $fieldAnnotation */
        $fieldAnnotation = current($comment->getAnnotationType(FieldAnnotation::class));

        if ($fieldAnnotation->name === null) {
            $fieldAnnotation->name = $property;
        }

        $field = new Field(
            $this->createSetter($entity, $property, $fieldAnnotation),
            $this->createGetter($entity, $property, $fieldAnnotation)
        );

        $fieldName = $entity->addField($fieldAnnotation->name, $field);

        if ($comment->has('Id')) {
            if ($entity->getPrimaryKey() !== null) {
                throw new EntityDefinitionException("Compound primary key is not supported.");
            }
            $entity->setPrimaryKey($fieldName);
        }
    }

    /**
     * @param Comment $comment
     * @param         $property
     * @param Entity  $entity
     */
    private function processRelation(Comment $comment, $property, Entity $entity)
    {
        /** @var \ORMiny\Annotations\Relation $relationAnnotation */
        $relationAnnotation = current($comment->getAnnotationType(RelationAnnotation::class));

        $relation = Relation::create(
            $entity,
            $this->manager->get($relationAnnotation->target),
            $relationAnnotation,
            $this->createSetter($entity, $property, $relationAnnotation),
            $this->createGetter($entity, $property, $relationAnnotation)
        );

        $entity->addRelation($relationAnnotation->name, $relationAnnotation->foreignKey, $relation);
    }

    /**
     * @param Entity $entity
     */
    private function readTableName(Entity $entity)
    {
        $className = $entity->getClassName();
        try {
            $classAnnotations = $this->annotationReader->readClass($className);
            $entity->setTable($classAnnotations->get('Table'));
        } catch (\OutOfBoundsException $e) {
            throw new EntityDefinitionException("Missing Table annotation of {$className}", 0, $e);
        }
    }

    /**
     * @param Entity $entity
     * @param        $property
     * @param        $annotation
     *
     * @return Setter
     */
    private function createSetter(Entity $entity, $property, $annotation)
    {
        if ($annotation->setter === null) {
            $setter = new PropertySetter($entity, $property);
        } else {
            if ($annotation->setter === true) {
                $methodName = 'set' . ucfirst($property);
            } else {
                $methodName = $annotation->setter;
            }
            $setter = new MethodSetter($entity, $methodName);
        }

        return $setter;
    }

    /**
     * @param Entity $entity
     * @param        $property
     * @param        $annotation
     *
     * @return Getter
     */
    private function createGetter(Entity $entity, $property, $annotation)
    {
        if ($annotation->getter === null) {
            $getter = new PropertyGetter($entity, $property);
        } else {
            if ($annotation->getter === true) {
                $methodName = 'get' . ucfirst($property);
            } else {
                $methodName = $annotation->getter;
            }
            $getter = new MethodGetter($entity, $methodName);
        }

        return $getter;
    }
}
