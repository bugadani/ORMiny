<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny\Annotations;

/**
 * @Annotation
 * @DefaultAttribute name
 * @Attribute('name', type: 'string', required: true)
 * @Attribute('type', type: @Enum({Relation::HAS_ONE, Relation::HAS_MANY, Relation::BELONGS_TO, Relation::MANY_MANY}))
 * @Attribute('target', type: 'string')
 * @Attribute('foreignKey', type: 'string')
 * @Attribute('targetKey', type: 'string')
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
    public $setter;
    public $getter;

    public function __construct($name, $type = Relation::HAS_ONE, $target = null, $foreignKey = null, $targetKey = null)
    {
        if($target === null) {
            $target = $name;
        }

        $this->name   = $name;
        $this->type   = $type;
        $this->target = $target;

        if ($foreignKey === null) {
            switch ($type) {
                case Relation::BELONGS_TO:
                case Relation::HAS_ONE:
                    $foreignKey = $target . '_id';
                    break;
                case Relation::HAS_MANY:
                case Relation::MANY_MANY:
                    $foreignKey = 'id';
                    break;
            }
        }
        if ($targetKey === null) {
            switch ($type) {
                case Relation::BELONGS_TO:
                case Relation::HAS_ONE:
                    $targetKey = 'id';
                    break;
                case Relation::HAS_MANY:
                case Relation::MANY_MANY:
                    $targetKey = $target . '_id';
                    break;
            }
        }
        $this->foreignKey = $foreignKey;
        $this->targetKey  = $targetKey;
    }
}
