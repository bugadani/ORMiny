<?php

namespace ORMiny;

use Modules\DBAL\Driver\Statement;

class StatementIterator implements \Iterator
{
    private $statement;
    private $pkField;

    private $currentKey;
    private $currentRecord;

    private $fetchedRecordCount = 0;

    private $offset = 0;
    private $limit  = PHP_INT_MAX;

    private $iterationStarted = false;
    private $cursorClosed     = false;

    public function __construct(Statement $statement, $pkField, $offset, $limit)
    {
        $this->statement = $statement;
        $this->pkField   = $pkField;
        $this->offset    = (int)$offset;
        if ($limit !== null) {
            $this->limit = (int)$limit;
        }
    }

    public function current()
    {
        return $this->currentRecord;
    }

    public function next()
    {
        $this->currentRecord = $this->statement->fetch();
        if (!empty($this->currentRecord)) {
            $recordKey = $this->currentRecord[ $this->pkField ];
            if ($recordKey !== $this->currentKey) {
                $this->currentKey = $recordKey;
                $this->fetchedRecordCount++;
            }
        }
    }

    public function key()
    {
        return $this->currentKey;
    }

    public function valid()
    {
        if (!empty($this->currentRecord) && $this->fetchedRecordCount <= $this->limit) {
            return true;
        }

        if (!$this->cursorClosed) {
            $this->statement->closeCursor();
            $this->cursorClosed = true;
        }

        return false;
    }

    /**
     * StatementIterator cannot be rewound
     */
    public function rewind()
    {
        if ($this->iterationStarted) {
            return;
        }
        $this->iterationStarted = true;

        if ($this->offset > 0) {
            $key   = null;
            $index = 0;
            //Skip the first N
            while ($record = $this->statement->fetch()) {
                if ($key === $record[ $this->pkField ]) {
                    continue;
                }
                $key = $record[ $this->pkField ];
                if ($index++ === $this->offset) {
                    break;
                }
            }
            $this->currentRecord = $record;
        } else {
            $this->currentRecord = $this->statement->fetch();
        }
        $this->fetchedRecordCount = 1;
    }
}
