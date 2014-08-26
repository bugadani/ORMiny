<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use Miny\Application\BaseApplication;

class Module extends \Miny\Modules\Module
{
    public function defaultConfiguration()
    {
        return [
            'entityMap' => []
        ];
    }

    public function getDependencies()
    {
        return ['Annotation', 'DBAL'];
    }

    public function init(BaseApplication $app)
    {
        $entityManager = $app->getContainer()->get(__NAMESPACE__ . '\\EntityManager');
        foreach ($this->getConfiguration('entityMap') as $entityName => $className) {
            $entityManager->register($entityName, $className);
        }
    }
}
