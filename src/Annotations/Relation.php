<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM\Annotations;

/**
 * @Annotation
 * @DefaultAttribute name
 * @Attribute('name', type: 'string', required: true)
 * @Attribute('type', type: @Enum({'has one', 'has many', 'belongs to', 'many to many'}), required: true)
 * @Attribute('target', type: 'string', required: true)
 * @Attribute('foreignKey', type: 'string', required: true)
 * @Attribute('targetKey', type: 'string', required: true)
 * @Target('property')
 */
class Relation
{
    const HAS_ONE = 'has one';
    const HAS_MANY = 'has many';
    const BELONGS_TO = 'belongs to';
    const MANY_MANY = 'many to many';

    public $name;
    public $type;
    public $target;
    public $foreignKey;
    public $targetKey;
}
