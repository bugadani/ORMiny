<?php

namespace ORMiny;

use DBTiny\AbstractQueryBuilder;

/**
 * Class PendingQuery
 *
 * @package ORMiny
 */
class PendingQuery
{
    const TYPE_INSERT = 0;
    const TYPE_UPDATE = 1;
    const TYPE_DELETE = 2;

    /**
     * @var Entity
     */
    public $entity;

    /**
     * @var int
     */
    public $type;

    /**
     * @var AbstractQueryBuilder
     */
    public $query;

    /**
     * @var array
     */
    public $parameters;

    /**
     * PendingQuery constructor.
     *
     * @param Entity               $entity
     * @param                      $type
     * @param AbstractQueryBuilder $query
     * @param array                $parameters
     */
    public function __construct(Entity $entity, $type, AbstractQueryBuilder $query, array $parameters = [])
    {
        $this->entity     = $entity;
        $this->type       = $type;
        $this->query      = $query;
        $this->parameters = $parameters;
    }

    public function execute()
    {
        $this->query->query($this->parameters);
    }
}