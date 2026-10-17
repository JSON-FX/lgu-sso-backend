<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use PDO;

class SsoDatabaseBounds
{
    public function run(Closure $operation): mixed
    {
        $connection = config('database.default');
        $options = config('database.connections.'.$connection.'.options', []);
        config(['database.connections.'.$connection.'.options' => array_replace($options, [PDO::ATTR_TIMEOUT => 2])]);
        $db = DB::connection();
        $previous = null;
        try {
            if ($db->getDriverName() === 'mysql') {
                $previous = $db->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS row_wait, @@SESSION.lock_wait_timeout AS metadata_wait, @@SESSION.max_execution_time AS statement_ms');
                $db->statement('SET SESSION innodb_lock_wait_timeout = 1, lock_wait_timeout = 1, max_execution_time = 1000');
            } elseif (! app()->runningUnitTests()) {
                throw new SsoCapacityException;
            }

            return $operation();
        } finally {
            config(['database.connections.'.$connection.'.options' => $options]);
            if ($previous !== null) {
                $db->statement('SET SESSION innodb_lock_wait_timeout = '.(int) $previous->row_wait
                    .', lock_wait_timeout = '.(int) $previous->metadata_wait.', max_execution_time = '.(int) $previous->statement_ms);
            }
        }
    }
}
