<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny\Annotations;

use Modules\Annotation\Exceptions\AnnotationException;

/**
 * @Annotation
 * @DefaultAttribute name
 * @Attribute('name', type: 'string', required: true)
 * @Attribute('type', type: @Enum({Relation::HAS_ONE, Relation::HAS_MANY, Relation::BELONGS_TO, Relation::MANY_MANY}))
 * @Attribute('target', type: 'string')
 * @Attribute('foreignKey', type: 'string')
 * @Attribute('targetKey', type: 'string')
 * @Attribute('joinTable', type: 'string')
 * @Attribute('joinTableForeignKey', type: 'string')
 * @Attribute('joinTableTargetKey', type: 'string')
 * @Attribute('setter')
 * @Attribute('getter')
 * @Target('property')
 */
class Relation
{
    const HAS_ONE    = 'has one';
    const HAS_MANY   = 'has many';
    const BELONGS_TO = 'belongs to';
    const MANY_MANY  = 'many to many';

    public $name;
    public $type;
    public $target;
    public $foreignKey;
    public $targetKey;
    public $joinTableForeignKey;
    public $joinTableTargetKey;
    public $joinTable;
    public $setter;
    public $getter;

    private $setterIsMethod;
    private $getterIsMethod;

    public function __construct(
        $name,
        $type = Relation::HAS_ONE,
        $target = null,
        $foreignKey = null,
        $targetKey = null,
        $joinTable = null,
        $joinTableForeignKey = null,
        $joinTableTargetKey = null
    )
    {
        if ($target === null) {
            $target = $name;
        }

        $this->name   = $name;
        $this->type   = $type;
        $this->target = $target;

        if ($this->isSingle()) {
            if ($foreignKey === null) {
                $foreignKey = $target . '_id';
            }
            if ($targetKey === null) {
                $targetKey = 'id';
            }
        } else {
            if ($foreignKey === null) {
                $foreignKey = 'id';
            }
            if ($targetKey === null) {
                $targetKey = $target . '_id';
            }
            if ($type === Relation::MANY_MANY) {
                if ($joinTable === null) {
                    print_r(func_get_args());
                    throw new AnnotationException('Many to many type relations require a join table.');
                }
                $this->joinTable           = $joinTable;
                $this->joinTableForeignKey = $joinTableForeignKey;
                $this->joinTableTargetKey  = $joinTableTargetKey;
            }
        }
        $this->foreignKey = $foreignKey;
        $this->targetKey  = $targetKey;
    }

    /**
     * @return bool
     */
    public function isSingle()
    {
        return $this->type === Relation::HAS_ONE || $this->type === Relation::BELONGS_TO;
    }

    public function setValue($object, $value)
    {
        if ($this->setterIsMethod === null) {
            if (is_callable([$object, $this->setter])) {
                $this->setterIsMethod = true;
            } else {
                $this->setterIsMethod = false;
            }
        }

        if ($this->setterIsMethod) {
            $object->{$this->setter}($value);
        } else {
            $object->{$this->setter} = $value;
        }
    }

    public function getValue($object)
    {
        if ($this->getterIsMethod === null) {
            if (is_callable([$object, $this->getter])) {
                $this->getterIsMethod = true;
            } else {
                $this->getterIsMethod = false;
            }
        }

        if ($this->getterIsMethod) {
            return $object->{$this->getter}();
        } else if (isset($object->{$this->getter})) {
            return $object->{$this->getter};
        } else {
            return $this->isSingle() ? null : [];
        }
    }
}
