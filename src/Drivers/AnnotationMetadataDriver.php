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

        try {
            foreach ($properties as $property => $comment) {
                if ($comment->hasAnnotationType(self::FIELD_ANNOTATION)) {
                    $this->processField($comment, $property, $metadata);
                } else if ($comment->hasAnnotationType(self::RELATION_ANNOTATION)) {
                    $this->processRelation($comment, $property, $metadata);
                }
            }
            if ($metadata->getPrimaryKey() === null) {
                throw new EntityDefinitionException("Class {$className} must have a primary key.");
            }
        } catch (EntityDefinitionException $e) {
            throw new EntityDefinitionException("Could not instantiate metadata for {$className}", 0, $e);
        }

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

        $fieldName = $metadata->addField($property, $fieldAnnotation);

        if ($comment->has('Id')) {
            if($metadata->getPrimaryKey() !== null) {
                throw new EntityDefinitionException("Compound primary key is not supported.");
            }
            $metadata->setPrimaryKey($fieldName);
        }
    }

    /**
     * @param Comment        $comment
     * @param                $property
     * @param EntityMetadata $metadata
     */
    private function processRelation(Comment $comment, $property, EntityMetadata $metadata)
    {
        $relation = current($comment->getAnnotationType(self::RELATION_ANNOTATION));

        $metadata->addRelation($property, $relation);
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
