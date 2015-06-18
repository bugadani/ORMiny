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

    public function __construct(Statement $statement, $pkField)
    {
        $this->statement = $statement;
        $this->pkField   = $pkField;
    }

    public function setOffset($offset)
    {
        $this->offset = (int)$offset;
    }

    public function setLimit($limit)
    {
        $this->limit = (int)$limit;
    }

    public function current()
    {
        return $this->currentRecord;
    }

    public function next()
    {
        if ($this->fetchedRecordCount > $this->limit) {
            return;
        }

        $record = $this->statement->fetch();
        if (empty($record)) {
            $this->currentRecord = null;

            return;
        }

        if ($record[ $this->pkField ] !== $this->currentKey) {

            if ($this->fetchedRecordCount === $this->limit) {
                $record = null;
            } else {
                $this->currentKey = $record[ $this->pkField ];
            }

            $this->fetchedRecordCount++;
        }

        $this->currentRecord = $record;
    }

    public function key()
    {
        return $this->currentKey;
    }

    public function valid()
    {
        if (!empty($this->currentRecord)) {
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
        if (!empty($this->currentRecord)) {
            $this->currentKey         = $this->currentRecord[ $this->pkField ];
            $this->fetchedRecordCount = 1;
        }
    }
}
