<?php

namespace ORMiny;

use DBTiny\Driver\Statement;
use Traversable;

class StatementIterator implements \IteratorAggregate
{
    private $statement;
    private $pkField;

    private $offset = 0;
    private $limit  = PHP_INT_MAX;

    public function __construct(Statement $statement, $pkField, $offset, $limit)
    {
        $this->statement = $statement;
        $this->pkField   = $pkField;
        $this->offset    = (int)$offset;
        if ($limit !== null) {
            $this->limit = (int)$limit;
        }
    }

    /**
     * Retrieve an external iterator
     *
     * @link  http://php.net/manual/en/iteratoraggregate.getiterator.php
     * @return Traversable An instance of an object implementing <b>Iterator</b> or
     *        <b>Traversable</b>
     * @since 5.0.0
     */
    public function getIterator()
    {
        if ($this->offset > 0) {
            $key   = null;
            $index = 0;
            //Skip the first N
            while (false !== ($record = $this->statement->fetch())) {
                if ($key === $record[ $this->pkField ]) {
                    continue;
                }
                $key = $record[ $this->pkField ];
                if ($index++ === $this->offset) {
                    break;
                }
            }
            $current = $record;
        } else {
            $current = $this->statement->fetch();
        }

        $currentKey = $current[ $this->pkField ];

        $fetchedRecordCount = 1;
        do {
            yield $currentKey => $current;

            $current = $this->statement->fetch();
            if (empty($current)) {
                break;
            }
            if ($currentKey !== $current[ $this->pkField ]) {
                $currentKey = $current[ $this->pkField ];
                $fetchedRecordCount++;
            }
        } while ($fetchedRecordCount <= $this->limit);

        $this->statement->closeCursor();
    }
}
