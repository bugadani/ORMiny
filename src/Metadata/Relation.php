<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny\Metadata;

use Modules\DBAL\QueryBuilder\Expression;
use Modules\DBAL\QueryBuilder\Select;
use ORMiny\Annotations\Relation as RelationAnnotation;
use ORMiny\Entity;
use ORMiny\EntityManager;
use ORMiny\Metadata\Relation\BelongsTo;
use ORMiny\Metadata\Relation\HasMany;
use ORMiny\Metadata\Relation\HasOne;
use ORMiny\Metadata\Relation\ManyToMany;

abstract class Relation implements Setter, Getter
{
    /**
     * @var Entity
     */
    protected $entity;

    /**
     * @var Entity
     */
    protected $related;

    /**
     * @var Setter
     */
    private $setter;

    /**
     * @var Getter
     */
    private $getter;

    /**
     * @var RelationAnnotation
     */
    protected $relationAnnotation;

    private function __construct(Entity $metadata, Entity $related, RelationAnnotation $relation, Setter $setter, Getter $getter)
    {
        $this->entity             = $metadata;
        $this->related            = $related;
        $this->relationAnnotation = $relation;
        $this->setter             = $setter;
        $this->getter             = $getter;
    }

    public static function create(Entity $metadata, Entity $related, RelationAnnotation $relationAnnotation, $setter, $getter)
    {
        switch ($relationAnnotation->type) {
            case RelationAnnotation::HAS_ONE:
                return new HasOne($metadata, $related, $relationAnnotation, $setter, $getter);
            case RelationAnnotation::BELONGS_TO:
                return new BelongsTo($metadata, $related, $relationAnnotation, $setter, $getter);
            case RelationAnnotation::HAS_MANY:
                return new HasMany($metadata, $related, $relationAnnotation, $setter, $getter);
            case RelationAnnotation::MANY_MANY:
                return new ManyToMany($metadata, $related, $relationAnnotation, $setter, $getter);
        }
    }

    /**
     * @param $object
     *
     * @return mixed The field value from $object
     */
    public function get($object)
    {
        return $this->getter->get($object) ?: $this->getEmptyValue();
    }

    /**
     * @param $object
     * @param $value
     *
     * @return mixed The new value
     */
    public function set($object, $value)
    {
        return $this->setter->set($object, $value);
    }

    /**
     * @return bool
     */
    public function isSingle()
    {
        return false;
    }

    /**
     * @return Entity
     */
    public function getEntity()
    {
        return $this->related;
    }

    /**
     * @return RelationAnnotation
     */
    public function getEntityName()
    {
        return $this->relationAnnotation->target;
    }

    public function getTargetKey()
    {
        return $this->relationAnnotation->targetKey;
    }

    public function getForeignKey()
    {
        return $this->relationAnnotation->foreignKey;
    }

    abstract public function getForeignKeyValue($object);

    public function getRelationName()
    {
        return $this->relationAnnotation->name;
    }

    public function joinToQuery(Select $query, $leftAlias, $alias)
    {
        $query->leftJoin(
            $leftAlias,
            $this->related->getTable(),
            $alias,
            (new Expression())->eq(
                "{$leftAlias}.{$this->getForeignKey()}",
                "{$alias}.{$this->getTargetKey()}"
            )
        );
    }

    /**
     * @return array|null
     */
    abstract public function getEmptyValue();

    abstract public function delete(EntityManager $manager, $foreignKey);
}