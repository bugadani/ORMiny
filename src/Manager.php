<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use Miny\Log\Log;
use Modules\DBAL\Driver;
use Modules\DBAL\QueryBuilder;
use Modules\ORM\Parts\Table;
use Modules\ORM\Parts\TableDescriptor;
use OutOfBoundsException;

class Manager
{
    /**
     * @var Driver
     */
    public $connection;

    /**
     * @var iDatabaseDescriptor
     */
    private $database;

    /**
     * @var array
     */
    private $tables = array();

    /**
     * @var Log
     */
    private $log;

    /**
     * @param Driver              $connection
     * @param iDatabaseDescriptor $database
     * @param Log                 $log
     */
    public function __construct(
        Driver $connection,
        iDatabaseDescriptor $database = null,
        Log $log = null
    ) {
        $this->connection = $connection;
        $this->log        = $log;
        $this->database   = $database;

        if ($database === null) {
            return;
        }
        foreach ($database->getTableDescriptors() as $name => $descriptor) {
            $this->addTable($name, $descriptor);
        }
    }

    /**
     * @return Driver
     */
    public function getDriver()
    {
        return $this->connection;
    }

    /**
     * @return QueryBuilder
     */
    public function getQueryBuilder()
    {
        return $this->connection->getQueryBuilder();
    }

    /**
     * @param string $message
     */
    public function log($message)
    {
        if ($this->log === null) {
            return;
        }
        $args = array_slice(func_get_args(), 1);
        $this->log->write(Log::DEBUG, 'ORM', $message, $args);
    }

    /**
     * @param string          $name
     * @param TableDescriptor $table
     */
    public function addTable($name, TableDescriptor $table)
    {
        if ($name === null) {
            sscanf($table->name, $this->database->getTableNameFormat(), $name);
        }
        $this->tables[$name] = new Table($this, $table);
    }

    /**
     * @param string $name
     *
     * @return Table
     * @throws OutOfBoundsException
     */
    public function __get($name)
    {
        if (!isset($this->tables[$name])) {
            throw new OutOfBoundsException('Table does not exist: ' . $name);
        }

        return $this->tables[$name];
    }

    /**
     * @param string $table
     *
     * @return bool
     */
    public function __isset($table)
    {
        return isset($this->tables[$table]);
    }

    public function getTableNameFormat()
    {
        return $this->database->getTableNameFormat();
    }

    public function getForeignKeyFormat()
    {
        return $this->database->getForeignKeyFormat();
    }

}
