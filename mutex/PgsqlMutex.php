<?php


namespace pfilsx\db_broker\mutex;


use Closure;
use Exception;

class PgsqlMutex extends Mutex
{

    /**
     * @var int Number of milliseconds between each try in [[acquire()]] until specified timeout times out.
     * By default it is 50 milliseconds - it means that [[acquire()]] may try acquire lock up to 20 times per second.
     */
    public $retryDelay = 50;
    /**
     * Initializes PgSQL specific mutex component implementation.
     * @throws Exception if [[db]] is not PgSQL connection.
     */
    public function init()
    {
        parent::init();
        if ($this->db->driverName !== 'pgsql') {
            throw new Exception('In order to use PgsqlMutex connection must be configured to use PgSQL database.');
        }
    }
    /**
     * Converts a string into two 16 bit integer keys using the SHA1 hash function.
     * @param string $name
     * @return array contains two 16 bit integer keys
     */
    private function getKeysFromName($name)
    {
        return array_values(unpack('n2', sha1($name, true)));
    }
    /**
     * Acquires lock by given name.
     * @param string $name of the lock to be acquired.
     * @param int $timeout time (in seconds) to wait for lock to become released.
     * @return bool acquiring result.
     * @see http://www.postgresql.org/docs/9.0/static/functions-admin.html
     */
    protected function acquireLock($name, $timeout = 0)
    {
        list($key1, $key2) = $this->getKeysFromName($name);
        return $this->retryAcquire($timeout, function () use ($key1, $key2) {
            return (bool)$this->db->createCommand(
                'SELECT pg_try_advisory_lock(:key1, :key2)',
                [':key1' => $key1, ':key2' => $key2]
            )->queryScalar();
        });
    }
    /**
     * Releases lock by given name.
     * @param string $name of the lock to be released.
     * @return bool release result.
     * @see http://www.postgresql.org/docs/9.0/static/functions-admin.html
     */
    protected function releaseLock($name)
    {
        list($key1, $key2) = $this->getKeysFromName($name);
        return (bool)$this->db->createCommand(
            'SELECT pg_advisory_unlock(:key1, :key2)',
            [':key1' => $key1, ':key2' => $key2]
        )->queryScalar();
    }

    private function retryAcquire($timeout, Closure $callback)
    {
        $start = microtime(true);
        do {
            if ($callback()) {
                return true;
            }
            usleep($this->retryDelay * 1000);
        } while (microtime(true) - $start < $timeout);
        return false;
    }
}