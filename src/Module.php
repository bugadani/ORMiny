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
    public function getDependencies()
    {
        return array('DBAL');
    }

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
        $module    = $this;

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
