<?php


namespace pfilsx\db_broker\mutex;


use Exception;
use PDO;

class OracleMutex extends Mutex
{
    /** available lock modes */
    const MODE_X = 'X_MODE';
    const MODE_NL = 'NL_MODE';
    const MODE_S = 'S_MODE';
    const MODE_SX = 'SX_MODE';
    const MODE_SS = 'SS_MODE';
    const MODE_SSX = 'SSX_MODE';
    /**
     * @var string lock mode to be used.
     * @see http://docs.oracle.com/cd/B19306_01/appdev.102/b14258/d_lock.htm#CHDBCFDI
     */
    public $lockMode = self::MODE_X;
    /**
     * @var bool whether to release lock on commit.
     */
    public $releaseOnCommit = false;

    /**
     * Initializes Oracle specific mutex component implementation.
     * @throws Exception if [[db]] is not Oracle connection.
     */
    public function init()
    {
        parent::init();
        if (strncmp($this->db->driverName, 'oci', 3) !== 0 && strncmp($this->db->driverName, 'odbc', 4) !== 0) {
            throw new Exception('In order to use OracleMutex connection must be configured to use Oracle database.');
        }
    }
    /**
     * Acquires lock by given name.
     * @see http://docs.oracle.com/cd/B19306_01/appdev.102/b14258/d_lock.htm
     * @param string $name of the lock to be acquired.
     * @param int $timeout time (in seconds) to wait for lock to become released.
     * @return bool acquiring result.
     */
    protected function acquireLock($name, $timeout = 0)
    {
        $lockStatus = null;
        // clean vars before using
        $releaseOnCommit = $this->releaseOnCommit ? 'TRUE' : 'FALSE';
        $timeout = abs((int) $timeout);
        // inside pl/sql scopes pdo binding not working correctly :(
        $this->db->createCommand(
            'DECLARE
                    handle VARCHAR2(128);
                BEGIN
                    DBMS_LOCK.ALLOCATE_UNIQUE(:name, handle);
                    :lockStatus := DBMS_LOCK.REQUEST(handle, DBMS_LOCK.' . $this->lockMode . ', ' . $timeout . ', ' . $releaseOnCommit . ');
                END;',
            [':name' => $name]
        )
        ->bindParam(':lockStatus', $lockStatus, PDO::PARAM_INT, 1)
        ->execute();
        return $lockStatus === 0 || $lockStatus === '0';
    }
    /**
     * Releases lock by given name.
     * @param string $name of the lock to be released.
     * @return bool release result.
     * @see http://docs.oracle.com/cd/B19306_01/appdev.102/b14258/d_lock.htm
     */
    protected function releaseLock($name)
    {
        $releaseStatus = null;
        $this->db->createCommand(
            'DECLARE
                    handle VARCHAR2(128);
                BEGIN
                    DBMS_LOCK.ALLOCATE_UNIQUE(:name, handle);
                    :result := DBMS_LOCK.RELEASE(handle);
                END;',
            [':name' => $name]
        )
        ->bindParam(':result', $releaseStatus, PDO::PARAM_INT, 1)
        ->execute();
        return $releaseStatus === 0 || $releaseStatus === '0';
    }
}