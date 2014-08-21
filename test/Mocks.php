<?php

namespace Modules\ORM;

use Modules\ORM\Annotations as ORM;

/**
 * @Table      related
 * @PrimaryKey primaryKey
 */
class RelatedEntity
{
    /**
     * @Field primaryKey
     */
    public $primaryKey;
}

/**
 * @Table      hasOne
 * @PrimaryKey pk
 */
class HasOneRelationEntity
{
    /**
     * @Field
     */
    public $pk;

    /**
     * @Field
     */
    public $fk;

    /**
     * @ORM\Relation('hasOneRelation',
     *     type: 'has one',
     *     target: 'RelatedEntity',
     *     foreignKey: 'fk',
     *     targetKey: 'primaryKey'
     * )
     */
    public $relation;
}

/**
 * @Table      deep
 * @PrimaryKey pk
 */
class DeepRelationEntity
{
    /**
     * @Field
     */
    public $pk;

    /**
     * @Field
     */
    public $fk;

    /**
     * @ORM\Relation('relation',
     *     type: 'has many',
     *     target: 'HasOneRelationEntity',
     *     foreignKey: 'fk',
     *     targetKey: 'pk'
     * )
     */
    public $relation;
}

/**
 * @Table      many_many
 * @PrimaryKey pk
 */
class ManyManyRelationEntity
{
    /**
     * @Field
     */
    public $pk;

    /**
     * @Field
     */
    public $fk;

    /**
     * @ORM\Relation('relation',
     *     type: 'many to many',
     *     target: 'RelatedEntity',
     *     foreignKey: 'fk',
     *     targetKey: 'primaryKey'
     * )
     */
    public $relation;
}

/**
 * @Table      test
 * @PrimaryKey field
 */
class TestEntity
{
    /**
     * @Field field
     */
    public $field;

    /**
     * @Field  fieldWithSetter
     * @Setter setField
     * @Getter getField
     */
    private $fieldWithSetter;

    /**
     * @Field field2
     * @AutomaticSetterAndGetter
     */
    public $field2;

    public function setField($field)
    {
        $this->fieldWithSetter = $field . ' via setter';
    }

    public function getField()
    {
        return $this->fieldWithSetter . ' and getter';
    }

    public function setField2($field)
    {
        $this->field2 = $field . ' via setter';
    }

    public function getField2()
    {
        return $this->field2 . ' and getter';
    }
}
