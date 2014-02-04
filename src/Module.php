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
        return array(
            'orm' => array(
                'database_descriptor' => __NAMESPACE__ . '\\DatabaseDiscovery',
                'table_name_format'   => 'miny_%s'
            )
        );
    }


    public function init(BaseApplication $app)
    {
        $container  = $app->getContainer();
        $parameters = $app->getParameterContainer();

        $container->addAlias(
            '\\PDO',
            __NAMESPACE__ . '\\PDO',
            array(
                '@orm:pdo:dsn',
                '@orm:pdo:username',
                '@orm:pdo:password',
                '@orm:pdo:options'
            )
        );

        $container->addCallback(
            __NAMESPACE__ . '\\DatabaseDiscovery',
            function (DatabaseDiscovery $discovery) use ($parameters) {
                $discovery->table_format = $parameters['orm']['table_name_format'];
            }
        );

        $container->addAlias(__NAMESPACE__ . '\\iDatabaseDescriptor', $parameters['orm']['database_descriptor']);
    }
}
