<?php


namespace pfilsx\db_broker;

use Closure;
use Exception;

/**
 * Class Query
 * @package db_broker
 *
 * @property string[] $tablesUsedInFrom Table names indexed by aliases. This property is read-only.
 */
class Query extends BaseObject implements QueryInterface
{
    /**
     * @var array the columns being selected. For example, `['id', 'name']`.
     * This is used to construct the SELECT clause in a SQL statement. If not set, it means selecting all columns.
     * @see select()
     */
    public $select;
    /**
     * @var string additional option that should be appended to the 'SELECT' keyword. For example,
     * in MySQL, the option 'SQL_CALC_FOUND_ROWS' can be used.
     */
    public $selectOption;
    /**
     * @var bool whether to select distinct rows of data only. If this is set true,
     * the SELECT clause would be changed to SELECT DISTINCT.
     */
    public $distinct;
    /**
     * @var array the table(s) to be selected from. For example, `['user', 'post']`.
     * This is used to construct the FROM clause in a SQL statement.
     * @see from()
     */
    public $from;
    /**
     * @var array how to group the query results. For example, `['company', 'department']`.
     * This is used to construct the GROUP BY clause in a SQL statement.
     */
    public $groupBy;
    /**
     * @var array how to join with other tables. Each array element represents the specification
     * of one join which has the following structure:
     *
     * ```php
     * [$joinType, $tableName, $joinCondition]
     * ```
     *
     * For example,
     *
     * ```php
     * [
     *     ['INNER JOIN', 'user', 'user.id = author_id'],
     *     ['LEFT JOIN', 'team', 'team.id = team_id'],
     * ]
     * ```
     */
    public $join;
    /**
     * @var string|array|Expression the condition to be applied in the GROUP BY clause.
     * It can be either a string or an array. Please refer to [[where()]] on how to specify the condition.
     */
    public $having;
    /**
     * @var array this is used to construct the UNION clause(s) in a SQL statement.
     * Each array element is an array of the following structure:
     *
     * - `query`: either a string or a [[Query]] object representing a query
     * - `all`: boolean, whether it should be `UNION ALL` or `UNION`
     */
    public $union;
    /**
     * @var array list of query parameter values indexed by parameter placeholders.
     * For example, `[':name' => 'Dan', ':age' => 31]`.
     */
    public $params = [];

    /**
     * @var string|array query condition. This refers to the WHERE clause in a SQL statement.
     * For example, `['age' => 31, 'team' => 1]`.
     * @see where() for valid syntax on specifying this value.
     */
    public $where;
    /**
     * @var int|Expression maximum number of records to be returned. May be an instance of [[Expression]].
     * If not set or less than 0, it means no limit.
     */
    public $limit;
    /**
     * @var int|Expression zero-based offset from where the records are to be returned.
     * May be an instance of [[Expression]]. If not set or less than 0, it means starting from the beginning.
     */
    public $offset;
    /**
     * @var array how to sort the query results. This is used to construct the ORDER BY clause in a SQL statement.
     * The array keys are the columns to be sorted by, and the array values are the corresponding sort directions which
     * can be either [SORT_ASC](http://php.net/manual/en/array.constants.php#constant.sort-asc)
     * or [SORT_DESC](http://php.net/manual/en/array.constants.php#constant.sort-desc).
     * The array may also contain [[Expression]] objects. If that is the case, the expressions
     * will be converted into strings without any change.
     */
    public $orderBy;
    /**
     * @var string|callable the name of the column by which the query results should be indexed by.
     * This can also be a callable (e.g. anonymous function) that returns the index value based on the given
     * row data. For more details, see [[indexBy()]]. This property is only used by [[QueryInterface::all()|all()]].
     */
    public $indexBy;
    /**
     * @var Connection
     */
    public $db;

    /**
     * Creates a DB command that can be used to execute this query.
     * @return Command the created DB command instance.
     */
    public function createCommand()
    {
        list($sql, $params) = $this->db->getQueryBuilder()->build($this);

        return $this->db->createCommand($sql, $params);
    }

    /**
     * Prepares for building SQL.
     * This method is called by [[QueryBuilder]] when it starts to build SQL from a query object.
     * You may override this method to do some final preparation work when converting a query into a SQL statement.
     * @param QueryBuilder $builder
     * @return $this a prepared query instance which will be used by [[QueryBuilder]] to build the SQL
     */
    public function prepare($builder)
    {
        return $this;
    }

    public function all()
    {
        $rows = $this->createCommand()->queryAll();
        return $this->populate($rows);
    }

    /**
     * Converts the raw query results into the format as specified by this query.
     * This method is internally used to convert the data fetched from database
     * into the format as required by this query.
     * @param array $rows the raw query result from database
     * @return array the converted query result
     */
    public function populate($rows)
    {
        if ($this->indexBy === null) {
            return $rows;
        }
        $result = [];
        foreach ($rows as $row) {
            if (is_string($this->indexBy)) {
                $key = $row[$this->indexBy];
            } else {
                $key = call_user_func($this->indexBy, $row);
            }
            $result[$key] = $row;
        }

        return $result;
    }

