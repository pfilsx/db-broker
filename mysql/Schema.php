<?php


namespace pfilsx\db_broker\mysql;

use pfilsx\db_broker\ColumnSchema;
use pfilsx\db_broker\Expression;
use pfilsx\db_broker\Schema as BaseSchema;
use pfilsx\db_broker\TableSchema;
use Exception;
use PDO;
use PDOException;

/**
 * Class Schema
 * @package db_broker\mysql
 */
class Schema extends BaseSchema
{
    /**
     * @var bool whether MySQL used is older than 5.1.
     */
    private $_oldMysql;

    /**
     * @var array mapping from physical column types (keys) to abstract column types (values)
     */
    public $typeMap = [
        'tinyint' => self::TYPE_SMALLINT,
        'bit' => self::TYPE_INTEGER,
        'smallint' => self::TYPE_SMALLINT,
        'mediumint' => self::TYPE_INTEGER,
        'int' => self::TYPE_INTEGER,
        'integer' => self::TYPE_INTEGER,
        'bigint' => self::TYPE_BIGINT,
        'float' => self::TYPE_FLOAT,
        'double' => self::TYPE_DOUBLE,
        'real' => self::TYPE_FLOAT,
        'decimal' => self::TYPE_DECIMAL,
        'numeric' => self::TYPE_DECIMAL,
        'tinytext' => self::TYPE_TEXT,
        'mediumtext' => self::TYPE_TEXT,
        'longtext' => self::TYPE_TEXT,
        'longblob' => self::TYPE_BINARY,
        'blob' => self::TYPE_BINARY,
        'text' => self::TYPE_TEXT,
        'varchar' => self::TYPE_STRING,
        'string' => self::TYPE_STRING,
        'char' => self::TYPE_CHAR,
        'datetime' => self::TYPE_DATETIME,
        'year' => self::TYPE_DATE,
        'date' => self::TYPE_DATE,
        'time' => self::TYPE_TIME,
        'timestamp' => self::TYPE_TIMESTAMP,
        'enum' => self::TYPE_STRING,
        'varbinary' => self::TYPE_BINARY,
    ];

    /**
     * @inheritDoc
     */
    protected function resolveTableName($name)
    {
        $resolvedName = new TableSchema();
        $parts = explode('.', str_replace('`', '', $name));
        if (isset($parts[1])) {
            $resolvedName->schemaName = $parts[0];
            $resolvedName->name = $parts[1];
        } else {
            $resolvedName->schemaName = $this->defaultSchema;
            $resolvedName->name = $name;
        }
        $resolvedName->fullName = ($resolvedName->schemaName !== $this->defaultSchema ? $resolvedName->schemaName . '.' : '') . $resolvedName->name;
        return $resolvedName;
    }

    /**
     * @inheritDoc
     */
    protected function findTableNames($schema = '')
    {
        $sql = 'SHOW TABLES';
        if ($schema !== '') {
            $sql .= ' FROM ' . $this->quoteSimpleTableName($schema);
        }

        return $this->db->createCommand($sql)->queryColumn();
    }

    /**
     * @inheritDoc
     */
    protected function loadTableSchema($name)
    {
        $table = new TableSchema();
        $this->resolveTableNames($table, $name);

        if ($this->findColumns($table)) {
            return $table;
        }

        return null;
    }

    /**
     * @inheritDoc
     * @throws Exception if this method is called.
     */
    protected function loadTableChecks($tableName)
    {
        throw new Exception('MySQL does not support check constraints.');
    }

    /**
     * @inheritDoc
     * @throws Exception if this method is called.
     */
    protected function loadTableDefaultValues($tableName)
    {
        throw new Exception('MySQL does not support default value constraints.');
    }

    /**
     * Quotes a table name for use in a query.
     * A simple table name has no schema prefix.
     * @param string $name table name
     * @return string the properly quoted table name
     */
    public function quoteSimpleTableName($name)
    {
        return strpos($name, '`') !== false ? $name : "`$name`";
    }

    /**
     * Quotes a column name for use in a query.
     * A simple column name has no prefix.
     * @param string $name column name
     * @return string the properly quoted column name
     */
    public function quoteSimpleColumnName($name)
    {
        return strpos($name, '`') !== false || $name === '*' ? $name : "`$name`";
    }

    /**
     * Creates a query builder for the MySQL database.
     * @return QueryBuilder query builder instance
     */
    public function createQueryBuilder()
    {
        return new QueryBuilder($this->db);
    }

