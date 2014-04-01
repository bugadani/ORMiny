<?php

/**
 * This file is part of the Miny framework.
 * (c) Dániel Buga <daniel@bugadani.hu>
 *
 * For licensing information see the LICENSE file.
 */

namespace Modules\ORM\Parts;

use Modules\DBAL\Driver\Statement;
use PDO;

class ResultProcessor
{
    /**
     * @var Table
     */
    private $table;
    private $selectedExtraFields = array();
    private $limit;
    private $offset;

    public function __construct(Table $table)
    {
        $this->table = $table;
    }

    public function setExtraFieldsForMainTable(array $fields)
    {
        $this->selectedExtraFields = $fields;
    }

    public function setLimits($limit, $offset = null)
    {
        $this->limit  = $limit;
        $this->offset = $offset;
    }

    /**
     * @param Statement $statement
     * @param array     $related
     *
     * @return array|bool|Row
     */
    public function processResults(Statement $statement, array $related = array())
    {
        if (!empty($related)) {
            return $this->processResultsWithRelatedRecords($statement, $related);
        }
        $rows   = $statement->fetchAll();
        $this->table->manager->log('Results: %d', count($rows));

        if (isset($this->limit) && $this->limit === 1) {
            if (empty($rows)) {
                return false;
            }

            return new Row($this->table, current($rows));
        }
        $return  = array();
        $pkField = $this->table->getPrimaryKey();
        foreach ($rows as $row) {
            $record = new Row($this->table, $row);
            if (isset($row[$pkField])) {
                $return[$row[$pkField]] = $record;
            } else {
                $return[] = $record;
            }
        }

        return $return;
    }

    /**
     * @param Statement $statement
     * @param array     $related
     *
     * @return array
     */
    public function processResultsWithRelatedRecords(Statement $statement, array $related = array())
    {
        $descriptor = $this->table->descriptor;
        $table      = $descriptor->name;

        $table_fields = array();
        foreach ($descriptor->fields as $name) {
            $table_fields[$name] = $table . '_' . $name;
        }

        $relation_data     = $this->getRelationData($descriptor, $related);
        $pk_field          = $descriptor->primary_key;
        $records           = array();
        $last_pk           = null;
        $relation_last_pks = array();
        $row_num           = 0;
        $fetched           = 0;

        $query_fields   = array_merge($table_fields, $this->selectedExtraFields);
        $table_pk_field = $table_fields[$pk_field];
        $row_skipped    = false;

        //We fetch rows one-by-one because MANY_MANY relation type cannot be limited by LIMIT
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if ($last_pk !== $row[$table_pk_field]) {
                $last_pk = $row[$table_pk_field];

                if (isset($this->offset)) {
                    $row_skipped = ($row_num++ < $this->offset);
                }
                if ($row_skipped) {
                    continue;
                }
                if (isset($this->limit) && $fetched++ == $this->limit) {
                    break;
                }
                $records[$last_pk] = new Row($this->table, $this->getFieldsFromRow(
                    $row,
                    $query_fields
                ));
                $relation_last_pks = array();
            } elseif ($row_skipped) {
                continue;
            }

            $relation_last_pks = $this->processRelatedRecords(
                $related,
                $relation_data,
                $records[$last_pk],
                $row,
                $relation_last_pks
            );
        }
        $statement->closeCursor();
        $this->table->manager->log('Results: %d', count($records));
        if (isset($this->limit) && $this->limit === 1) {
            $records = current($records);
        }

        return $records;
    }

    /**
     * @param array $related
     * @param       $relation_data
     * @param       $return
     * @param       $row
     * @param       $relation_last_pks
     *
     * @return mixed
     */
    private function processRelatedRecords(
        array $related,
        $relation_data,
        $return,
        $row,
        $relation_last_pks
    ) {
        foreach ($related as $name) {
            if (is_array($name)) {
                $name = $name[0];
            }
            $relation_type     = $relation_data[$name]['type'];
            $relation_table    = $relation_data[$name]['table'];
            $relation_pk_alias = $relation_data[$name]['primary_key_alias'];
            $relation_fields   = $relation_data[$name]['fields'];

            if ($relation_type !== TableDescriptor::RELATION_BELONGS_TO) {
                if (!isset($return->$name)) {
                    $return->$name = array();
                }
            }

            if ($row[$relation_pk_alias]) {
                $relation_pk_value = $row[$relation_pk_alias];
            } else {
                continue;
            }

            if (isset($relation_last_pks[$name]) && $relation_last_pks[$name] == $relation_pk_value) {
                //This row is present multiple times and we have already processed it.
                continue;
            }

            $relation_last_pks[$name] = $relation_pk_value;
            $relation_row             = new Row($relation_table, $this->getFieldsFromRow(
                $row,
                $relation_fields
            ));
            if ($relation_type == TableDescriptor::RELATION_BELONGS_TO) {
                $return->$name = $relation_row;
            } else {
                $var                     = & $return->$name;
                $var[$relation_pk_value] = $relation_row;
            }
        }

        return $relation_last_pks;
    }

    /**
     * @param TableDescriptor $descriptor
     * @param array           $related
     *
     * @return array
     */
    private function getRelationData(TableDescriptor $descriptor, array $related)
    {
        $relation_data = array();
        foreach ($related as $name) {
            if (is_array($name)) {
                $name = $name[0];
            }
            $relation_table = $this->table->getRelatedTable($name);

            $fields = array();
            $data   = array(
                'table' => $relation_table,
                'type'  => $descriptor->getRelation($name),
            );
            foreach ($relation_table->descriptor->fields as $field) {
                $fields[$field] = $name . '_' . $field;
            }
            $primaryKey                = $relation_table->getPrimaryKey();
            $data['primary_key_alias'] = $fields[$primaryKey];
            $data['fields']            = $fields;
            $relation_data[$name]      = $data;
        }

        return $relation_data;
    }

    /**
     * @param array $row
     * @param array $fields
     *
     * @return array
     */
    private function getFieldsFromRow(array $row, array $fields)
    {
        $rowdata = array();
        foreach ($fields as $field => $alias) {
            if (isset($row[$alias])) {
                $rowdata[$field] = $row[$alias];
            }
        }

        return $rowdata;
    }
}