    /**
     * Executes the query and returns a single row of result.
     * @return array|bool the first row (in terms of an array) of the query result. False is returned if the query
     * results in nothing.
     */
    public function one()
    {
        return $this->createCommand()->queryOne();
    }

    /**
     * Returns the query result as a scalar value.
     * The value returned will be the first column in the first row of the query results.
     * @return string|null|false the value of the first column in the first row of the query result.
     * False is returned if the query result is empty.
     */
    public function scalar()
    {
        return $this->createCommand()->queryScalar();
    }

    /**
     * Executes the query and returns the first column of the result.
     * @return array the first column of the query result. An empty array is returned if the query results in nothing.
     */
    public function column()
    {
        if ($this->indexBy === null) {
            return $this->createCommand()->queryColumn();
        }

        if (is_string($this->indexBy) && is_array($this->select) && count($this->select) === 1) {
            if (strpos($this->indexBy, '.') === false && count($tables = $this->getTablesUsedInFrom()) > 0) {
                $this->select[] = key($tables) . '.' . $this->indexBy;
            } else {
                $this->select[] = $this->indexBy;
            }
        }
        $rows = $this->createCommand()->queryAll();
        $results = [];
        foreach ($rows as $row) {
            $value = reset($row);

            if ($this->indexBy instanceof Closure) {
                $results[call_user_func($this->indexBy, $row)] = $value;
            } else {
                $results[$row[$this->indexBy]] = $value;
            }
        }

        return $results;
    }

    /**
     * Returns the number of records.
     * @param string $q the COUNT expression. Defaults to '*'.
     * Make sure you properly [quote](guide:db-dao#quoting-table-and-column-names) column names in the expression.
     * @return int|string number of records. The result may be a string depending on the
     * underlying database engine and to support integer values higher than a 32bit PHP integer can handle.
     */
    public function count($q = '*')
    {
        return $this->queryScalar("COUNT($q)");
    }

    /**
     * Returns the sum of the specified column values.
     * @param string $q the column name or expression.
     * Make sure you properly [quote](guide:db-dao#quoting-table-and-column-names) column names in the expression.
     * @return mixed the sum of the specified column values.
     */
    public function sum($q)
    {
        return $this->queryScalar("SUM($q)");
    }

    /**
     * Returns the average of the specified column values.
     * @param string $q the column name or expression.
     * Make sure you properly [quote](guide:db-dao#quoting-table-and-column-names) column names in the expression.
     * @return mixed the average of the specified column values.
     */
    public function average($q)
    {
        return $this->queryScalar("AVG($q)");
    }

    /**
     * Returns the minimum of the specified column values.
     * @param string $q the column name or expression.
     * Make sure you properly [quote](guide:db-dao#quoting-table-and-column-names) column names in the expression.
     * @return mixed the minimum of the specified column values.
     */
    public function min($q)
    {
        return $this->queryScalar("MIN($q)");
    }

    /**
     * Returns the maximum of the specified column values.
     * @param string $q the column name or expression.
     * Make sure you properly [quote](guide:db-dao#quoting-table-and-column-names) column names in the expression.
     * @return mixed the maximum of the specified column values.
     */
    public function max($q)
    {
        return $this->queryScalar("MAX($q)");
    }

    /**
     * Returns a value indicating whether the query result contains any row of data.
     * @return bool whether the query result contains any row of data.
     */
    public function exists()
    {
        $command = $this->createCommand();
        $params = $command->params;
        $command->setSql($command->db->getQueryBuilder()->selectExists($command->getSql()));
        $command->bindValues($params);
        return (bool) $command->queryScalar();
    }

    /**
     * Queries a scalar value by setting [[select]] first.
     * Restores the value of select to make this query reusable.
     * @param string|Expression $selectExpression
     * @return bool|string
     */
    protected function queryScalar($selectExpression)
    {
        if (
            !$this->distinct
            && empty($this->groupBy)
            && empty($this->having)
            && empty($this->union)
        ) {
            $select = $this->select;
            $order = $this->orderBy;
            $limit = $this->limit;
            $offset = $this->offset;

            $this->select = [$selectExpression];
            $this->orderBy = null;
            $this->limit = null;
            $this->offset = null;
            $command = $this->createCommand();

            $this->select = $select;
            $this->orderBy = $order;
            $this->limit = $limit;
            $this->offset = $offset;

            return $command->queryScalar();
        }

        return (new self())
            ->select([$selectExpression])
            ->from(['c' => $this])
            ->createCommand()
            ->queryScalar();
    }

