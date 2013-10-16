<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use Modules\Cache\iCacheDriver;
use Modules\ORM\Parts\Table;
use Modules\ORM\Parts\TableDescriptor;
use OutOfBoundsException;
use PDO;

class Manager
{
    public $table_format = '%s';
    public $foreign_key = '%s_id';
    public $connection;
    public $cache_lifetime = 3600;
    private $tables = array();
    private $cache;

    /**
     *
     * @param PDO $connection
     * @param \Modules\Cache\iCacheDriver $cache
     */
    public function __construct(PDO $connection, iCacheDriver $cache = NULL)
    {
        $this->connection = $connection;
        $this->cache = $cache;
    }

    /**
     *
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
        if ($parts_count == 2) {
            if (isset($descriptors[$parts[0]], $descriptors[$parts[1]])) {
                $joins_tables = array($parts[0], $parts[1]);
            }
        } else {
            for ($i = 1; $i < $parts_count; ++$i) {
                $table1 = implode('_', array_slice($parts, 0, $i));
                if (isset($descriptors[$table1])) {
                    $table2 = implode('_', array_slice($parts, $i));
                    if (isset($descriptors[$table2])) {
                        $joins_tables = array($table1, $table2);
                        break;
                    }
                }
            }
        }
        if ($joins_tables) {
            list($table1, $table2) = $joins_tables;

            $descriptors[$table1]->relations[$table2] = TableDescriptor::RELATION_MANY_MANY;
            $descriptors[$table2]->relations[$table1] = TableDescriptor::RELATION_MANY_MANY;
        }
    }

    public function discover()
    {
        if (!is_null($this->cache) && $this->cache->has('orm.tables')) {
            $descriptors = $this->cache->get('orm.tables');
        } else {
            $tables = $this->connection->query('SHOW TABLES')->fetchAll();
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

            $foreign_pattern = '/' . str_replace('%s', '(.*)', $this->foreign_key) . '/';

            foreach ($table_ids as $name => $table_name) {
                $this->processManyManyRelation($name, $descriptors);
                $stmt = $this->connection->query('DESCRIBE ' . $table_name);
                $td = $descriptors[$name];

                foreach ($stmt->fetchAll() as $field) {
                    $td->fields[] = $field['Field'];
                    if ($field['Key'] == 'PRI') {
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
            if (!is_null($this->cache)) {
                $this->cache->store('orm.tables', $descriptors, $this->cache_lifetime);
            }
        }
        foreach ($descriptors as $name => $td) {
            $this->addTable($td, $name);
        }
    }

    /**
     *
     * @param \Modules\ORM\Parts\TableDescriptor $table
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
     *
     * @param string $table
     * @return  \Modules\ORM\Parts\TableDescriptor
     * @throws OutOfBoundsException
     */
    public function __get($table)
    {
        if (!isset($this->tables[$table])) {
            throw new OutOfBoundsException('Table not exists: ' . $table);
        }
        return $this->tables[$table];
    }

}
