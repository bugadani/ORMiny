<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny\Drivers;

use Modules\Annotation\Comment;
use Modules\Annotation\Reader;
use ORMiny\EntityMetadata;
use ORMiny\Exceptions\EntityDefinitionException;
use ORMiny\MetadataDriverInterface;

class AnnotationMetadataDriver implements MetadataDriverInterface
{
    const RELATION_ANNOTATION = 'ORMiny\\Annotations\\Relation';
    const FIELD_ANNOTATION    = 'ORMiny\\Annotations\\Field';

    /**
     * @var Reader
     */
    private $annotationReader;

    /**
     * @var EntityMetadata[]
     */
    private $metadata = [];

    public function __construct(Reader $annotationReader)
    {
        $this->annotationReader = $annotationReader;
    }

    /**
     * @param $className
     *
     * @throws EntityDefinitionException
     * @internal param $class
     *
     * @return EntityMetadata
     */
    public function readEntityMetadata($className)
    {
        if (isset($this->metadata[ $className ])) {
            return $this->metadata[ $className ];
        }
        $metadata = $this->createInstance($className);

        $filter = \ReflectionProperty::IS_PRIVATE
                  | \ReflectionProperty::IS_PROTECTED
                  | \ReflectionProperty::IS_PUBLIC;

        $properties = $this->annotationReader->readProperties($className, $filter);

        $primaryKey = null;
        foreach ($properties as $property => $comment) {
            if ($comment->hasAnnotationType(self::FIELD_ANNOTATION)) {
                $fieldName = $this->processField($comment, $property, $metadata);
                if ($comment->has('Id')) {
                    if (isset($primaryKey)) {
                        throw new EntityDefinitionException("Class {$className} must only have one primary key.");
                    }
                    $primaryKey = $fieldName;
                }
            } elseif ($comment->hasAnnotationType(self::RELATION_ANNOTATION)) {
                $this->processRelation($comment, $property, $metadata);
            }
        }
        if (!isset($primaryKey)) {
            throw new EntityDefinitionException("Class {$className} must have a primary key.");
        }
        $metadata->setPrimaryKey($primaryKey);

        return $metadata;
    }

    /**
     * @param Comment        $comment
     * @param                $property
     * @param EntityMetadata $metadata
     *
     * @return string The field name.
     */
    private function processField(Comment $comment, $property, EntityMetadata $metadata)
    {
        $fieldAnnotation = current($comment->getAnnotationType(self::FIELD_ANNOTATION));

        $setter = $fieldAnnotation->setter;
        if ($setter === true) {
            $setter = 'set' . ucfirst($property);
        }

        $getter = $fieldAnnotation->getter;
        if ($getter === true) {
            $getter = 'get' . ucfirst($property);
        }

        return $metadata->addField($property, $fieldAnnotation->name, $setter, $getter);
    }

    /**
     * @param Comment        $comment
     * @param                $property
     * @param EntityMetadata $metadata
     */
    private function processRelation(Comment $comment, $property, EntityMetadata $metadata)
    {
        $relation = current($comment->getAnnotationType(self::RELATION_ANNOTATION));

        $setter = $relation->setter;
        if ($setter === true) {
            $setter = 'set' . ucfirst($property);
        }

        $getter = $relation->getter;
        if ($getter === true) {
            $getter = 'get' . ucfirst($property);
        }

        $metadata->addRelation($property, $relation, $setter, $getter);
    }

    /**
     * @param $className
     *
     * @return EntityMetadata
     * @throws EntityDefinitionException
     */
    private function createInstance($className)
    {
        try {
            $classAnnotations = $this->annotationReader->readClass($className);

            $metadata = new EntityMetadata($className);
            $metadata->setTable($classAnnotations->get('Table'));

            $this->metadata[ $className ] = $metadata;
        } catch (\OutOfBoundsException $e) {
            throw new EntityDefinitionException("Missing Table annotation of {$className}", 0, $e);
        }

        return $metadata;
    }
}
