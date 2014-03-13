<?php

namespace Modules\ORM\Parts;

class SelectQueryBuilder
{
    private static $select_pattern = 'SELECT %s FROM %s';
    private static $table_name_pattern = '%1$s.%2$s as %3$s_%2$s';
    private static $join_pattern = ' LEFT JOIN %1$s ON (%1$s.%2$s = %3$s.%4$s%5$s)';

    private $selected_extra_fields = array();
    /**
     * @var Table
     */
    private $table;
    private $columns;
    private $lock;
    private $where;
    private $group;
    private $having;
    private $limit;
    private $with;
    private $order;

    /**
     * @param mixed $order
     */
    public function setOrder($order)
    {
        $this->order = $order;
    }

    /**
     * @return mixed
     */
    public function getOrder()
    {
        return $this->order;
    }

    /**
     * @param mixed $with
     */
    public function setWith($with)
    {
        $this->with = $with;
    }

    /**
     * @return mixed
     */
    public function getWith()
    {
        return $this->with;
    }

    /**
     * @param mixed $columns
     */
    public function setColumns($columns)
    {
        $this->columns = $columns;
    }

    /**
     * @return mixed
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * @param mixed $group
     */
    public function setGroup($group)
    {
        $this->group = $group;
    }

    /**
     * @return mixed
     */
    public function getGroup()
    {
        return $this->group;
    }

    /**
     * @param mixed $having
     */
    public function setHaving($having)
    {
        $this->having = $having;
    }

    /**
     * @return mixed
     */
    public function getHaving()
    {
        return $this->having;
    }

    /**
     * @param mixed $limit
     */
    public function setLimit($limit)
    {
        $this->limit = $limit;
    }

    /**
     * @return mixed
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * @param mixed $lock
     */
    public function setLock($lock)
    {
        $this->lock = $lock;
    }

    /**
     * @return mixed
     */
    public function getLock()
    {
        return $this->lock;
    }

    /**
     * @return mixed
     */
    public function getSelectedExtraFields()
    {
        return $this->selected_extra_fields;
    }

    /**
     * @param \Modules\ORM\Parts\Table $table
     */
    public function setTable($table)
    {
        $this->table = $table;
    }

    /**
     * @return \Modules\ORM\Parts\Table
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * @param mixed $where
     */
    public function setWhere($where)
    {
        $this->where = $where;
    }

    /**
     * @return mixed
     */
    public function getWhere()
    {
        return $this->where;
    }



    /**
     * @param $descriptor
     * @param $table
     * @param $table_id
     * @param $columns
     *
     * @return array
     */
    private function addColumnsFromRelatedTable(
        TableDescriptor $descriptor,
        $table,
        $table_id,
        $columns
    ) {
        foreach ($descriptor->fields as $related_field) {
            $columns[] = sprintf(
                self::$table_name_pattern,
                $table,
                $related_field,
                $table_id
            );
        }

        return $columns;
    }

    /**
     * @param Table $table
     * @param       $table_columns
     * @param       $table_name
     * @param       $descriptor
     *
     * @return array
     */
    private function addColumnsFromQueriedTable(
        Table $table,
        $table_columns,
        $table_name,
        $descriptor
    ) {
        $columns = array();
        foreach ($table_columns as $name) {
            if (strpos($name, '(') === false) {
                $columns[] = sprintf(
                    self::$table_name_pattern,
                    $table_name,
                    $name,
                    $descriptor->name
                );
            } else {
                $columns[] = $name;
            }
            if (!in_array($name, $table->descriptor->fields) && strpos($name, ' as ')) {
                list(, $alias) = explode(' as ', $name, 2);
                $this->selected_extra_fields[$alias] = $alias;
            }
        }

        return $columns;
    }

    /**
     * @param TableDescriptor $descriptor
     * @param                 $name
     * @param                 $table_join_field
     * @param                 $table_name
     * @param                 $primary_key
     * @param                 $table
     * @param                 $related_table
     * @param                 $related_primary
     * @param                 $foreign_key
     *
     * @return string
     */
    private function buildRelation(
        TableDescriptor $descriptor,
        $name,
        $table_join_field,
        $table_name,
        $primary_key,
        $table,
        $related_table,
        $related_primary,
        $foreign_key
    ) {
        if (is_array($name)) {
            $condition      = $name;
            $name           = array_shift($condition);
            $join_condition = sprintf(' AND (%s)', implode(') AND (', $condition));
        } else {
            $join_condition = '';
        }
        switch ($descriptor->getRelation($name)) {
            case TableDescriptor::RELATION_MANY_MANY:
                $join_table = $this->table->getJoinTable($name);

                $table .= sprintf(
                    self::$join_pattern,
                    $join_table,
                    $table_join_field,
                    $table_name,
                    $primary_key,
                    ''
                );
                $table .= sprintf(
                    self::$join_pattern,
                    $related_table,
                    $related_primary,
                    $join_table,
                    $foreign_key,
                    ''
                );
                break;
            case TableDescriptor::RELATION_HAS:
                $related_foreign = $this->table->getForeignKey($table_name);
                $table .= sprintf(
                    self::$join_pattern,
                    $related_table,
                    $related_foreign,
                    $table_name,
                    $primary_key,
                    $join_condition
                );
                break;
            case TableDescriptor::RELATION_BELONGS_TO:
                $table .= sprintf(
                    self::$join_pattern,
                    $related_table,
                    $related_primary,
                    $table_name,
                    $foreign_key,
                    $join_condition
                );
                break;
        }

        return $table;
    }

    public function get()
    {
        $table = $this->table->getTableName();
        if (!empty($this->with)) {
            $descriptor       = $this->table->descriptor;
            $table_name       = $table;
            $table_join_field = $this->table->getForeignKey($descriptor->name);
            $primary_key      = $descriptor->primary_key;

            $columns = $this->addColumnsFromQueriedTable(
                $this->table,
                $this->columns ? : $this->table->descriptor->fields,
                $table_name,
                $descriptor
            );

            foreach ($this->with as $name) {
                $related            = $this->table->getRelatedTable($name);
                $related_descriptor = $related->descriptor;
                $related_table      = $related->getTableName();
                $columns            = $this->addColumnsFromRelatedTable(
                    $related_descriptor,
                    $related_table,
                    $related_descriptor->name,
                    $columns
                );

                $table = $this->buildRelation(
                    $descriptor,
                    $name,
                    $table_join_field,
                    $table_name,
                    $primary_key,
                    $table,
                    $related_table,
                    $related_descriptor->primary_key,
                    $this->table->getForeignKey($name)
                );
            }
        } else {
            $columns = $this->columns ? : array('*');
        }
        $sql = sprintf(self::$select_pattern, implode(', ', $columns), $table);
        if (isset($this->where)) {
            $sql .= ' WHERE ' . $this->where;
        }
        if (isset($this->group)) {
            $sql .= ' GROUP BY ' . $this->group;
        }
        if (isset($this->having)) {
            $sql .= ' HAVING ' . $this->having;
        }
        if (isset($this->order)) {
            $sql .= ' ORDER BY ' . $this->order;
        }
        if (isset($this->limit) && empty($this->with)) {
            //Only set limit and offset if we don't query relations. If we do, we'll deal with them later
            $sql .= ' LIMIT ' . $this->limit;
            if (isset($this->offset) && $this->offset != 0) {
                $sql .= ' OFFSET ' . $this->offset;
            }
        }
        if ($this->lock) {
            $sql .= ' FOR UPDATE';
        }

        return $sql;
    }
}
