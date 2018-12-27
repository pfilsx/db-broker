<?php


namespace pfilsx\db_broker;


use Exception;
use PDO;
use PDOException;
use Throwable;

/**
 * Class Connection
 * @package db_broker
 *
 * @property string $driverName Name of the DB driver.
 * @property bool $isActive Whether the DB connection is established. This property is read-only.
 * @property string $lastInsertID The row ID of the last row inserted, or the last value retrieved from the
 * sequence object. This property is read-only.
 * @property QueryBuilder $queryBuilder The query builder for the current DB connection. This property is
 * read-only.
 * @property Schema $schema The schema information for the database opened by this connection. This property
 * is read-only.
 */
class Connection extends BaseObject
{
    public $dsn;
    /**
     * @var string the username for establishing DB connection. Defaults to `null` meaning no username to use.
     */
    public $username;
    /**
     * @var string the password for establishing DB connection. Defaults to `null` meaning no password to use.
     */
    public $password;
    /**
     * @var array PDO attributes (name => value) that should be set when calling [[open()]]
     * to establish a DB connection. Please refer to the
     * [PHP manual](http://php.net/manual/en/pdo.setattribute.php) for
     * details about available attributes.
     */
    public $attributes;
    /**
     * @var PDO the PHP PDO instance associated with this DB connection.
     * This property is mainly managed by [[open()]] and [[close()]] methods.
     * When a DB connection is active, this property will represent a PDO instance;
     * otherwise, it will be null.
     * @see pdoClass
     */
    public $pdo;
    /**
     * @var string the charset used for database connection. The property is only used
     * for MySQL and PostgreSQL. Defaults to null, meaning using default charset
     * as configured by the database.
     *
     * For Oracle Database, the charset must be specified in the [[dsn]], for example for UTF-8 by appending `;charset=UTF-8`
     * to the DSN string.
     *
     * The same applies for if you're using GBK or BIG5 charset with MySQL, then it's highly recommended to
     * specify charset via [[dsn]] like `'mysql:dbname=mydatabase;host=127.0.0.1;charset=GBK;'`.
     */
    public $charset;
    /**
     * @var bool whether to turn on prepare emulation. Defaults to false, meaning PDO
     * will use the native prepare support if available. For some databases (such as MySQL),
     * this may need to be set true so that PDO can emulate the prepare support to bypass
     * the buggy native prepare support.
     * The default value is null, which means the PDO ATTR_EMULATE_PREPARES value will not be changed.
     */
    public $emulatePrepare;

    /**
     * @var Transaction the currently active transaction
     */
    private $_transaction;
    /**
     * @var string the common prefix or suffix for table names. If a table name is given
     * as `{{%TableName}}`, then the percentage character `%` will be replaced with this
     * property value. For example, `{{%post}}` becomes `{{tbl_post}}`.
     */
    public $tablePrefix = '';
    /**
     * @var array mapping between PDO driver names and [[Schema]] classes.
     */
    public $schemaMap = [
        'pgsql' => 'pfilsx\db_broker\pgsql\Schema', // PostgreSQL
        'mysqli' => 'pfilsx\db_broker\mysql\Schema', // MySQL
        'mysql' => 'pfilsx\db_broker\mysql\Schema', // MySQL
        'oci' => 'pfilsx\db_broker\oci\Schema', // Oracle driver
        'oci8' => 'pfilsx\db_broker\oci\Schema', // Oracle driver
    ];
    /**
     * @var string Custom PDO wrapper class. If not set, it will use [[PDO]].
     * @see pdo
     */
    public $pdoClass;
    /**
     * @var string the class used to create new database [[Command]] objects. If you want to extend the [[Command]] class,
     * you may configure this property to use your extended version of the class.
     * @see createCommand
     */
    public $commandClass = 'pfilsx\db_broker\Command';
    /**
     * @var int the retry interval in seconds for dead servers listed in [[masters]] and [[slaves]].
     * This is used together with [[serverStatusCache]].
     */
    public $serverRetryInterval = 600;
    /**
     * @var bool whether to enable [savepoint](http://en.wikipedia.org/wiki/Savepoint).
     * Note that if the underlying DBMS does not support savepoint, setting this property to be true will have no effect.
     */
    public $enableSavepoint = true;

    /**
     * @var Schema the database schema
     */
    private $_schema;
    /**
     * @var string driver name
     */
    private $_driverName;

    public function init(){
        $this->open();
    }

    /**
     * Returns a value indicating whether the DB connection is established.
     * @return bool whether the DB connection is established
     */
    public function getIsActive()
    {
        return $this->pdo !== null;
    }

