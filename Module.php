<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use Miny\Application\Application;

class Module extends \Miny\Application\Module
{
    public function init(Application $app, $source = '&db', $cache = NULL, $auto_discover = true)
    {
        $orm = $app->add('orm', __NAMESPACE__ . '\Manager')->setArguments($source, $cache);
        if ($auto_discover) {
            $orm->addMethodCall('discover');
        }
    }

}