    /**
     * Resolves the table name and schema name (if any).
     * @param TableSchema $table the table metadata object
     * @param string $name the table name
     */
    protected function resolveTableNames($table, $name)
    {
        $parts = explode('.', str_replace('`', '', $name));
        if (isset($parts[1])) {
            $table->schemaName = $parts[0];
            $table->name = $parts[1];
            $table->fullName = $table->schemaName . '.' . $table->name;
        } else {
            $table->fullName = $table->name = $parts[0];
        }
    }

    /**
     * Loads the column information into a [[ColumnSchema]] object.
     * @param array $info column information
     * @return ColumnSchema the column schema object
     */
    protected function loadColumnSchema($info)
    {
        $column = $this->createColumnSchema();

        $column->name = $info['field'];
        $column->allowNull = $info['null'] === 'YES';
        $column->isPrimaryKey = strpos($info['key'], 'PRI') !== false;
        $column->autoIncrement = stripos($info['extra'], 'auto_increment') !== false;
        $column->comment = $info['comment'];

        $column->dbType = $info['type'];
        $column->unsigned = stripos($column->dbType, 'unsigned') !== false;

        $column->type = self::TYPE_STRING;
        if (preg_match('/^(\w+)(?:\(([^\)]+)\))?/', $column->dbType, $matches)) {
            $type = strtolower($matches[1]);
            if (isset($this->typeMap[$type])) {
                $column->type = $this->typeMap[$type];
            }
            if (!empty($matches[2])) {
                if ($type === 'enum') {
                    preg_match_all("/'[^']*'/", $matches[2], $values);
                    foreach ($values[0] as $i => $value) {
                        $values[$i] = trim($value, "'");
                    }
                    $column->enumValues = $values;
                } else {
                    $values = explode(',', $matches[2]);
                    $column->size = $column->precision = (int) $values[0];
                    if (isset($values[1])) {
                        $column->scale = (int) $values[1];
                    }
                    if ($column->size === 1 && $type === 'bit') {
                        $column->type = 'boolean';
                    } elseif ($type === 'bit') {
                        if ($column->size > 32) {
                            $column->type = 'bigint';
                        } elseif ($column->size === 32) {
                            $column->type = 'integer';
                        }
                    }
                }
            }
        }

        $column->phpType = $this->getColumnPhpType($column);

        if (!$column->isPrimaryKey) {
            if ($column->type === 'timestamp' && $info['default'] === 'CURRENT_TIMESTAMP') {
                $column->defaultValue = new Expression('CURRENT_TIMESTAMP');
            } elseif (isset($type) && $type === 'bit') {
                $column->defaultValue = bindec(trim($info['default'], 'b\''));
            } else {
                $column->defaultValue = $column->phpTypecast($info['default']);
            }
        }

        return $column;
    }

    /**
     * Collects the metadata of table columns.
     * @param TableSchema $table the table metadata
     * @return bool whether the table exists in the database
     * @throws \Exception if DB query fails
     */
    protected function findColumns($table)
    {
        $sql = 'SHOW FULL COLUMNS FROM ' . $this->quoteTableName($table->fullName);
        try {
            $columns = $this->db->createCommand($sql)->queryAll();
        } catch (\Exception $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof PDOException && strpos($previous->getMessage(), 'SQLSTATE[42S02') !== false) {
                // table does not exist
                // https://dev.mysql.com/doc/refman/5.5/en/error-messages-server.html#error_er_bad_table_error
                return false;
            }
            throw $e;
        }
        foreach ($columns as $info) {
            if ($this->db->pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_LOWER) {
                $info = array_change_key_case($info, CASE_LOWER);
            }
            $column = $this->loadColumnSchema($info);
            $table->columns[$column->name] = $column;
            if ($column->isPrimaryKey) {
                $table->primaryKey[] = $column->name;
                if ($column->autoIncrement) {
                    $table->sequenceName = '';
                }
            }
        }

        return true;
    }

    /**
     * Gets the CREATE TABLE sql string.
     * @param TableSchema $table the table metadata
     * @return string $sql the result of 'SHOW CREATE TABLE'
     */
    protected function getCreateTableSql($table)
    {
        $row = $this->db->createCommand('SHOW CREATE TABLE ' . $this->quoteTableName($table->fullName))->queryOne();
        if (isset($row['Create Table'])) {
            $sql = $row['Create Table'];
        } else {
            $row = array_values($row);
            $sql = $row[1];
        }

        return $sql;
    }

    /**
     * @return bool whether the version of the MySQL being used is older than 5.1.
     * @throws Exception
     */
    protected function isOldMysql()
    {
        if ($this->_oldMysql === null) {
            $version = $this->db->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $this->_oldMysql = version_compare($version, '5.1', '<=');
        }

        return $this->_oldMysql;
    }
}