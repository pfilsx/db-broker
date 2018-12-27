<?php


namespace pfilsx\db_broker;

use ArrayAccess;
use Countable;
use Exception;
use Traversable;

/**
 * Class QueryBuilder
 * @package db_broker
 */
class QueryBuilder extends BaseObject
{
    /**
     * The prefix for automatically generated query binding parameters.
     */
    const PARAM_PREFIX = ':qp';

    /**
     * @var Connection the database connection.
     */
    public $db;
    /**
     * @var string the separator between different fragments of a SQL statement.
     * Defaults to an empty space. This is mainly used by [[build()]] when generating a SQL statement.
     */
    public $separator = ' ';
    /**
     * @var array the abstract column types mapped to physical column types.
     * This is mainly used to support creating/modifying tables using DB-independent data type specifications.
     * Child classes should override this property to declare supported type mappings.
     */
    public $typeMap = [];

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
        'NOT LIKE' => 'buildLikeCondition',
        'OR LIKE' => 'buildLikeCondition',
        'OR NOT LIKE' => 'buildLikeCondition',
        'EXISTS' => 'buildExistsCondition',
        'NOT EXISTS' => 'buildExistsCondition',
    ];
    /**
     * @var array map of chars to their replacements in LIKE conditions.
     * By default it's configured to escape `%`, `_` and `\` with `\`.
     */
    protected $likeEscapingReplacements = [
        '%' => '\%',
        '_' => '\_',
        '\\' => '\\\\',
    ];
    /**
     * @var string|null character used to escape special characters in LIKE conditions.
     * By default it's assumed to be `\`.
     */
    protected $likeEscapeCharacter;

    /**
     * Constructor.
     * @param Connection $connection the database connection.
     * @param array $config name-value pairs that will be used to initialize the object properties
     */
    public function __construct($connection, $config = [])
    {
        $this->db = $connection;
        parent::__construct($config);
    }
    /**
     * Generates a SELECT SQL statement from a [[Query]] object.
     * @param Query $query the [[Query]] object from which the SQL statement will be generated.
     * @param array $params the parameters to be bound to the generated SQL statement. These parameters will
     * be included in the result with the additional parameters generated during the query building process.
     * @return array the generated SQL statement (the first array element) and the corresponding
     * parameters to be bound to the SQL statement (the second array element). The parameters returned
     * include those provided in `$params`.
     */
    public function build($query, $params = [])
    {
        $query = $query->prepare($this);

        $params = empty($params) ? $query->params : array_merge($params, $query->params);

        $clauses = [
            $this->buildSelect($query->select, $params, $query->distinct, $query->selectOption),
            $this->buildFrom($query->from, $params),
            $this->buildJoin($query->join, $params),
            $this->buildWhere($query->where, $params),
            $this->buildGroupBy($query->groupBy),
            $this->buildHaving($query->having, $params),
        ];

        $sql = implode($this->separator, array_filter($clauses));
        $sql = $this->buildOrderByAndLimit($sql, $query->orderBy, $query->limit, $query->offset);

        if (!empty($query->orderBy)) {
            foreach ($query->orderBy as $expression) {
                if ($expression instanceof Expression) {
                    $params = array_merge($params, $expression->params);
                }
            }
        }
        if (!empty($query->groupBy)) {
            foreach ($query->groupBy as $expression) {
                if ($expression instanceof Expression) {
                    $params = array_merge($params, $expression->params);
                }
            }
        }

        $union = $this->buildUnion($query->union, $params);
        if ($union !== '') {
            $sql = "($sql){$this->separator}$union";
        }

        return [$sql, $params];
    }

    /**
     * Creates an INSERT SQL statement.
     *
     * For example,
     *
     * ```php
     * $sql = $queryBuilder->insert('user', [
     *     'name' => 'Sam',
     *     'age' => 30,
     * ], $params);
     * ```
     *
     * The method will properly escape the table and column names.
     *
     * @param string $table the table that new rows will be inserted into.
     * @param array|\pfilsx\db_broker\Query $columns the column data (name => value) to be inserted into the table or instance
     * of [[db_broker|Query]] to perform INSERT INTO ... SELECT SQL statement.
     * @param array $params the binding parameters that will be generated by this method.
     * They should be bound to the DB command later.
     * @return string the INSERT SQL
     */
    public function insert($table, $columns, &$params)
    {
        $schema = $this->db->getSchema();
        if (($tableSchema = $schema->getTableSchema($table)) !== null) {
            $columnSchemas = $tableSchema->columns;
        } else {
            $columnSchemas = [];
        }
        $names = [];
        $placeholders = [];
        $values = ' DEFAULT VALUES';
        if ($columns instanceof Query) {
            list($names, $values, $params) = $this->prepareInsertSelectSubQuery($columns, $schema);
        } else {
            foreach ($columns as $name => $value) {
                $names[] = $schema->quoteColumnName($name);
                if ($value instanceof Expression) {
                    $placeholders[] = $value->expression;
                    foreach ($value->params as $n => $v) {
                        $params[$n] = $v;
                    }
                } elseif ($value instanceof Query) {
                    list($sql, $params) = $this->build($value, $params);
                    $placeholders[] = "($sql)";
                } else {
                    $phName = self::PARAM_PREFIX . count($params);
                    $placeholders[] = $phName;
                    $params[$phName] = !is_array($value) && isset($columnSchemas[$name]) ? $columnSchemas[$name]->dbTypecast($value) : $value;
                }
            }
        }

        return 'INSERT INTO ' . $schema->quoteTableName($table)
            . (!empty($names) ? ' (' . implode(', ', $names) . ')' : '')
            . (!empty($placeholders) ? ' VALUES (' . implode(', ', $placeholders) . ')' : $values);
    }

    /**
     * Prepare select-subquery and field names for INSERT INTO ... SELECT SQL statement.
     *
     * @param Query $columns Object, which represents select query.
     * @param \pfilsx\db_broker\Schema $schema Schema object to quote column name.
     * @param array $params the parameters to be bound to the generated SQL statement. These parameters will
     * be included in the result with the additional parameters generated during the query building process.
     * @return array
     * @throws Exception if query's select does not contain named parameters only.
     */
    protected function prepareInsertSelectSubQuery($columns, $schema, $params = [])
    {
        if (!is_array($columns->select) || empty($columns->select) || in_array('*', $columns->select)) {
            throw new Exception('Expected select query object with enumerated (named) parameters');
        }

        list($values, $params) = $this->build($columns, $params);
        $names = [];
        $values = ' ' . $values;
        foreach ($columns->select as $title => $field) {
            if (is_string($title)) {
                $names[] = $schema->quoteColumnName($title);
            } elseif (preg_match('/^(.*?)(?i:\s+as\s+|\s+)([\w\-_\.]+)$/', $field, $matches)) {
                $names[] = $schema->quoteColumnName($matches[2]);
            } else {
                $names[] = $schema->quoteColumnName($field);
            }
        }

        return [$names, $values, $params];
    }

    /**
     * Generates a batch INSERT SQL statement.
     *
     * For example,
     *
     * ```php
     * $sql = $queryBuilder->batchInsert('user', ['name', 'age'], [
     *     ['Tom', 30],
     *     ['Jane', 20],
     *     ['Linda', 25],
     * ]);
     * ```
     *
     * Note that the values in each row must match the corresponding column names.
     *
     * The method will properly escape the column names, and quote the values to be inserted.
     *
     * @param string $table the table that new rows will be inserted into.
     * @param array $columns the column names
     * @param array|\Generator $rows the rows to be batch inserted into the table
     * @return string the batch INSERT SQL statement
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
                } elseif ($value === false) {
                    $value = 0;
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

    /**
     * Creates an UPDATE SQL statement.
     *
     * For example,
     *
     * ```php
     * $params = [];
     * $sql = $queryBuilder->update('user', ['status' => 1], 'age > 30', $params);
     * ```
     *
     * The method will properly escape the table and column names.
     *
     * @param string $table the table to be updated.
     * @param array $columns the column data (name => value) to be updated.
     * @param array|string $condition the condition that will be put in the WHERE part. Please
     * refer to [[Query::where()]] on how to specify condition.
     * @param array $params the binding parameters that will be modified by this method
     * so that they can be bound to the DB command later.
     * @return string the UPDATE SQL
     */
    public function update($table, $columns, $condition, &$params)
    {
        if (($tableSchema = $this->db->getTableSchema($table)) !== null) {
            $columnSchemas = $tableSchema->columns;
        } else {
            $columnSchemas = [];
        }

        $lines = [];
        foreach ($columns as $name => $value) {
            if ($value instanceof Expression) {
                $lines[] = $this->db->quoteColumnName($name) . '=' . $value->expression;
                foreach ($value->params as $n => $v) {
                    $params[$n] = $v;
                }
            } else {
                $phName = self::PARAM_PREFIX . count($params);
                $lines[] = $this->db->quoteColumnName($name) . '=' . $phName;
                $params[$phName] = !is_array($value) && isset($columnSchemas[$name]) ? $columnSchemas[$name]->dbTypecast($value) : $value;
            }
        }

        $sql = 'UPDATE ' . $this->db->quoteTableName($table) . ' SET ' . implode(', ', $lines);
        $where = $this->buildWhere($condition, $params);

        return $where === '' ? $sql : $sql . ' ' . $where;
    }

    /**
     * Creates a DELETE SQL statement.
     *
     * For example,
     *
     * ```php
     * $sql = $queryBuilder->delete('user', 'status = 0');
     * ```
     *
     * The method will properly escape the table and column names.
     *
     * @param string $table the table where the data will be deleted from.
     * @param array|string $condition the condition that will be put in the WHERE part. Please
     * refer to [[Query::where()]] on how to specify condition.
     * @param array $params the binding parameters that will be modified by this method
     * so that they can be bound to the DB command later.
     * @return string the DELETE SQL
     */
    public function delete($table, $condition, &$params)
    {
        $sql = 'DELETE FROM ' . $this->db->quoteTableName($table);
        $where = $this->buildWhere($condition, $params);

        return $where === '' ? $sql : $sql . ' ' . $where;
    }
    /**
     * @param array $columns
     * @param array $params the binding parameters to be populated
     * @param bool $distinct
     * @param string $selectOption
     * @return string the SELECT clause built from [[Query::$select]].
     */
    public function buildSelect($columns, &$params, $distinct = false, $selectOption = null)
    {
        $select = $distinct ? 'SELECT DISTINCT' : 'SELECT';
        if ($selectOption !== null) {
            $select .= ' ' . $selectOption;
        }

        if (empty($columns)) {
            return $select . ' *';
        }

        foreach ($columns as $i => $column) {
            if ($column instanceof Expression) {
                if (is_int($i)) {
                    $columns[$i] = $column->expression;
                } else {
                    $columns[$i] = $column->expression . ' AS ' . $this->db->quoteColumnName($i);
                }
                $params = array_merge($params, $column->params);
            } elseif ($column instanceof Query) {
                list($sql, $params) = $this->build($column, $params);
                $columns[$i] = "($sql) AS " . $this->db->quoteColumnName($i);
            } elseif (is_string($i)) {
                if (strpos($column, '(') === false) {
                    $column = $this->db->quoteColumnName($column);
                }
                $columns[$i] = "$column AS " . $this->db->quoteColumnName($i);
            } elseif (strpos($column, '(') === false) {
                if (preg_match('/^(.*?)(?i:\s+as\s+|\s+)([\w\-_\.]+)$/', $column, $matches)) {
                    $columns[$i] = $this->db->quoteColumnName($matches[1]) . ' AS ' . $this->db->quoteColumnName($matches[2]);
                } else {
                    $columns[$i] = $this->db->quoteColumnName($column);
                }
            }
        }

        return $select . ' ' . implode(', ', $columns);
    }

    /**
     * @param array $tables
     * @param array $params the binding parameters to be populated
     * @return string the FROM clause built from [[Query::$from]].
     */
    public function buildFrom($tables, &$params)
    {
        if (empty($tables)) {
            return '';
        }

        $tables = $this->quoteTableNames($tables, $params);

        return 'FROM ' . implode(', ', $tables);
    }

    /**
     * @param array $joins
     * @param array $params the binding parameters to be populated
     * @return string the JOIN clause built from [[Query::$join]].
     * @throws Exception if the $joins parameter is not in proper format
     */
    public function buildJoin($joins, &$params)
    {
        if (empty($joins)) {
            return '';
        }

        foreach ($joins as $i => $join) {
            if (!is_array($join) || !isset($join[0], $join[1])) {
                throw new Exception('A join clause must be specified as an array of join type, join table, and optionally join condition.');
            }
            // 0:join type, 1:join table, 2:on-condition (optional)
            list($joinType, $table) = $join;
            $tables = $this->quoteTableNames((array) $table, $params);
            $table = reset($tables);
            $joins[$i] = "$joinType $table";
            if (isset($join[2])) {
                $condition = $this->buildCondition($join[2], $params);
                if ($condition !== '') {
                    $joins[$i] .= ' ON ' . $condition;
                }
            }
        }

        return implode($this->separator, $joins);
    }

    /**
     * Quotes table names passed.
     *
     * @param array $tables
     * @param array $params
     * @return array
     */
    private function quoteTableNames($tables, &$params)
    {
        foreach ($tables as $i => $table) {
            if ($table instanceof Query) {
                list($sql, $params) = $this->build($table, $params);
                $tables[$i] = "($sql) " . $this->db->quoteTableName($i);
            } elseif (is_string($i)) {
                if (strpos($table, '(') === false) {
                    $table = $this->db->quoteTableName($table);
                }
                $tables[$i] = "$table " . $this->db->quoteTableName($i);
            } elseif (strpos($table, '(') === false) {
                if (preg_match('/^(.*?)(?i:\s+as|)\s+([^ ]+)$/', $table, $matches)) { // with alias
                    $tables[$i] = $this->db->quoteTableName($matches[1]) . ' ' . $this->db->quoteTableName($matches[2]);
                } else {
                    $tables[$i] = $this->db->quoteTableName($table);
                }
            }
        }

        return $tables;
    }

    /**
     * @param string|array $condition
     * @param array $params the binding parameters to be populated
     * @return string the WHERE clause built from [[Query::$where]].
     */
    public function buildWhere($condition, &$params)
    {
        $where = $this->buildCondition($condition, $params);

        return $where === '' ? '' : 'WHERE ' . $where;
    }

    /**
     * @param array $columns
     * @return string the GROUP BY clause
     */
    public function buildGroupBy($columns)
    {
        if (empty($columns)) {
            return '';
        }
        foreach ($columns as $i => $column) {
            if ($column instanceof Expression) {
                $columns[$i] = $column->expression;
            } elseif (strpos($column, '(') === false) {
                $columns[$i] = $this->db->quoteColumnName($column);
            }
        }

        return 'GROUP BY ' . implode(', ', $columns);
    }

    /**
     * @param string|array $condition
     * @param array $params the binding parameters to be populated
     * @return string the HAVING clause built from [[Query::$having]].
     */
    public function buildHaving($condition, &$params)
    {
        $having = $this->buildCondition($condition, $params);

        return $having === '' ? '' : 'HAVING ' . $having;
    }

    /**
     * Builds the ORDER BY and LIMIT/OFFSET clauses and appends them to the given SQL.
     * @param string $sql the existing SQL (without ORDER BY/LIMIT/OFFSET)
     * @param array $orderBy the order by columns. See [[Query::orderBy]] for more details on how to specify this parameter.
     * @param int $limit the limit number. See [[Query::limit]] for more details.
     * @param int $offset the offset number. See [[Query::offset]] for more details.
     * @return string the SQL completed with ORDER BY/LIMIT/OFFSET (if any)
     */
    public function buildOrderByAndLimit($sql, $orderBy, $limit, $offset)
    {
        $orderBy = $this->buildOrderBy($orderBy);
        if ($orderBy !== '') {
            $sql .= $this->separator . $orderBy;
        }
        $limit = $this->buildLimit($limit, $offset);
        if ($limit !== '') {
            $sql .= $this->separator . $limit;
        }

        return $sql;
    }

    /**
     * @param array $columns
     * @return string the ORDER BY clause built from [[Query::$orderBy]].
     */
    public function buildOrderBy($columns)
    {
        if (empty($columns)) {
            return '';
        }
        $orders = [];
        foreach ($columns as $name => $direction) {
            if ($direction instanceof Expression) {
                $orders[] = $direction->expression;
            } else {
                $orders[] = $this->db->quoteColumnName($name) . ($direction === SORT_DESC ? ' DESC' : '');
            }
        }

        return 'ORDER BY ' . implode(', ', $orders);
    }

    /**
     * @param int $limit
     * @param int $offset
     * @return string the LIMIT and OFFSET clauses
     */
    public function buildLimit($limit, $offset)
    {
        $sql = '';
        if ($this->hasLimit($limit)) {
            $sql = 'LIMIT ' . $limit;
        }
        if ($this->hasOffset($offset)) {
            $sql .= ' OFFSET ' . $offset;
        }

        return ltrim($sql);
    }

    /**
     * Checks to see if the given limit is effective.
     * @param mixed $limit the given limit
     * @return bool whether the limit is effective
     */
    protected function hasLimit($limit)
    {
        return ($limit instanceof Expression) || ctype_digit((string) $limit);
    }

    /**
     * Checks to see if the given offset is effective.
     * @param mixed $offset the given offset
     * @return bool whether the offset is effective
     */
    protected function hasOffset($offset)
    {
        return ($offset instanceof Expression) || ctype_digit((string) $offset) && (string) $offset !== '0';
    }

    /**
     * @param array $unions
     * @param array $params the binding parameters to be populated
     * @return string the UNION clause built from [[Query::$union]].
     */
    public function buildUnion($unions, &$params)
    {
        if (empty($unions)) {
            return '';
        }

        $result = '';

        foreach ($unions as $i => $union) {
            $query = $union['query'];
            if ($query instanceof Query) {
                list($unions[$i]['query'], $params) = $this->build($query, $params);
            }

            $result .= 'UNION ' . ($union['all'] ? 'ALL ' : '') . '( ' . $unions[$i]['query'] . ' ) ';
        }

        return trim($result);
    }

    /**
     * Processes columns and properly quotes them if necessary.
     * It will join all columns into a string with comma as separators.
     * @param string|array $columns the columns to be processed
     * @return string the processing result
     */
    public function buildColumns($columns)
    {
        if (!is_array($columns)) {
            if (strpos($columns, '(') !== false) {
                return $columns;
            }

            $columns = preg_split('/\s*,\s*/', $columns, -1, PREG_SPLIT_NO_EMPTY);
        }
        foreach ($columns as $i => $column) {
            if ($column instanceof Expression) {
                $columns[$i] = $column->expression;
            } elseif (strpos($column, '(') === false) {
                $columns[$i] = $this->db->quoteColumnName($column);
            }
        }

        return is_array($columns) ? implode(', ', $columns) : $columns;
    }

    /**
     * Parses the condition specification and generates the corresponding SQL expression.
     * @param string|array|Expression $condition the condition specification. Please refer to [[Query::where()]]
     * on how to specify a condition.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function buildCondition($condition, &$params)
    {
        if ($condition instanceof Expression) {
            foreach ($condition->params as $n => $v) {
                $params[$n] = $v;
            }

            return $condition->expression;
        } elseif (!is_array($condition)) {
            return (string) $condition;
        } elseif (empty($condition)) {
            return '';
        }

        if (isset($condition[0])) { // operator format: operator, operand 1, operand 2, ...
            $operator = strtoupper($condition[0]);
            if (isset($this->conditionBuilders[$operator])) {
                $method = $this->conditionBuilders[$operator];
            } else {
                $method = 'buildSimpleCondition';
            }
            array_shift($condition);
            return $this->$method($operator, $condition, $params);
        }

        // hash format: 'column1' => 'value1', 'column2' => 'value2', ...
        return $this->buildHashCondition($condition, $params);
    }

    /**
     * Creates a condition based on column-value pairs.
     * @param array $condition the condition specification.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function buildHashCondition($condition, &$params)
    {
        $parts = [];
        foreach ($condition as $column => $value) {
            if (is_array($value) || $value instanceof \Traversable || $value instanceof Query) {
                // IN condition
                $parts[] = $this->buildInCondition('IN', [$column, $value], $params);
            } else {
                if (strpos($column, '(') === false) {
                    $column = $this->db->quoteColumnName($column);
                }
                if ($value === null) {
                    $parts[] = "$column IS NULL";
                } elseif ($value instanceof Expression) {
                    $parts[] = "$column=" . $value->expression;
                    foreach ($value->params as $n => $v) {
                        $params[$n] = $v;
                    }
                } else {
                    $phName = self::PARAM_PREFIX . count($params);
                    $parts[] = "$column=$phName";
                    $params[$phName] = $value;
                }
            }
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode(') AND (', $parts) . ')';
    }

    /**
     * Connects two or more SQL expressions with the `AND` or `OR` operator.
     * @param string $operator the operator to use for connecting the given operands
     * @param array $operands the SQL expressions to connect.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function buildAndCondition($operator, $operands, &$params)
    {
        $parts = [];
        foreach ($operands as $operand) {
            if (is_array($operand)) {
                $operand = $this->buildCondition($operand, $params);
            }
            if ($operand instanceof Expression) {
                foreach ($operand->params as $n => $v) {
                    $params[$n] = $v;
                }
                $operand = $operand->expression;
            }
            if ($operand !== '') {
                $parts[] = $operand;
            }
        }
        if (!empty($parts)) {
            return '(' . implode(") $operator (", $parts) . ')';
        }

        return '';
    }

    /**
     * Inverts an SQL expressions with `NOT` operator.
     * @param string $operator the operator to use for connecting the given operands
     * @param array $operands the SQL expressions to connect.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws Exception if wrong number of operands have been given.
     */
    public function buildNotCondition($operator, $operands, &$params)
    {
        if (count($operands) !== 1) {
            throw new Exception("Operator '$operator' requires exactly one operand.");
        }

        $operand = reset($operands);
        if (is_array($operand) || $operand instanceof Expression) {
            $operand = $this->buildCondition($operand, $params);
        }
        if ($operand === '') {
            return '';
        }

        return "$operator ($operand)";
    }

    /**
     * Creates an SQL expressions with the `BETWEEN` operator.
     * @param string $operator the operator to use (e.g. `BETWEEN` or `NOT BETWEEN`)
     * @param array $operands the first operand is the column name. The second and third operands
     * describe the interval that column value should be in.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws Exception if wrong number of operands have been given.
     */
    public function buildBetweenCondition($operator, $operands, &$params)
    {
        if (!isset($operands[0], $operands[1], $operands[2])) {
            throw new Exception("Operator '$operator' requires three operands.");
        }

        list($column, $value1, $value2) = $operands;

        if (strpos($column, '(') === false) {
            $column = $this->db->quoteColumnName($column);
        }
        if ($value1 instanceof Expression) {
            foreach ($value1->params as $n => $v) {
                $params[$n] = $v;
            }
            $phName1 = $value1->expression;
        } else {
            $phName1 = self::PARAM_PREFIX . count($params);
            $params[$phName1] = $value1;
        }
        if ($value2 instanceof Expression) {
            foreach ($value2->params as $n => $v) {
                $params[$n] = $v;
            }
            $phName2 = $value2->expression;
        } else {
            $phName2 = self::PARAM_PREFIX . count($params);
            $params[$phName2] = $value2;
        }

        return "$column $operator $phName1 AND $phName2";
    }

    /**
     * Creates an SQL expressions with the `IN` operator.
     * @param string $operator the operator to use (e.g. `IN` or `NOT IN`)
     * @param array $operands the first operand is the column name. If it is an array
     * a composite IN condition will be generated.
     * The second operand is an array of values that column value should be among.
     * If it is an empty array the generated expression will be a `false` value if
     * operator is `IN` and empty if operator is `NOT IN`.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws Exception if wrong number of operands have been given.
     */
    public function buildInCondition($operator, $operands, &$params)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new Exception("Operator '$operator' requires two operands.");
        }

        list($column, $values) = $operands;

        if ($column === []) {
            // no columns to test against
            return $operator === 'IN' ? '0=1' : '';
        }

        if ($values instanceof Query) {
            return $this->buildSubqueryInCondition($operator, $column, $values, $params);
        }
        if (!is_array($values) && !$values instanceof Traversable) {
            // ensure values is an array
            $values = (array) $values;
        }

        if ($column instanceof Traversable || ((is_array($column) || $column instanceof Countable) && count($column) > 1)) {
            return $this->buildCompositeInCondition($operator, $column, $values, $params);
        } elseif (is_array($column)) {
            $column = reset($column);
        }

        $sqlValues = [];
        foreach ($values as $i => $value) {
            if (is_array($value) || $value instanceof ArrayAccess) {
                $value = isset($value[$column]) ? $value[$column] : null;
            }
            if ($value === null) {
                $sqlValues[$i] = 'NULL';
            } elseif ($value instanceof Expression) {
                $sqlValues[$i] = $value->expression;
                foreach ($value->params as $n => $v) {
                    $params[$n] = $v;
                }
            } else {
                $phName = self::PARAM_PREFIX . count($params);
                $params[$phName] = $value;
                $sqlValues[$i] = $phName;
            }
        }

        if (empty($sqlValues)) {
            return $operator === 'IN' ? '0=1' : '';
        }

        if (strpos($column, '(') === false) {
            $column = $this->db->quoteColumnName($column);
        }

        if (count($sqlValues) > 1) {
            return "$column $operator (" . implode(', ', $sqlValues) . ')';
        }

        $operator = $operator === 'IN' ? '=' : '<>';
        return $column . $operator . reset($sqlValues);
    }

    /**
     * Builds SQL for IN condition.
     *
     * @param string $operator
     * @param array $columns
     * @param Query $values
     * @param array $params
     * @return string SQL
     */
    protected function buildSubqueryInCondition($operator, $columns, $values, &$params)
    {
        list($sql, $params) = $this->build($values, $params);
        if (is_array($columns)) {
            foreach ($columns as $i => $col) {
                if (strpos($col, '(') === false) {
                    $columns[$i] = $this->db->quoteColumnName($col);
                }
            }

            return '(' . implode(', ', $columns) . ") $operator ($sql)";
        }

        if (strpos($columns, '(') === false) {
            $columns = $this->db->quoteColumnName($columns);
        }

        return "$columns $operator ($sql)";
    }

    /**
     * Builds SQL for IN condition.
     *
     * @param string $operator
     * @param array|Traversable $columns
     * @param array $values
     * @param array $params
     * @return string SQL
     */
    protected function buildCompositeInCondition($operator, $columns, $values, &$params)
    {
        $vss = [];
        foreach ($values as $value) {
            $vs = [];
            foreach ($columns as $column) {
                if (isset($value[$column])) {
                    $phName = self::PARAM_PREFIX . count($params);
                    $params[$phName] = $value[$column];
                    $vs[] = $phName;
                } else {
                    $vs[] = 'NULL';
                }
            }
            $vss[] = '(' . implode(', ', $vs) . ')';
        }

        if (empty($vss)) {
            return $operator === 'IN' ? '0=1' : '';
        }

        $sqlColumns = [];
        foreach ($columns as $i => $column) {
            $sqlColumns[] = strpos($column, '(') === false ? $this->db->quoteColumnName($column) : $column;
        }

        return '(' . implode(', ', $sqlColumns) . ") $operator (" . implode(', ', $vss) . ')';
    }

    /**
     * Creates an SQL expressions with the `LIKE` operator.
     * @param string $operator the operator to use (e.g. `LIKE`, `NOT LIKE`, `OR LIKE` or `OR NOT LIKE`)
     * @param array $operands an array of two or three operands
     *
     * - The first operand is the column name.
     * - The second operand is a single value or an array of values that column value
     *   should be compared with. If it is an empty array the generated expression will
     *   be a `false` value if operator is `LIKE` or `OR LIKE`, and empty if operator
     *   is `NOT LIKE` or `OR NOT LIKE`.
     * - An optional third operand can also be provided to specify how to escape special characters
     *   in the value(s). The operand should be an array of mappings from the special characters to their
     *   escaped counterparts. If this operand is not provided, a default escape mapping will be used.
     *   You may use `false` or an empty array to indicate the values are already escaped and no escape
     *   should be applied. Note that when using an escape mapping (or the third operand is not provided),
     *   the values will be automatically enclosed within a pair of percentage characters.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws Exception if wrong number of operands have been given.
     */
    public function buildLikeCondition($operator, $operands, &$params)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new Exception("Operator '$operator' requires two operands.");
        }

        $escape = isset($operands[2]) ? $operands[2] : $this->likeEscapingReplacements;
        unset($operands[2]);

        if (!preg_match('/^(AND |OR |)(((NOT |))I?LIKE)/', $operator, $matches)) {
            throw new Exception("Invalid operator '$operator'.");
        }
        $andor = ' ' . (!empty($matches[1]) ? $matches[1] : 'AND ');
        $not = !empty($matches[3]);
        $operator = $matches[2];

        list($column, $values) = $operands;

        if (!is_array($values)) {
            $values = [$values];
        }

        if (empty($values)) {
            return $not ? '' : '0=1';
        }

        if (strpos($column, '(') === false) {
            $column = $this->db->quoteColumnName($column);
        }

        $parts = [];
        foreach ($values as $value) {
            if ($value instanceof Expression) {
                foreach ($value->params as $n => $v) {
                    $params[$n] = $v;
                }
                $phName = $value->expression;
            } else {
                $phName = self::PARAM_PREFIX . count($params);
                $params[$phName] = empty($escape) ? $value : ('%' . strtr($value, $escape) . '%');
            }
            $escapeSql = '';
            if ($this->likeEscapeCharacter !== null) {
                $escapeSql = " ESCAPE '{$this->likeEscapeCharacter}'";
            }
            $parts[] = "{$column} {$operator} {$phName}{$escapeSql}";
        }

        return implode($andor, $parts);
    }

    /**
     * Creates an SQL expressions with the `EXISTS` operator.
     * @param string $operator the operator to use (e.g. `EXISTS` or `NOT EXISTS`)
     * @param array $operands contains only one element which is a [[Query]] object representing the sub-query.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws Exception if the operand is not a [[Query]] object.
     */
    public function buildExistsCondition($operator, $operands, &$params)
    {
        if ($operands[0] instanceof Query) {
            list($sql, $params) = $this->build($operands[0], $params);
            return "$operator ($sql)";
        }

        throw new Exception('Subquery for EXISTS operator must be a Query object.');
    }

    /**
     * Creates an SQL expressions like `"column" operator value`.
     * @param string $operator the operator to use. Anything could be used e.g. `>`, `<=`, etc.
     * @param array $operands contains two column names.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws Exception if wrong number of operands have been given.
     */
    public function buildSimpleCondition($operator, $operands, &$params)
    {
        if (count($operands) !== 2) {
            throw new Exception("Operator '$operator' requires two operands.");
        }

        list($column, $value) = $operands;

        if (strpos($column, '(') === false) {
            $column = $this->db->quoteColumnName($column);
        }

        if ($value === null) {
            return "$column $operator NULL";
        } elseif ($value instanceof Expression) {
            foreach ($value->params as $n => $v) {
                $params[$n] = $v;
            }

            return "$column $operator {$value->expression}";
        } elseif ($value instanceof Query) {
            list($sql, $params) = $this->build($value, $params);
            return "$column $operator ($sql)";
        }

        $phName = self::PARAM_PREFIX . count($params);
        $params[$phName] = $value;
        return "$column $operator $phName";
    }

    /**
     * Creates a SELECT EXISTS() SQL statement.
     * @param string $rawSql the subquery in a raw form to select from.
     * @return string the SELECT EXISTS() SQL statement.
     */
    public function selectExists($rawSql)
    {
        return 'SELECT EXISTS(' . $rawSql . ')';
    }
}