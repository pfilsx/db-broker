<?php


namespace pfilsx\db_broker\pgsql;


use pfilsx\db_broker\Query;
use pfilsx\db_broker\QueryBuilder as BaseQueryBuilder;
use PDO;

class QueryBuilder extends BaseQueryBuilder
{
    /**
     * Defines a UNIQUE index for [[createIndex()]].
     */
    const INDEX_UNIQUE = 'unique';
    /**
     * Defines a B-tree index for [[createIndex()]].
     */
    const INDEX_B_TREE = 'btree';
    /**
     * Defines a hash index for [[createIndex()]].
     */
    const INDEX_HASH = 'hash';
    /**
     * Defines a GiST index for [[createIndex()]].
     */
    const INDEX_GIST = 'gist';
    /**
     * Defines a GIN index for [[createIndex()]].
     */
    const INDEX_GIN = 'gin';

    /**
     * @var array mapping from abstract column types (keys) to physical column types (values).
     */
    public $typeMap = [
        Schema::TYPE_PK => 'serial NOT NULL PRIMARY KEY',
        Schema::TYPE_UPK => 'serial NOT NULL PRIMARY KEY',
        Schema::TYPE_BIGPK => 'bigserial NOT NULL PRIMARY KEY',
        Schema::TYPE_UBIGPK => 'bigserial NOT NULL PRIMARY KEY',
        Schema::TYPE_CHAR => 'char(1)',
        Schema::TYPE_STRING => 'varchar(255)',
        Schema::TYPE_TEXT => 'text',
        Schema::TYPE_SMALLINT => 'smallint',
        Schema::TYPE_INTEGER => 'integer',
        Schema::TYPE_BIGINT => 'bigint',
        Schema::TYPE_FLOAT => 'double precision',
        Schema::TYPE_DOUBLE => 'double precision',
        Schema::TYPE_DECIMAL => 'numeric(10,0)',
        Schema::TYPE_DATETIME => 'timestamp(0)',
        Schema::TYPE_TIMESTAMP => 'timestamp(0)',
        Schema::TYPE_TIME => 'time(0)',
        Schema::TYPE_DATE => 'date',
        Schema::TYPE_BINARY => 'bytea',
        Schema::TYPE_BOOLEAN => 'boolean',
        Schema::TYPE_MONEY => 'numeric(19,4)',
    ];

    /**
     * @var array map of query condition to builder methods.
     * These methods are used by [[buildCondition]] to build SQL conditions from array syntax.
     */
    protected $conditionBuilders = [
        'NOT' => 'buildNotCondition',
        'AND' => 'buildAndCondition',
        'OR' => 'buildAndCondition',
        'BETWEEN' => 'buildBetweenCondition',
        'NOT BETWEEN' => 'buildBetweenCondition',
        'IN' => 'buildInCondition',
        'NOT IN' => 'buildInCondition',
        'LIKE' => 'buildLikeCondition',
        'ILIKE' => 'buildLikeCondition',
        'NOT LIKE' => 'buildLikeCondition',
        'NOT ILIKE' => 'buildLikeCondition',
        'OR LIKE' => 'buildLikeCondition',
        'OR ILIKE' => 'buildLikeCondition',
        'OR NOT LIKE' => 'buildLikeCondition',
        'OR NOT ILIKE' => 'buildLikeCondition',
        'EXISTS' => 'buildExistsCondition',
        'NOT EXISTS' => 'buildExistsCondition',
    ];

    /**
     * @inheritdoc
     */
    public function insert($table, $columns, &$params)
    {
        return parent::insert($table, $this->normalizeTableRowData($table, $columns), $params);
    }

    /**
     * @inheritdoc
     */
    public function update($table, $columns, $condition, &$params)
    {
        return parent::update($table, $this->normalizeTableRowData($table, $columns), $condition, $params);
    }

    /**
     * Normalizes data to be saved into the table, performing extra preparations and type converting, if necessary.
     * @param string $table the table that data will be saved into.
     * @param array|Query $columns the column data (name => value) to be saved into the table or instance
     * of [[db_broker|Query]] to perform INSERT INTO ... SELECT SQL statement.
     * @return array normalized columns
     */
    private function normalizeTableRowData($table, $columns)
    {
        if ($columns instanceof Query) {
            return $columns;
        }

        if (($tableSchema = $this->db->getSchema()->getTableSchema($table)) !== null) {
            $columnSchemas = $tableSchema->columns;
            foreach ($columns as $name => $value) {
                if (isset($columnSchemas[$name]) && $columnSchemas[$name]->type === Schema::TYPE_BINARY && is_string($value)) {
                    $columns[$name] = [$value, PDO::PARAM_LOB]; // explicitly setup PDO param type for binary column
                }
            }
        }

        return $columns;
    }

    /**
     * @inheritdoc
     */
    public function batchInsert($table, $columns, $rows)
    {
        if (empty($rows)) {
            return '';
        }

        $schema = $this->db->getSchema();
        if (($tableSchema = $schema->getTableSchema($table)) !== null) {
            $columnSchemas = $tableSchema->columns;
        } else {
            $columnSchemas = [];
        }

        $values = [];
        foreach ($rows as $row) {
            $vs = [];
            foreach ($row as $i => $value) {
                if (isset($columns[$i], $columnSchemas[$columns[$i]]) && !is_array($value)) {
                    $value = $columnSchemas[$columns[$i]]->dbTypecast($value);
                }
                if (is_string($value)) {
                    $value = $schema->quoteValue($value);
                } elseif (is_float($value)) {
                    // ensure type cast always has . as decimal separator in all locales
                    $value = str_replace(',', '.', (string) $value);
                } elseif ($value === true) {
                    $value = 'TRUE';
                } elseif ($value === false) {
                    $value = 'FALSE';
                } elseif ($value === null) {
                    $value = 'NULL';
                }
                $vs[] = $value;
            }
            $values[] = '(' . implode(', ', $vs) . ')';
        }
        if (empty($values)) {
            return '';
        }

        foreach ($columns as $i => $name) {
            $columns[$i] = $schema->quoteColumnName($name);
        }

        return 'INSERT INTO ' . $schema->quoteTableName($table)
            . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $values);
    }
}