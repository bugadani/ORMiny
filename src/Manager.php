<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use Miny\Log;
use Modules\ORM\Parts\Table;
use Modules\ORM\Parts\TableDescriptor;
use OutOfBoundsException;

class Manager
{
    /**
     * @var PDO
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
     * @param PDO $connection
     * @param iDatabaseDescriptor $database
     * @param Log $log
     */
    public function __construct(PDO $connection, iDatabaseDescriptor $database = NULL, Log $log = NULL)
    {
        $this->connection = $connection;
        $this->log = $log;
        $this->database = $database;

        if ($database !== null) {
            foreach ($database->getTableDescriptors() as $name => $descriptor) {
                $this->__set($name, $descriptor);
            }
        }
    }

    /**
     * @param string $message
     */
    public function log($message)
    {
        if ($this->log !== NULL) {
            $this->log->write('ORM: ' . $message, Log::DEBUG);
        }
    }

    /**
     * @param string $name
     * @param TableDescriptor $table
     */
    public function __set($name, TableDescriptor $table)
    {
        if ($name === NULL) {
            sscanf($table->name, $this->database->getTableNameFormat(), $name);
        }
        $this->tables[$name] = new Table($this, $table);
    }

    /**
     * @param string $name
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
