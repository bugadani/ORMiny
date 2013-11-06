<?php

namespace Modules\ORM;

use PDO as PHP_PDO;

class PDO extends PHP_PDO
{
    protected $transaction_count = 0;

    private function transactionActive()
    {
        return $this->transaction_count > 0;
    }

    private function reset()
    {
        $this->transaction_count = 0;
    }

    private function increment()
    {
        $this->transaction_count++;
    }

    private function decrement()
    {
        $this->transaction_count--;
        if ($this->transaction_count < 0) {
            $this->transaction_count = 0;
        }
    }

    public function beginTransaction()
    {
        if (!$this->transactionActive()) {
            $return = true;
        } else {
            $return = parent::beginTransaction();
        }

        $this->increment();
        return $return;
    }

    public function commit()
    {
        $this->decrement();
        if ($this->transactionActive()) {
            $return = true;
        } else {
            $return = parent::commit();
        }
        return $return;
    }

    public function rollback()
    {
        if ($this->transactionActive()) {
            $return = parent::rollback();
        } else {
            $return = true;
        }
        $this->reset();
        return $return;
    }

}
