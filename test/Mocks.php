<?php

namespace ORMiny;

use ORMiny\Annotations\Field;
use ORMiny\Annotations\Relation;

/**
 * @Table related
 */
class RelatedEntity
{
    /**
     * @Id @Field()
     */
    public $primaryKey;
}

/**
 * @Table hasOne
 */
class HasOneRelationEntity
{
    /**
     * @Id @Field()
     */
    public $pk;

    /**
     * @Field()
     */
    public $fk;

    /**
     * @Relation('hasOneRelation',
     *     type: 'has one',
     *     target: 'RelatedEntity',
     *     foreignKey: 'fk',
     *     targetKey: 'primaryKey'
     * )
     */
    public $relation;
}

/**
 * @Table deep
 */
class DeepRelationEntity
{
    /**
     * @Id @Field()
     */
    public $pk;

    /**
     * @Field()
     */
    public $fk;

    /**
     * @Relation('relation',
     *     type: 'has many',
     *     target: 'HasOneRelationEntity',
     *     foreignKey: 'fk',
     *     targetKey: 'pk'
     * )
     */
    public $relation;
}

/**
 * @Table multiple
 */
class MultipleRelationEntity
{
    /**
     * @Id @Field()
     */
    public $pk;

    /**
     * @Field()
     */
    public $fk;

    /**
     * @Field()
     */
    public $fk2;

    /**
     * @Relation('relation',
     *     type: 'has many',
     *     target: 'HasOneRelationEntity',
     *     foreignKey: 'fk',
     *     targetKey: 'pk'
     * )
     */
    public $relation;

    /**
     * @Relation('deepRelation',
     *     type: 'has one',
     *     target: 'DeepRelationEntity',
     *     foreignKey: 'fk2',
     *     targetKey: 'pk'
     * )
     */
    public $otherRelation;
}

/**
 * @Table many_many
 */
class ManyManyRelationEntity
{
    /**
     * @Id @Field()
     */
    public $pk;

    /**
     * @Field()
     */
    public $fk;

    /**
     * @Relation('relation',
     *     type: 'many to many',
     *     target: 'RelatedEntity',
     *     foreignKey: 'fk',
     *     targetKey: 'primaryKey',
     *     joinTable: 'joinTable'
     * )
     */
    public $relation;
}

/**
 * @Table has_many
 */
class HasManyRelationEntity
{
    /**
     * @Id @Field()
     */
    public $pk;

    /**
     * @Relation('relation',
     *     type: 'has many',
     *     target: 'HasManyTargetEntity',
     *     foreignKey: 'pk',
     *     targetKey: 'foreignKey'
     * )
     */
    public $relation;
}

/**
 * @Table related
 */
class HasManyTargetEntity
{
    /**
     * @Id @Field()
     */
    public $primaryKey;

    /**
     * @Field()
     */
    public $foreignKey;
}

/**
 * @Table test
 */
class TestEntity
{
    /**
     * @Id @Field(name: 'key')
     */
    public $field;

    /**
     * @Field(setter: 'setField', getter: 'getField')
     */
    private $fieldWithSetter;

    /**
     * @Field(setter: true, getter: true)
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
