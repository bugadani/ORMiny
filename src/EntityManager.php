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
use Modules\ORM\Annotations\Relation;
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
        $entity = new Entity($this, $className, $classAnnotations->get('Table'));

        foreach ($properties as $property => $comment) {
            if ($comment->has('Field')) {
                $this->processField($comment, $property, $entity);
            } elseif ($comment->hasAnnotationType('Modules\\ORM\\Annotations\\Relation')) {
                $this->processRelation($comment, $property, $entity);
            }
        }
        if ($classAnnotations->has('PrimaryKey')) {
            $entity->setPrimaryKey($classAnnotations->get('PrimaryKey'));
        }

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
        return $this->getEntityFinder($this->get($entityName));
    }

    private function getEntityFinder(Entity $entity)
    {
        return new EntityFinder($this->resultProcessor, $this->driver, $entity);
    }

    public function save(Entity $entity, $object)
    {
        $queryBuilder = $this->driver->getQueryBuilder();

        if ($entity->isPrimaryKeySet($object)) {
            $query = $queryBuilder->update($entity->getTable());
        } else {
            $query = $queryBuilder->insert($entity->getTable());
        }

        foreach ($entity->toArray($object) as $field => $value) {
            $query->set(
                $field,
                $query->createPositionalParameter($value)
            );
        }
        $query->query();
    }

    public function delete(Entity $entity, $object)
    {
        foreach ($entity->getRelatedEntities() as $relationName => $relatedEntity) {
            $relatedObjects = $entity->getRelationValue($object, $relationName);
            $relation       = $entity->getRelation($relationName);
            switch ($relation->type) {
                case Relation::HAS_ONE:
                    $this->delete($relatedEntity, $relatedObjects);;
                    break;

                case Relation::HAS_MANY:
                    array_map([$relatedEntity, 'delete'], $relatedObjects);
                    break;

                case Relation::MANY_MANY:
                    $joinTable = $entity->getTable() . '_' . $relatedEntity->getTable();
                    $field     = $entity->getTable() . '_' . $relation->foreignKey;

                    $queryBuilder = $this->driver->getQueryBuilder();
                    $queryBuilder
                        ->delete($joinTable)
                        ->where(
                            $this->createInExpression(
                                $field,
                                array_map(
                                    [$relatedEntity, 'getPrimaryKeyValue'],
                                    $relatedObjects
                                ),
                                $queryBuilder
                            )
                        )->query();
                    break;

                case Relation::BELONGS_TO:
                    //don't delete the record this one belongs to
                    break;
            }
        }

        if ($entity->isPrimaryKeySet($object)) {
            $this->deleteByPrimaryKey(
                $entity,
                $entity->getPrimaryKeyValue($object)
            );
        }
    }

    public function getByPrimaryKey(Entity $entity, $primaryKey)
    {
        return $this->getEntityFinder($entity)
            ->get($primaryKey);
    }

    private function createInExpression($field, array $values, QueryBuilder $queryBuilder)
    {
        $expression = $queryBuilder->expression();
        if (count($values) === 1) {
            $expression->eq(
                $field,
                $queryBuilder->createPositionalParameter(current($values))
            );
        } else {
            $expression->in(
                $field,
                array_map([$queryBuilder, 'createPositionalParameter'], $values)
            );
        }

        return $expression;
    }

    public function deleteByPrimaryKey(Entity $entity, $primaryKey)
    {
        $queryBuilder = $this->driver->getQueryBuilder();
        $queryBuilder->delete($entity->getTable())
            ->where(
                $this->createInExpression(
                    $entity->getPrimaryKey(),
                    (array) $primaryKey,
                    $queryBuilder
                )
            )->query();
    }
}