    /**
     * Establishes a DB connection.
     * It does nothing if a DB connection has already been established.
     * @throws Exception if connection fails
     */
    public function open()
    {
        if ($this->pdo !== null) {
            return;
        }
        if (empty($this->dsn)) {
            throw new Exception('Connection::dsn cannot be empty.');
        }
        try {
            $this->pdo = $this->createPdoInstance();
            $this->initConnection();
        } catch (PDOException $e) {
            throw new Exception($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Closes the currently active DB connection.
     * It does nothing if the connection is already closed.
     */
    public function close()
    {
        if ($this->pdo !== null) {
            $this->pdo = null;
            $this->_schema = null;
        }
    }

    /**
     * Creates the PDO instance.
     * This method is called by [[open]] to establish a DB connection.
     * The default implementation will create a PHP PDO instance.
     * You may override this method if the default PDO needs to be adapted for certain DBMS.
     * @return PDO the pdo instance
     */
    protected function createPdoInstance()
    {
        $pdoClass = $this->pdoClass;
        if ($pdoClass === null) {
            $pdoClass = 'PDO';
            if ($this->_driverName !== null) {
                $driver = $this->_driverName;
            } elseif (($pos = strpos($this->dsn, ':')) !== false) {
                $driver = strtolower(substr($this->dsn, 0, $pos));
            }
            if (isset($driver)) {
                if ($driver === 'oci' || $driver === 'oci8') {
                    $pdoClass = 'pfilsx\db_broker\oci\Oci8';
                }
            }
        }
        $dsn = $this->dsn;
        return new $pdoClass($dsn, $this->username, $this->password, $this->attributes);
    }

    /**
     * Initializes the DB connection.
     * This method is invoked right after the DB connection is established.
     * The default implementation turns on `PDO::ATTR_EMULATE_PREPARES`
     * if [[emulatePrepare]] is true, and sets the database [[charset]] if it is not empty.
     * It then triggers an [[EVENT_AFTER_OPEN]] event.
     */
    protected function initConnection()
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($this->emulatePrepare !== null && constant('PDO::ATTR_EMULATE_PREPARES')) {
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $this->emulatePrepare);
        }
        if ($this->charset !== null && in_array($this->getDriverName(), ['pgsql', 'mysql'], true)) {
            $this->pdo->exec('SET NAMES ' . $this->pdo->quote($this->charset));
        }
    }

    /**
     * Creates a command for execution.
     * @param string $sql the SQL statement to be executed
     * @param array $params the parameters to be bound to the SQL statement
     * @return Command the DB command
     */
    public function createCommand($sql = null, $params = [])
    {
        /** @var Command $command */
        $command = new $this->commandClass([
            'db' => $this,
            'sql' => $sql,
        ]);

        return $command->bindValues($params);
    }
    public function createQuery(){
        return new Query(['db' => $this]);
    }


    /**
     * Returns the schema information for the database opened by this connection.
     * @return Schema the schema information for the database opened by this connection.
     * @throws Exception if there is no support for the current driver type
     */
    public function getSchema()
    {
        if ($this->_schema !== null) {
            return $this->_schema;
        }

        $driver = $this->getDriverName();
        if (isset($this->schemaMap[$driver])) {
            $class = !is_array($this->schemaMap[$driver]) ? $this->schemaMap[$driver] : $this->schemaMap[$driver]['class'];

            return $this->_schema = new $class(['db' => $this]);
        }

        throw new Exception("Connection does not support reading schema information for '$driver' DBMS.");
    }

    /**
     * Returns the query builder for the current DB connection.
     * @return QueryBuilder the query builder for the current DB connection.
     */
    public function getQueryBuilder()
    {
        return $this->getSchema()->getQueryBuilder();
    }

    /**
     * Obtains the schema information for the named table.
     * @param string $name table name.
     * @param bool $refresh whether to reload the table schema even if it is found in the cache.
     * @return TableSchema table schema information. Null if the named table does not exist.
     */
    public function getTableSchema($name, $refresh = false)
    {
        return $this->getSchema()->getTableSchema($name, $refresh);
    }

    /**
     * Returns the ID of the last inserted row or sequence value.
     * @param string $sequenceName name of the sequence object (required by some DBMS)
     * @return string the row ID of the last row inserted, or the last value retrieved from the sequence object
     * @see http://php.net/manual/en/pdo.lastinsertid.php
     */
    public function getLastInsertID($sequenceName = '')
    {
        return $this->getSchema()->getLastInsertID($sequenceName);
    }

    /**
     * Quotes a string value for use in a query.
     * Note that if the parameter is not a string, it will be returned without change.
     * @param string $value string to be quoted
     * @return string the properly quoted string
     * @see http://php.net/manual/en/pdo.quote.php
     */
    public function quoteValue($value)
    {
        return $this->getSchema()->quoteValue($value);
    }

    /**
     * Quotes a table name for use in a query.
     * If the table name contains schema prefix, the prefix will also be properly quoted.
     * If the table name is already quoted or contains special characters including '(', '[[' and '{{',
     * then this method will do nothing.
     * @param string $name table name
     * @return string the properly quoted table name
     */
    public function quoteTableName($name)
    {
        return $this->getSchema()->quoteTableName($name);
    }

    /**
     * Quotes a column name for use in a query.
     * If the column name contains prefix, the prefix will also be properly quoted.
     * If the column name is already quoted or contains special characters including '(', '[[' and '{{',
     * then this method will do nothing.
     * @param string $name column name
     * @return string the properly quoted column name
     */
    public function quoteColumnName($name)
    {
        return $this->getSchema()->quoteColumnName($name);
    }

    /**
     * Processes a SQL statement by quoting table and column names that are enclosed within double brackets.
     * Tokens enclosed within double curly brackets are treated as table names, while
     * tokens enclosed within double square brackets are column names. They will be quoted accordingly.
     * Also, the percentage character "%" at the beginning or ending of a table name will be replaced
     * with [[tablePrefix]].
     * @param string $sql the SQL to be quoted
     * @return string the quoted SQL
     */
    public function quoteSql($sql)
    {
        return preg_replace_callback(
            '/(\\{\\{(%?[\w\-\. ]+%?)\\}\\}|\\[\\[([\w\-\. ]+)\\]\\])/',
            function ($matches) {
                if (isset($matches[3])) {
                    return $this->quoteColumnName($matches[3]);
                }

                return str_replace('%', $this->tablePrefix, $this->quoteTableName($matches[2]));
            },
            $sql
        );
    }

    /**
     * Returns the name of the DB driver. Based on the the current [[dsn]], in case it was not set explicitly
     * by an end user.
     * @return string name of the DB driver
     */
    public function getDriverName()
    {
        if ($this->_driverName === null) {
            if (($pos = strpos($this->dsn, ':')) !== false) {
                $this->_driverName = strtolower(substr($this->dsn, 0, $pos));
            } else {
                $this->_driverName = null;
            }
        }
        return $this->_driverName;
    }

    /**
     * Changes the current driver name.
     * @param string $driverName name of the DB driver
     */
    public function setDriverName($driverName)
    {
        $this->_driverName = strtolower($driverName);
    }

    /**
     * Close the connection before serializing.
     * @return array
     */
    public function __sleep()
    {
        $fields = (array)$this;

        unset($fields['pdo']);
        unset($fields["\000" . __CLASS__ . "\000" . '_schema']);
        return array_keys($fields);
    }

    /**
     * Returns the currently active transaction.
     * @return Transaction|null the currently active transaction. Null if no active transaction.
     */
    public function getTransaction()
    {
        return $this->_transaction && $this->_transaction->getIsActive() ? $this->_transaction : null;
    }

    /**
     * Starts a transaction.
     * @param string|null $isolationLevel The isolation level to use for this transaction.
     * See [[Transaction::begin()]] for details.
     * @return Transaction the transaction initiated
     */
    public function beginTransaction($isolationLevel = null)
    {
        $this->open();

        if (($transaction = $this->getTransaction()) === null) {
            $transaction = $this->_transaction = new Transaction(['db' => $this]);
        }
        $transaction->begin($isolationLevel);

        return $transaction;
    }

    /**
     * Executes callback provided in a transaction.
     *
     * @param callable $callback a valid PHP callback that performs the job. Accepts connection instance as parameter.
     * @param string|null $isolationLevel The isolation level to use for this transaction.
     * See [[Transaction::begin()]] for details.
     * @throws \Exception|Throwable if there is any exception during query. In this case the transaction will be rolled back.
     * @return mixed result of callback function
     */
    public function transaction(callable $callback, $isolationLevel = null)
    {
        $transaction = $this->beginTransaction($isolationLevel);
        $level = $transaction->level;

        try {
            $result = call_user_func($callback, $this);
            if ($transaction->isActive && $transaction->level === $level) {
                $transaction->commit();
            }
        } catch (Exception $e) {
            $this->rollbackTransactionOnLevel($transaction, $level);
            throw $e;
        } catch (Throwable $e) {
            $this->rollbackTransactionOnLevel($transaction, $level);
            throw $e;
        }

        return $result;
    }

    /**
     * Rolls back given [[Transaction]] object if it's still active and level match.
     * In some cases rollback can fail, so this method is fail safe. Exception thrown
     * from rollback will be caught and just ignore it =).
     * @param Transaction $transaction Transaction object given from [[beginTransaction()]].
     * @param int $level Transaction level just after [[beginTransaction()]] call.
     */
    private function rollbackTransactionOnLevel($transaction, $level)
    {
        if ($transaction->isActive && $transaction->level === $level) {
            try {
                $transaction->rollBack();
            } catch (\Exception $e) {
                //ignore it
            }
        }
    }
}