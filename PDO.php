<?php

namespace Modules\ORM;

use PDO as PHP_PDO;

class PDO extends PHP_PDO
{
    protected $transaction_count = 0;

    public function beginTransaction()
    {
        $return = true;
        if ($this->transaction_count == 0) {
            $return = parent::beginTransaction();
        }

        $this->transaction_count++;
        return $return;
    }

    public function commit()
    {
        $return = true;
        $this->transaction_count--;
        if ($this->transaction_count == 0) {
            $return = parent::commit();
        } else if ($this->transaction_count < 0) {
            $this->transaction_count = 0;
        }
        return $return;
    }

    public function rollBack()
    {
        $return = true;
        if ($this->transaction_count > 0) {
            $return = parent::rollBack();
        }
        $this->transaction_count = 0;
        return $return;
    }

}
