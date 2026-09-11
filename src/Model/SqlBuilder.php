<?php

declare(strict_types=1);

namespace CodeIT\Model;

use Laminas\Db\Sql\Expression;

/**
 * Assembles the SQL strings AppTable builds by hand.
 *
 * Pure string work with no state of its own: every clause is passed in, so a
 * model that sets $groupBy directly still gets its value used, and the pieces a
 * subclass may override (quoting a value, preparing a condition) arrive as
 * callables so the override is honoured.
 */
final class SqlBuilder
{
    /** `from` with the find join attached, the shape five call sites used to repeat. */
    public static function from(string $table, string $join = ''): string
    {
        return ' from `' . $table . '` ' . $join . ' ';
    }

    /** Group by and having, each already complete or empty. */
    public static function clauses(?string $groupBy, ?string $having): string
    {
        return (string) $groupBy . ' ' . (string) $having;
    }

    public static function countQuery(string $table, string $join, string $where, string $clauses): string
    {
        return 'select count(*) cnt' . self::from($table, $join) . $where . $clauses;
    }

    /**
     * @param string|bool|false $orderBy
     */
    public static function selectQuery(
        string $columns,
        string $table,
        string $join,
        string $where,
        string $clauses,
        $orderBy = false,
        int $limit = 0,
        int $offset = 0,
        string $extraJoin = ''
    ): string {
        return 'select ' . $columns . self::from($table, $join) . $where . $extraJoin . $clauses
            . ($orderBy ? ' order by ' . $orderBy : '')
            . ($limit ? ' limit ' . $offset . ', ' . $limit : '');
    }

    /**
     * Renders the select list. An Expression is used verbatim, a two element
     * array is `table`.column for joined tables, and a string key is an alias.
     *
     * @param array $columns
     */
    public static function columns(string $table, array $columns): string
    {
        $rendered = [];
        foreach ($columns as $alias => $column) {
            if ($column instanceof Expression) {
                $selectColumn = $column->getExpression();
            } elseif (is_array($column)) {
                $selectColumn = '`' . $column[0] . '`.' . $column[1];
            } elseif ($column == '*') {
                $selectColumn = '`' . $table . '`.*';
            } else {
                $selectColumn = '`' . $table . '`.`' . $column . '`';
            }
            if (is_string($alias) && $alias !== '') {
                $selectColumn .= ' AS ' . $alias;
            }
            $rendered[] = $selectColumn;
        }
        return implode(', ', $rendered);
    }

    /**
     * Turns one [column, operator, value] triple into SQL.
     *
     * @param mixed $param
     * @param callable $quote quotes a scalar value
     * @param callable $quoteIdentifier quotes a bare column name
     */
    public static function condition($param, callable $quote, callable $quoteIdentifier): string
    {
        if ($param instanceof Expression) {
            return $param->getExpression();
        }
        if (is_string($param)) {
            return $param;
        }

        $column = $param[0] instanceof Expression
            ? $param[0]->getExpression()
            : self::quoteColumn($param[0], $quoteIdentifier);
        $operator = $param[1];
        $set = $column . ' ' . $operator . ' ';

        switch (strtolower((string) $operator)) {
            case 'in':
                if (is_array($param[2])) {
                    return $set . '(' . implode(', ', array_map($quote, $param[2])) . ')';
                }
                return $set . '(\'' . $quote($param[2]) . '\')';
            case 'like':
                $set .= $quote($param[2]);
                // The fourth element is the ESCAPE character.
                return empty($param[3]) ? $set : $set . ' ESCAPE "' . $param[3] . '"';
            default:
                return $set . (isset($param[2]) ? $quote($param[2]) : 'NULL');
        }
    }

    /** `table.column` keeps its dot, anything else is quoted whole. */
    private static function quoteColumn(string $column, callable $quoteIdentifier): string
    {
        $dot = strpos($column, '.');
        if ($dot === false) {
            return $quoteIdentifier($column);
        }
        return substr_replace($column, '`', $dot + 1, 0) . '`';
    }
}