    /**
     * Returns table names used in [[from]] indexed by aliases.
     * Both aliases and names are enclosed into {{ and }}.
     * @return string[] table names indexed by aliases
     * @throws Exception
     */
    public function getTablesUsedInFrom()
    {
        if (empty($this->from)) {
            return [];
        } elseif (is_array($this->from)) {
            $tableNames = $this->from;
        } elseif (is_string($this->from)) {
            $tableNames = preg_split('/\s*,\s*/', trim($this->from), -1, PREG_SPLIT_NO_EMPTY);
        } else {
            throw new Exception(gettype($this->from) . ' in $from is not supported.');
        }

        // Clean up table names and aliases
        $cleanedUpTableNames = [];
        foreach ($tableNames as $alias => $tableName) {
            if (!is_string($alias)) {
                $pattern = <<<PATTERN
~
^
\s*
(
    (?:['"`\[]|{{)
    .*?
    (?:['"`\]]|}})
    |
    .*?
)
(?:
    (?:
        \s+
        (?:as)?
        \s*
    )
    (
       (?:['"`\[]|{{)
        .*?
        (?:['"`\]]|}})
        |
        .*?
    )
)?
\s*
$
~iux
PATTERN;
                if (preg_match($pattern, $tableName, $matches)) {
                    if (isset($matches[1])) {
                        if (isset($matches[2])) {
                            list(, $tableName, $alias) = $matches;
                        } else {
                            $tableName = $alias = $matches[1];
                        }
                        if (strncmp($alias, '{{', 2) !== 0) {
                            $alias = '{{' . $alias . '}}';
                        }
                        if (strncmp($tableName, '{{', 2) !== 0) {
                            $tableName = '{{' . $tableName . '}}';
                        }
                    }
                }
            }

            $tableName = str_replace(["'", '"', '`', '[', ']'], '', $tableName);
            $alias = str_replace(["'", '"', '`', '[', ']'], '', $alias);

            $cleanedUpTableNames[$alias] = $tableName;
        }

        return $cleanedUpTableNames;
    }

    /**
     * Sets the SELECT part of the query.
     * @param string|array|Expression $columns the columns to be selected.
     * Columns can be specified in either a string (e.g. "id, name") or an array (e.g. ['id', 'name']).
     * Columns can be prefixed with table names (e.g. "user.id") and/or contain column aliases (e.g. "user.id AS user_id").
     * The method will automatically quote the column names unless a column contains some parenthesis
     * (which means the column contains a DB expression). A DB expression may also be passed in form of
     * an [[Expression]] object.
     *
     * Note that if you are selecting an expression like `CONCAT(first_name, ' ', last_name)`, you should
     * use an array to specify the columns. Otherwise, the expression may be incorrectly split into several parts.
     *
     * When the columns are specified as an array, you may also use array keys as the column aliases (if a column
     * does not need alias, do not use a string key).
     *
     *
     * @param string $option additional option that should be appended to the 'SELECT' keyword. For example,
     * in MySQL, the option 'SQL_CALC_FOUND_ROWS' can be used.
     * @return $this the query object itself
     */
    public function select($columns, $option = null)
    {
        if ($columns instanceof Expression) {
            $columns = [$columns];
        } elseif (!is_array($columns)) {
            $columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
        }
        $this->select = $columns;
        $this->selectOption = $option;
        return $this;
    }

    /**
     * Add more columns to the SELECT part of the query.
     *
     * Note, that if [[select]] has not been specified before, you should include `*` explicitly
     * if you want to select all remaining columns too:
     *
     * ```php
     * $query->addSelect(["*", "CONCAT(first_name, ' ', last_name) AS full_name"])->one();
     * ```
     *
     * @param string|array|Expression $columns the columns to add to the select. See [[select()]] for more
     * details about the format of this parameter.
     * @return $this the query object itself
     * @see select()
     */
    public function addSelect($columns)
    {
        if ($columns instanceof Expression) {
            $columns = [$columns];
        } elseif (!is_array($columns)) {
            $columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
        }
        if ($this->select === null) {
            $this->select = $columns;
        } else {
            $this->select = array_merge($this->select, $columns);
        }

        return $this;
    }

    /**
     * Sets the value indicating whether to SELECT DISTINCT or not.
     * @param bool $value whether to SELECT DISTINCT or not.
     * @return $this the query object itself
     */
    public function distinct($value = true)
    {
        $this->distinct = $value;
        return $this;
    }

    /**
     * Sets the FROM part of the query.
     * @param string|array|Expression $tables the table(s) to be selected from. This can be either a string (e.g. `'user'`)
     * or an array (e.g. `['user', 'profile']`) specifying one or several table names.
     * Table names can contain schema prefixes (e.g. `'public.user'`) and/or table aliases (e.g. `'user u'`).
     * The method will automatically quote the table names unless it contains some parenthesis
     * (which means the table is given as a sub-query or DB expression).
     *
     * When the tables are specified as an array, you may also use the array keys as the table aliases
     * (if a table does not need alias, do not use a string key).
     *
     * Use a Query object to represent a sub-query. In this case, the corresponding array key will be used
     * as the alias for the sub-query.
     *
     * To specify the `FROM` part in plain SQL, you may pass an instance of [[Expression]].
     *
     * Here are some examples:
     *
     * ```php
     * // SELECT * FROM  `user` `u`, `profile`;
     * $query = (new \db_broker\Query)->from(['u' => 'user', 'profile']);
     *
     * // SELECT * FROM (SELECT * FROM `user` WHERE `active` = 1) `activeusers`;
     * $subquery = (new \db_broker\Query)->from('user')->where(['active' => true])
     * $query = (new \db_broker\Query)->from(['activeusers' => $subquery]);
     *
     * // subquery can also be a string with plain SQL wrapped in parenthesis
     * // SELECT * FROM (SELECT * FROM `user` WHERE `active` = 1) `activeusers`;
     * $subquery = "(SELECT * FROM `user` WHERE `active` = 1)";
     * $query = (new \db_broker\Query)->from(['activeusers' => $subquery]);
     * ```
     *
     * @return $this the query object itself
     */
    public function from($tables)
    {
        if (!is_array($tables)) {
            $tables = preg_split('/\s*,\s*/', trim($tables), -1, PREG_SPLIT_NO_EMPTY);
        }
        $this->from = $tables;
        return $this;
    }

    /**
     * Sets the WHERE part of the query.
     *
     * The method requires a `$condition` parameter, and optionally a `$params` parameter
     * specifying the values to be bound to the query.
     *
     * The `$condition` parameter should be either a string (e.g. `'id=1'`) or an array.
     *
     * @inheritdoc
     *
     * @param string|array|Expression $condition the conditions that should be put in the WHERE part.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     * @see andWhere()
     * @see orWhere()
     * @see QueryInterface::where()
     */
    public function where($condition, $params = [])
    {
        $this->where = $condition;
        $this->addParams($params);
        return $this;
    }

    /**
     * Adds an additional WHERE condition to the existing one.
     * The new condition and the existing one will be joined using the `AND` operator.
     * @param string|array|Expression $condition the new WHERE condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     * @see where()
     * @see orWhere()
     */
    public function andWhere($condition, $params = [])
    {
        if ($this->where === null) {
            $this->where = $condition;
        } elseif (is_array($this->where) && isset($this->where[0]) && strcasecmp($this->where[0], 'and') === 0) {
            $this->where[] = $condition;
        } else {
            $this->where = ['and', $this->where, $condition];
        }
        $this->addParams($params);
        return $this;
    }

    /**
     * Adds an additional WHERE condition to the existing one.
     * The new condition and the existing one will be joined using the `OR` operator.
     * @param string|array|Expression $condition the new WHERE condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     * @see where()
     * @see andWhere()
     */
    public function orWhere($condition, $params = [])
    {
        if ($this->where === null) {
            $this->where = $condition;
        } else {
            $this->where = ['or', $this->where, $condition];
        }
        $this->addParams($params);
        return $this;
    }

    /**
     * Adds a filtering condition for a specific column and allow the user to choose a filter operator.
     *
     * It adds an additional WHERE condition for the given field and determines the comparison operator
     * based on the first few characters of the given value.
     * The condition is added in the same way as in [[andFilterWhere]] so [[isEmpty()|empty values]] are ignored.
     * The new condition and the existing one will be joined using the `AND` operator.
     *
     * The comparison operator is intelligently determined based on the first few characters in the given value.
     * In particular, it recognizes the following operators if they appear as the leading characters in the given value:
     *
     * - `<`: the column must be less than the given value.
     * - `>`: the column must be greater than the given value.
     * - `<=`: the column must be less than or equal to the given value.
     * - `>=`: the column must be greater than or equal to the given value.
     * - `<>`: the column must not be the same as the given value.
     * - `=`: the column must be equal to the given value.
     * - If none of the above operators is detected, the `$defaultOperator` will be used.
     *
     * @param string $name the column name.
     * @param string $value the column value optionally prepended with the comparison operator.
     * @param string $defaultOperator The operator to use, when no operator is given in `$value`.
     * Defaults to `=`, performing an exact match.
     * @return $this The query object itself
     */
    public function andFilterCompare($name, $value, $defaultOperator = '=')
    {
        if (preg_match('/^(<>|>=|>|<=|<|=)/', $value, $matches)) {
            $operator = $matches[1];
            $value = substr($value, strlen($operator));
        } else {
            $operator = $defaultOperator;
        }

        return $this->andFilterWhere([$operator, $name, $value]);
    }

    /**
     * Appends a JOIN part to the query.
     * The first parameter specifies what type of join it is.
     * @param string $type the type of join, such as INNER JOIN, LEFT JOIN.
     * @param string|array $table the table to be joined.
     *
     * Use a string to represent the name of the table to be joined.
     * The table name can contain a schema prefix (e.g. 'public.user') and/or table alias (e.g. 'user u').
     * The method will automatically quote the table name unless it contains some parenthesis
     * (which means the table is given as a sub-query or DB expression).
     *
     * Use an array to represent joining with a sub-query. The array must contain only one element.
     * The value must be a [[Query]] object representing the sub-query while the corresponding key
     * represents the alias for the sub-query.
     *
     * @param string|array $on the join condition that should appear in the ON part.
     * Please refer to [[where()]] on how to specify this parameter.
     *
     * Note that the array format of [[where()]] is designed to match columns to values instead of columns to columns, so
     * the following would **not** work as expected: `['post.author_id' => 'user.id']`, it would
     * match the `post.author_id` column value against the string `'user.id'`.
     * It is recommended to use the string syntax here which is more suited for a join:
     *
     * ```php
     * 'post.author_id = user.id'
     * ```
     *
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     */
    public function join($type, $table, $on = '', $params = [])
    {
        $this->join[] = [$type, $table, $on];
        return $this->addParams($params);
    }

    /**
     * Appends an INNER JOIN part to the query.
     * @param string|array $table the table to be joined.
     *
     * Use a string to represent the name of the table to be joined.
     * The table name can contain a schema prefix (e.g. 'public.user') and/or table alias (e.g. 'user u').
     * The method will automatically quote the table name unless it contains some parenthesis
     * (which means the table is given as a sub-query or DB expression).
     *
     * Use an array to represent joining with a sub-query. The array must contain only one element.
     * The value must be a [[Query]] object representing the sub-query while the corresponding key
     * represents the alias for the sub-query.
     *
     * @param string|array $on the join condition that should appear in the ON part.
     * Please refer to [[join()]] on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     */
    public function innerJoin($table, $on = '', $params = [])
    {
        $this->join[] = ['INNER JOIN', $table, $on];
        return $this->addParams($params);
    }

    /**
     * Appends a LEFT OUTER JOIN part to the query.
     * @param string|array $table the table to be joined.
     *
     * Use a string to represent the name of the table to be joined.
     * The table name can contain a schema prefix (e.g. 'public.user') and/or table alias (e.g. 'user u').
     * The method will automatically quote the table name unless it contains some parenthesis
     * (which means the table is given as a sub-query or DB expression).
     *
     * Use an array to represent joining with a sub-query. The array must contain only one element.
     * The value must be a [[Query]] object representing the sub-query while the corresponding key
     * represents the alias for the sub-query.
     *
     * @param string|array $on the join condition that should appear in the ON part.
     * Please refer to [[join()]] on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query
     * @return $this the query object itself
     */
    public function leftJoin($table, $on = '', $params = [])
    {
        $this->join[] = ['LEFT JOIN', $table, $on];
        return $this->addParams($params);
    }

    /**
     * Appends a RIGHT OUTER JOIN part to the query.
     * @param string|array $table the table to be joined.
     *
     * Use a string to represent the name of the table to be joined.
     * The table name can contain a schema prefix (e.g. 'public.user') and/or table alias (e.g. 'user u').
     * The method will automatically quote the table name unless it contains some parenthesis
     * (which means the table is given as a sub-query or DB expression).
     *
     * Use an array to represent joining with a sub-query. The array must contain only one element.
     * The value must be a [[Query]] object representing the sub-query while the corresponding key
     * represents the alias for the sub-query.
     *
     * @param string|array $on the join condition that should appear in the ON part.
     * Please refer to [[join()]] on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query
     * @return $this the query object itself
     */
    public function rightJoin($table, $on = '', $params = [])
    {
        $this->join[] = ['RIGHT JOIN', $table, $on];
        return $this->addParams($params);
    }

    /**
     * Sets the GROUP BY part of the query.
     * @param string|array|Expression $columns the columns to be grouped by.
     * Columns can be specified in either a string (e.g. "id, name") or an array (e.g. ['id', 'name']).
     * The method will automatically quote the column names unless a column contains some parenthesis
     * (which means the column contains a DB expression).
     *
     * Note that if your group-by is an expression containing commas, you should always use an array
     * to represent the group-by information. Otherwise, the method will not be able to correctly determine
     * the group-by columns.
     *
     * @return $this the query object itself
     * @see addGroupBy()
     */
    public function groupBy($columns)
    {
        if ($columns instanceof Expression) {
            $columns = [$columns];
        } elseif (!is_array($columns)) {
            $columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
        }
        $this->groupBy = $columns;
        return $this;
    }

    /**
     * Adds additional group-by columns to the existing ones.
     * @param string|array $columns additional columns to be grouped by.
     * Columns can be specified in either a string (e.g. "id, name") or an array (e.g. ['id', 'name']).
     * The method will automatically quote the column names unless a column contains some parenthesis
     * (which means the column contains a DB expression).
     *
     * Note that if your group-by is an expression containing commas, you should always use an array
     * to represent the group-by information. Otherwise, the method will not be able to correctly determine
     * the group-by columns.
     *
     * @return $this the query object itself
     * @see groupBy()
     */
    public function addGroupBy($columns)
    {
        if ($columns instanceof Expression) {
            $columns = [$columns];
        } elseif (!is_array($columns)) {
            $columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
        }
        if ($this->groupBy === null) {
            $this->groupBy = $columns;
        } else {
            $this->groupBy = array_merge($this->groupBy, $columns);
        }

        return $this;
    }

    /**
     * Sets the HAVING part of the query.
     * @param string|array|Expression $condition the conditions to be put after HAVING.
     * Please refer to [[where()]] on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     * @see andHaving()
     * @see orHaving()
     */
    public function having($condition, $params = [])
    {
        $this->having = $condition;
        $this->addParams($params);
        return $this;
    }

    /**
     * Adds an additional HAVING condition to the existing one.
     * The new condition and the existing one will be joined using the `AND` operator.
     * @param string|array|Expression $condition the new HAVING condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     * @see having()
     * @see orHaving()
     */
    public function andHaving($condition, $params = [])
    {
        if ($this->having === null) {
            $this->having = $condition;
        } else {
            $this->having = ['and', $this->having, $condition];
        }
        $this->addParams($params);
        return $this;
    }

    /**
     * Adds an additional HAVING condition to the existing one.
     * The new condition and the existing one will be joined using the `OR` operator.
     * @param string|array|Expression $condition the new HAVING condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     * @see having()
     * @see andHaving()
     */
    public function orHaving($condition, $params = [])
    {
        if ($this->having === null) {
            $this->having = $condition;
        } else {
            $this->having = ['or', $this->having, $condition];
        }
        $this->addParams($params);
        return $this;
    }

    /**
     * Sets the HAVING part of the query but ignores [[isEmpty()|empty operands]].
     *
     * This method is similar to [[having()]]. The main difference is that this method will
     * remove [[isEmpty()|empty query operands]]. As a result, this method is best suited
     * for building query conditions based on filter values entered by users.
     *
     * The following code shows the difference between this method and [[having()]]:
     *
     * ```php
     * // HAVING `age`=:age
     * $query->filterHaving(['name' => null, 'age' => 20]);
     * // HAVING `age`=:age
     * $query->having(['age' => 20]);
     * // HAVING `name` IS NULL AND `age`=:age
     * $query->having(['name' => null, 'age' => 20]);
     * ```
     *
     * Note that unlike [[having()]], you cannot pass binding parameters to this method.
     *
     * @param array $condition the conditions that should be put in the HAVING part.
     * See [[having()]] on how to specify this parameter.
     * @return $this the query object itself
     * @see having()
     * @see andFilterHaving()
     * @see orFilterHaving()
     */
    public function filterHaving(array $condition)
    {
        $condition = $this->filterCondition($condition);
        if ($condition !== []) {
            $this->having($condition);
        }

        return $this;
    }

    /**
     * Adds an additional HAVING condition to the existing one but ignores [[isEmpty()|empty operands]].
     * The new condition and the existing one will be joined using the `AND` operator.
     *
     * This method is similar to [[andHaving()]]. The main difference is that this method will
     * remove [[isEmpty()|empty query operands]]. As a result, this method is best suited
     * for building query conditions based on filter values entered by users.
     *
     * @param array $condition the new HAVING condition. Please refer to [[having()]]
     * on how to specify this parameter.
     * @return $this the query object itself
     * @see filterHaving()
     * @see orFilterHaving()
     */
    public function andFilterHaving(array $condition)
    {
        $condition = $this->filterCondition($condition);
        if ($condition !== []) {
            $this->andHaving($condition);
        }

        return $this;
    }

    /**
     * Adds an additional HAVING condition to the existing one but ignores [[isEmpty()|empty operands]].
     * The new condition and the existing one will be joined using the `OR` operator.
     *
     * This method is similar to [[orHaving()]]. The main difference is that this method will
     * remove [[isEmpty()|empty query operands]]. As a result, this method is best suited
     * for building query conditions based on filter values entered by users.
     *
     * @param array $condition the new HAVING condition. Please refer to [[having()]]
     * on how to specify this parameter.
     * @return $this the query object itself
     * @see filterHaving()
     * @see andFilterHaving()
     */
    public function orFilterHaving(array $condition)
    {
        $condition = $this->filterCondition($condition);
        if ($condition !== []) {
            $this->orHaving($condition);
        }

        return $this;
    }

    /**
     * Appends a SQL statement using UNION operator.
     * @param string|Query $sql the SQL statement to be appended using UNION
     * @param bool $all TRUE if using UNION ALL and FALSE if using UNION
     * @return $this the query object itself
     */
    public function union($sql, $all = false)
    {
        $this->union[] = ['query' => $sql, 'all' => $all];
        return $this;
    }

    /**
     * Sets the parameters to be bound to the query.
     * @param array $params list of query parameter values indexed by parameter placeholders.
     * For example, `[':name' => 'Dan', ':age' => 31]`.
     * @return $this the query object itself
     * @see addParams()
     */
    public function params($params)
    {
        $this->params = $params;
        return $this;
    }

    /**
     * Adds additional parameters to be bound to the query.
     * @param array $params list of query parameter values indexed by parameter placeholders.
     * For example, `[':name' => 'Dan', ':age' => 31]`.
     * @return $this the query object itself
     * @see params()
     */
    public function addParams($params)
    {
        if (!empty($params)) {
            if (empty($this->params)) {
                $this->params = $params;
            } else {
                foreach ($params as $name => $value) {
                    if (is_int($name)) {
                        $this->params[] = $value;
                    } else {
                        $this->params[$name] = $value;
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Sets the [[indexBy]] property.
     * @param string|callable $column the name of the column by which the query results should be indexed by.
     * This can also be a callable (e.g. anonymous function) that returns the index value based on the given
     * row data. The signature of the callable should be:
     *
     * ```php
     * function ($row)
     * {
     *     // return the index value corresponding to $row
     * }
     * ```
     *
     * @return $this the query object itself
     */
    public function indexBy($column)
    {
        $this->indexBy = $column;
        return $this;
    }

    /**
     * Sets the WHERE part of the query but ignores [[isEmpty()|empty operands]].
     *
     * This method is similar to [[where()]]. The main difference is that this method will
     * remove [[isEmpty()|empty query operands]]. As a result, this method is best suited
     * for building query conditions based on filter values entered by users.
     *
     * The following code shows the difference between this method and [[where()]]:
     *
     * ```php
     * // WHERE `age`=:age
     * $query->filterWhere(['name' => null, 'age' => 20]);
     * // WHERE `age`=:age
     * $query->where(['age' => 20]);
     * // WHERE `name` IS NULL AND `age`=:age
     * $query->where(['name' => null, 'age' => 20]);
     * ```
     *
     * Note that unlike [[where()]], you cannot pass binding parameters to this method.
     *
     * @param array $condition the conditions that should be put in the WHERE part.
     * See [[where()]] on how to specify this parameter.
     * @return $this the query object itself
     * @see where()
     * @see andFilterWhere()
     * @see orFilterWhere()
     */
    public function filterWhere(array $condition)
    {
        $condition = $this->filterCondition($condition);
        if ($condition !== []) {
            $this->where($condition);
        }

        return $this;
    }

    /**
     * Adds an additional WHERE condition to the existing one but ignores [[isEmpty()|empty operands]].
     * The new condition and the existing one will be joined using the 'AND' operator.
     *
     * This method is similar to [[andWhere()]]. The main difference is that this method will
     * remove [[isEmpty()|empty query operands]]. As a result, this method is best suited
     * for building query conditions based on filter values entered by users.
     *
     * @param array $condition the new WHERE condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @return $this the query object itself
     * @see filterWhere()
     * @see orFilterWhere()
     */
    public function andFilterWhere(array $condition)
    {
        $condition = $this->filterCondition($condition);
        if ($condition !== []) {
            $this->andWhere($condition);
        }

        return $this;
    }

    /**
     * Adds an additional WHERE condition to the existing one but ignores [[isEmpty()|empty operands]].
     * The new condition and the existing one will be joined using the 'OR' operator.
     *
     * This method is similar to [[orWhere()]]. The main difference is that this method will
     * remove [[isEmpty()|empty query operands]]. As a result, this method is best suited
     * for building query conditions based on filter values entered by users.
     *
     * @param array $condition the new WHERE condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @return $this the query object itself
     * @see filterWhere()
     * @see andFilterWhere()
     */
    public function orFilterWhere(array $condition)
    {
        $condition = $this->filterCondition($condition);
        if ($condition !== []) {
            $this->orWhere($condition);
        }

        return $this;
    }

    /**
     * Removes [[isEmpty()|empty operands]] from the given query condition.
     *
     * @param array $condition the original condition
     * @return array the condition with [[isEmpty()|empty operands]] removed.
     * @throws Exception if the condition operator is not supported
     */
    protected function filterCondition($condition)
    {
        if (!is_array($condition)) {
            return $condition;
        }

        if (!isset($condition[0])) {
            // hash format: 'column1' => 'value1', 'column2' => 'value2', ...
            foreach ($condition as $name => $value) {
                if ($this->isEmpty($value)) {
                    unset($condition[$name]);
                }
            }

            return $condition;
        }

        // operator format: operator, operand 1, operand 2, ...

        $operator = array_shift($condition);

        switch (strtoupper($operator)) {
            case 'NOT':
            case 'AND':
            case 'OR':
                foreach ($condition as $i => $operand) {
                    $subCondition = $this->filterCondition($operand);
                    if ($this->isEmpty($subCondition)) {
                        unset($condition[$i]);
                    } else {
                        $condition[$i] = $subCondition;
                    }
                }

                if (empty($condition)) {
                    return [];
                }
                break;
            case 'BETWEEN':
            case 'NOT BETWEEN':
                if (array_key_exists(1, $condition) && array_key_exists(2, $condition)) {
                    if ($this->isEmpty($condition[1]) || $this->isEmpty($condition[2])) {
                        return [];
                    }
                }
                break;
            default:
                if (array_key_exists(1, $condition) && $this->isEmpty($condition[1])) {
                    return [];
                }
        }

        array_unshift($condition, $operator);

        return $condition;
    }

    /**
     * Returns a value indicating whether the give value is "empty".
     *
     * The value is considered "empty", if one of the following conditions is satisfied:
     *
     * - it is `null`,
     * - an empty string (`''`),
     * - a string containing only whitespace characters,
     * - or an empty array.
     *
     * @param mixed $value
     * @return bool if the value is empty
     */
    protected function isEmpty($value)
    {
        return $value === '' || $value === [] || $value === null || is_string($value) && trim($value) === '';
    }

    /**
     * Sets the ORDER BY part of the query.
     * @param string|array|Expression $columns the columns (and the directions) to be ordered by.
     * Columns can be specified in either a string (e.g. `"id ASC, name DESC"`) or an array
     * (e.g. `['id' => SORT_ASC, 'name' => SORT_DESC]`).
     *
     * The method will automatically quote the column names unless a column contains some parenthesis
     * (which means the column contains a DB expression).
     *
     * Note that if your order-by is an expression containing commas, you should always use an array
     * to represent the order-by information. Otherwise, the method will not be able to correctly determine
     * the order-by columns.
     *
     * @return $this the query object itself
     * @see addOrderBy()
     */
    public function orderBy($columns)
    {
        $this->orderBy = $this->normalizeOrderBy($columns);
        return $this;
    }

    /**
     * Adds additional ORDER BY columns to the query.
     * @param string|array|Expression $columns the columns (and the directions) to be ordered by.
     * Columns can be specified in either a string (e.g. "id ASC, name DESC") or an array
     * (e.g. `['id' => SORT_ASC, 'name' => SORT_DESC]`).
     *
     * The method will automatically quote the column names unless a column contains some parenthesis
     * (which means the column contains a DB expression).
     *
     * Note that if your order-by is an expression containing commas, you should always use an array
     * to represent the order-by information. Otherwise, the method will not be able to correctly determine
     * the order-by columns.
     *
     * @return $this the query object itself
     * @see orderBy()
     */
    public function addOrderBy($columns)
    {
        $columns = $this->normalizeOrderBy($columns);
        if ($this->orderBy === null) {
            $this->orderBy = $columns;
        } else {
            $this->orderBy = array_merge($this->orderBy, $columns);
        }

        return $this;
    }

    /**
     * Normalizes format of ORDER BY data.
     *
     * @param array|string|Expression $columns the columns value to normalize. See [[orderBy]] and [[addOrderBy]].
     * @return array
     */
    protected function normalizeOrderBy($columns)
    {
        if ($columns instanceof Expression) {
            return [$columns];
        } elseif (is_array($columns)) {
            return $columns;
        }

        $columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
        $result = [];
        foreach ($columns as $column) {
            if (preg_match('/^(.*?)\s+(asc|desc)$/i', $column, $matches)) {
                $result[$matches[1]] = strcasecmp($matches[2], 'desc') ? SORT_ASC : SORT_DESC;
            } else {
                $result[$column] = SORT_ASC;
            }
        }

        return $result;
    }

    /**
     * Sets the LIMIT part of the query.
     * @param int|Expression|null $limit the limit. Use null or negative value to disable limit.
     * @return $this the query object itself
     */
    public function limit($limit)
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Sets the OFFSET part of the query.
     * @param int|Expression|null $offset the offset. Use null or negative value to disable offset.
     * @return $this the query object itself
     */
    public function offset($offset)
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Creates a new Query object and copies its property values from an existing one.
     * The properties being copies are the ones to be used by query builders.
     * @param Query $from the source query object
     * @return Query the new Query object
     */
    public static function create($from)
    {
        return new self([
            'where' => $from->where,
            'limit' => $from->limit,
            'offset' => $from->offset,
            'orderBy' => $from->orderBy,
            'indexBy' => $from->indexBy,
            'select' => $from->select,
            'selectOption' => $from->selectOption,
            'distinct' => $from->distinct,
            'from' => $from->from,
            'groupBy' => $from->groupBy,
            'join' => $from->join,
            'having' => $from->having,
            'union' => $from->union,
            'params' => $from->params,
        ]);
    }
}