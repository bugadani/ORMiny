<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use InvalidArgumentException;
use Modules\ORM\Parts\Table;
use PDOException;

class Utils
{
    /**
     * Calls a callable safeguarded by a transaction that rolls back on errors.
     * @param Manager $orm
     * @param callback $callback
     * @return mixed The value returned from the callback
     * @throws InvalidArgumentException
     * @throws PDOException
     */
    public static function guardDB(Manager $orm, $callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Callback is not callable.');
        }
        $params = func_get_args();
        array_shift($params);
        array_shift($params);
        if (is_array($params[0])) {
            $params = $params[0];
        }
        $orm->connection->beginTransaction();
        try {
            $return = call_user_func_array($callback, $params);
            $orm->connection->commit();
            return $return;
        } catch (PDOException $e) {
            $orm->connection->rollback();
            throw $e;
        }
    }

    /**
     * @param Table $table
     * @param array $rows
     */
    public static function batchInsert(Table $table, array $rows)
    {
        $orm = $table->manager;

        self::guardDB($orm, function(array $rows)use($table) {
            foreach ($rows as $row) {
                $table->insert($row);
            }
        }, $rows);
    }

}
