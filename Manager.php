<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use Miny\Log;
use Modules\Cache\iCacheDriver;
use Modules\ORM\Parts\Table;
use Modules\ORM\Parts\TableDescriptor;
use OutOfBoundsException;
use PDO;

class Manager
{
    /**
     * @var string
     */
    public $table_format = '%s';

    /**
     * @var string
     */
    public $foreign_key = '%s_id';

    /**
     * @var PDO
     */
    public $connection;

    /**
     * @var int
     */
    public $cache_lifetime = 3600;

    /**
     * @var array
     */
    private $tables = array();

    /**
     * @var iCacheDriver
     */
    private $cache;

    /**
     * @var Log
     */
    private $log;

    /**
     * @param PDO $connection
     * @param iCacheDriver $cache
     * @param Log $log
     */
    public function __construct(PDO $connection, iCacheDriver $cache = NULL, Log $log = NULL)
    {
        $this->connection = $connection;
        $this->cache = $cache;
        $this->log = $log;
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
     * @param array $descriptors
     */
    private function processManyManyRelation($name, array $descriptors)
    {
        if (strpos($name, '_') === false) {
            return;
        }
        $parts = explode('_', $name);
        $parts_count = count($parts);
        $joins_tables = false;
        switch ($parts_count) {
            case 1:
                //Well, no luch here. One table name part can't make two tables.
                return;
            case 2:
                //If the 2 parts represent existing table names use them
                if (isset($descriptors[$parts[0]], $descriptors[$parts[1]])) {
                    $joins_tables = array($parts[0], $parts[1]);
                }
                break;
            default:
                //We're iterating through the table name parts
                for ($i = 1; $i < $parts_count; ++$i) {
                    $table1 = implode('_', array_slice($parts, 0, $i));
                    //If we've found an existing table let's assume it is one of the related ones
                    if (isset($descriptors[$table1])) {
                        //Check if the other table exists
                        $table2 = implode('_', array_slice($parts, $i));
                        if (isset($descriptors[$table2])) {
                            //If the other table is indeed an existing one,
                            //we can assume it is the other related table
                            $joins_tables = array($table1, $table2);
                            break;
                        }
                    }
                }
                break;
        }
        if ($joins_tables) {
            list($table1, $table2) = $joins_tables;

            $descriptors[$table1]->relations[$table2] = TableDescriptor::RELATION_MANY_MANY;
            $descriptors[$table2]->relations[$table1] = TableDescriptor::RELATION_MANY_MANY;
        }
    }

    /**
     * Discover database structure.
     */
    public function discover()
    {
        $descriptors = $this->loadTables();
        if ($descriptors === false) {
            $pdo = $this->connection;
            $tables = $pdo->query('SHOW TABLES')->fetchAll();
            $table_ids = array();
            $descriptors = array();
            foreach ($tables as $name) {
                $name = current($name);
                list($id) = sscanf($name, $this->table_format);
                $td = new TableDescriptor;
                $td->name = $id;
                $descriptors[$id] = $td;
                $table_ids[$id] = $name;
            }

            $foreign_pattern = '/' . str_replace('%s', '(.*?)', $this->foreign_key) . '/';

            foreach ($table_ids as $name => $table_name) {
                $this->processManyManyRelation($name, $descriptors);
                $stmt = $pdo->query('DESCRIBE ' . $table_name);
                $td = $descriptors[$name];

                foreach ($stmt->fetchAll() as $field) {
                    $td->fields[] = $field['Field'];
                    if ($field['Key'] == 'PRI') {
                        //TODO: support multiple primary keys
                        $td->primary_key = $field['Field'];
                    }

                    $matches = array();
                    if (preg_match($foreign_pattern, $field['Field'], $matches)) {
                        $referenced_table = $matches[1];
                        $referencing_table = $name;
                        if (isset($descriptors[$referenced_table])) {
                            $referenced = $descriptors[$referenced_table];
                            $referencing = $descriptors[$referencing_table];

                            $referencing->relations[$referenced_table] = TableDescriptor::RELATION_BELONGS_TO;
                            $referenced->relations[$referencing_table] = TableDescriptor::RELATION_HAS;
                        }
                    }
                }
            }
            $this->storeTables($descriptors);
            foreach ($descriptors as $td) {
                $this->log('Table discovered: ' . $table_name . "\n\t" . $td);
            }
        }
        foreach ($descriptors as $name => $td) {
            $this->addTable($td, $name);
        }
    }

    /**
     * @param array $descriptors
     */
    private function storeTables(array $descriptors)
    {
        if (is_null($this->cache)) {
            return;
        }
        $this->cache->store('orm.tables', $descriptors, $this->cache_lifetime);
    }

    /**
     * @return bool|array
     */
    private function loadTables()
    {
        if (is_null($this->cache)) {
            return false;
        }
        if (!$this->cache->has('orm.tables')) {
            return false;
        }
        return $this->cache->get('orm.tables');
    }

    /**
     * @param TableDescriptor $table
     * @param string $name
     */
    public function addTable(TableDescriptor $table, $name = NULL)
    {
        if (is_null($name)) {
            list($name) = sscanf($table->name, $this->table_format);
        }
        $this->tables[$name] = new Table($this, $table);
    }

    /**
     * @param string $table
     * @return TableDescriptor
     * @throws OutOfBoundsException
     */
    public function __get($table)
    {
        if (!isset($this->tables[$table])) {
            throw new OutOfBoundsException('Table not exists: ' . $table);
        }
        return $this->tables[$table];
    }

    /**
     * @param string $table
     * @return bool
     */
    public function __isset($table)
    {
        return isset($this->tables[$table]);
    }

}
