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
            'database_descriptor' => __NAMESPACE__ . '\\DatabaseDiscovery',
            'table_name_format'   => 'miny_%s'
        );
    }


    public function init(BaseApplication $app)
    {
        $container = $app->getContainer();
        $module = $this;

        $container->addAlias(
            '\\PDO',
            __NAMESPACE__ . '\\PDO'
        );

        $container->addAlias(
            __NAMESPACE__ . '\\PDO',
            null,
            array(
                $this->getConfiguration('pdo:dsn'),
                $this->getConfiguration('pdo:username'),
                $this->getConfiguration('pdo:password'),
                $this->getConfiguration('pdo:options')
            )
        );

        $container->addCallback(
            __NAMESPACE__ . '\\DatabaseDiscovery',
            function (DatabaseDiscovery $discovery) use ($module) {
                $discovery->table_format = $module->getConfiguration('table_name_format');
            }
        );

        $container->addAlias(
            __NAMESPACE__ . '\\iDatabaseDescriptor',
            $module->getConfiguration('database_descriptor')
        );
    }
}
