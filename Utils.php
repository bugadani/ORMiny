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
     * Calls a callback in a transaction.
     * @param Manager $orm
     * @param callback $callback
     * @return mixed The value returned from the callback
     * @throws InvalidArgumentException
     */
    public static function inTransaction(Manager $orm, $callback)
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
        $return = call_user_func_array($callback, $params);
        $orm->connection->commit();
        return $return;
    }

    /**
     * Calls a callback safeguarded by a transaction that rolls back on errors.
     * @param Manager $orm
     * @param callback $callback
     * @return mixed The value returned from the callback
     * @throws InvalidArgumentException
     * @throws PDOException
     */
    public static function guardDB(Manager $orm, $callback)
    {
        try {
            return self::inTransaction($orm, $callback);
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
        $callback = function(array $rows)use($table) {
            foreach ($rows as $row) {
                $table->insert($row);
            }
        };
        self::inTransaction($table->manager, $callback, $rows);
    }

}
