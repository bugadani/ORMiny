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

    public function init(BaseApplication $app)
    {
        $factory = $app->getFactory();
        $factory->add('pdo', __NAMESPACE__ . '\PDO')
                ->setArguments('@orm:pdo:dsn', '@orm:pdo:username', '@orm:pdo:password', '@orm:pdo:options');

        $factory->add('db_auto_discovery', __NAMESPACE__ . '\DatabaseDiscovery')
                ->setArguments('&pdo', '@orm:table_cache')
                ->setProperty('table_format', '@orm:table_name_format');

        $factory->add('orm', __NAMESPACE__ . '\Manager')
                ->setArguments('&pdo', '@orm:database', '&log');
    }
}
