<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use Miny\Application\BaseApplication;

class Module extends \Miny\Application\Module
{
    public function init(BaseApplication $app)
    {
        $app->add('pdo', __NAMESPACE__ . '\PDO')
                ->setArguments('@orm:pdo:dsn', '@orm:pdo:username', '@orm:pdo:password', '@orm:pdo:options');

        $orm = $app->add('orm', __NAMESPACE__ . '\Manager')
                ->setArguments('&pdo', '@orm:table_cache', '&log')
                ->setProperty('table_format', '@orm:table_name_format');
        if ($app['orm']['auto_discovery']) {
            $orm->addMethodCall('discover');
        }
    }

}
