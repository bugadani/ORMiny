<?php
/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM;

use Modules\Cache\AbstractCacheDriver;
use Modules\DBAL\Driver;
use Modules\ORM\Parts\TableDescriptor;

/**
 * DatabaseDiscovery is a database structure discovery class.
 * It is able to discover an SQL database structure with table relationships.
 */
class DatabaseDiscovery implements iDatabaseDescriptor
{
    /**
     * @var PDO
     */
    public $connection;

    /**
     * @var string
     */
    public $table_format = '%s';

    /**
     * @var string
     */
    public $foreign_key = '%s_id';

    /**
     * @var int
     */
    public $cache_lifetime = 3600;

    /**
     * @var AbstractCacheDriver
     */
    private $cache;

    /**
     * @var TableDescriptor[]
     */
    private $tables;

    /**
     * @param Driver              $db
     * @param AbstractCacheDriver $cache
     */
    public function __construct(Driver $db, AbstractCacheDriver $cache = null)
    {
        $this->connection = $db;
        $this->cache      = $cache;
    }

    /**
     * @param TableDescriptor[] $descriptors
     */
    private function storeTables(array $descriptors)
    {
        if (is_null($this->cache)) {
            return;
        }
        $this->cache->store('orm.tables', $descriptors, $this->cache_lifetime);
    }

    /**
     * @return bool|TableDescriptor[]
     */
    private function loadTables()
    {
        if (!isset($this->cache)) {
            return false;
        }
        if (!$this->cache->has('orm.tables')) {
            return false;
        }

        return $this->cache->get('orm.tables');
    }

    /**
     * @param string            $name
     * @param TableDescriptor[] $descriptors
     */
    private function processManyManyRelation($name, array $descriptors)
    {
        if (strpos($name, '_') === false) {
            return;
        }
        $parts        = explode('_', $name);
        $parts_count  = count($parts);
        $joins_tables = false;
        switch ($parts_count) {
            case 1:
                //Well, no luck here. One table name part can't make two tables.
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
     * @return TableDescriptor[]
     */
    private function discover()
    {
        $descriptors = $this->loadTables();
        if ($descriptors !== false) {
            return $descriptors;
        }
        $table_names = array();
        $descriptors = array();

        $table_id = null;

        $platform          = $this->connection->getPlatform();
        $tableListingQuery = $platform->getTableListingQuery();
        foreach ($this->connection->fetchAll($tableListingQuery) as $name) {
            $name = current($name);
            sscanf($name, $this->getTableNameFormat(), $table_id);

            $table = new TableDescriptor($table_id);

            $descriptors[$table_id] = $table;
            $table_names[$table_id] = $name;
        }

        $foreign_pattern = '/' . str_replace('%s', '(.*?)', $this->getForeignKeyFormat()) . '/';

        foreach ($table_names as $referencing_table => $table_name) {
            $this->processManyManyRelation($referencing_table, $descriptors);
            $referencing = $descriptors[$referencing_table];

            $tableDescribingQuery = $platform->getTableDetailingQuery($table_name);
            foreach ($this->connection->fetchAll($tableDescribingQuery) as $field) {
                $referencing->fields[] = $field['Field'];
                if ($field['Key'] == 'PRI') {
                    //TODO: support multiple primary keys
                    $referencing->primary_key = $field['Field'];
                }

                $matches = array();
                if (preg_match($foreign_pattern, $field['Field'], $matches)) {
                    $referenced_table = $matches[1];
                    if (isset($descriptors[$referenced_table])) {
                        $referenced = $descriptors[$referenced_table];

                        $referencing->relations[$referenced_table] = TableDescriptor::RELATION_BELONGS_TO;
                        $referenced->relations[$referencing_table] = TableDescriptor::RELATION_HAS;
                    }
                }
            }
        }
        $this->storeTables($descriptors);

        return $descriptors;
    }

    public function getTableDescriptors()
    {
        if (!isset($this->tables)) {
            $this->tables = $this->discover();
        }

        return $this->tables;
    }

    public function getForeignKeyFormat()
    {
        return $this->foreign_key;
    }

    public function getTableNameFormat()
    {
        return $this->table_format;
    }

}
