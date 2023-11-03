<?php

    declare(strict_types = 1);

    namespace Coco\sqlCache;

    use PHPSQLParser\PHPSQLParser;

final class SqlParser
{
    private string       $sql      = '';
    private string       $sqlHash  = '';
    private array        $tables   = [];
    private static array $temp     = [];
    private static array $instance = [];

    /**
     * @param string $sql
     * @param string $prefix
     *
     * @return static
     */
    public static function getInstance(string $sql, string $prefix = 'default_db'): static
    {
        $sqlHash = $prefix . ':' . static::makeSqlHash($sql);

        (isset(static::$instance[$sqlHash])) or (static::$instance[$sqlHash] = new static($sql));

        return static::$instance[$sqlHash];
    }

    /**
     * @param $sql
     */
    private function __construct($sql)
    {
        $this->setSql($sql);
        $this->parseSqlTable();
    }

    /**
     * @return string
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * @return string
     */
    public function getSqlHash(): string
    {
        return $this->sqlHash;
    }

    /**
     * @return array
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * @param $table
     *
     * @return bool
     */
    public function hasTable($table): bool
    {
        return in_array($table, $this->getTables());
    }

    /**
     * @param string $sql
     *
     * @return self
     */
    private function setSql(string $sql): self
    {
        $this->sql     = $sql;
        $this->sqlHash = static::makeSqlHash($sql);

        return $this;
    }

    /**
     * @param string $sql
     *
     * @return string
     */
    private static function makeSqlHash(string $sql)
    {
        return md5($sql);
    }

    /**
     * @return array|int[]|mixed|string[]
     */
    private function parseSqlTable()
    {
        $tables = [];
        try {
            $parser      = new PHPSQLParser();
            $parsedArray = $parser->parse($this->getSql(), false);
            $hash        = $this->getSqlHash();

            static::$temp[$hash] = [];
            static::parseClause($parsedArray, $hash);
            $tables = static::$temp[$hash];

            unset(static::$temp[$hash]);
            $tables = array_flip(array_flip($tables));

            $this->tables = $tables;
        } catch (\Exception $exception) {
        }

        return $tables;
    }

    /**
     * @param $clause
     * @param $hash
     *
     * @return void
     */
    private static function parseClause($clause, $hash)
    {
        foreach ($clause as $k => $v) {
            isset($v[0]) && is_array($v[0]) && static::parseExpression($v, $hash);
        }
    }

    /**
     * @param $expression
     * @param $hash
     *
     * @return void
     */
    private static function parseExpression($expression, $hash)
    {
        foreach ($expression as $k => $v) {
            switch ($v['expr_type']) {
                case 'table':
                    $table = '';
                    if (isset($v['no_quotes']) && isset($v['no_quotes']['parts'])) {
                        if (isset($v['no_quotes']['parts'][1])) {
                            $table = $v['no_quotes']['parts'][1];
                        } elseif (isset($v['no_quotes']['parts'][0])) {
                            $table = $v['no_quotes']['parts'][0];
                        } else {
                            preg_match('/([^`]+)`$/m', $v['table'], $result);
                            (isset($result[1])) and ($table = $result[1]);
                        }
                    }
                    $table && (static::$temp[$hash][] = $table);

                    (isset($v['ref_clause']) && is_array($v['ref_clause'])) && static::parseExpression($v['ref_clause'], $hash);
                    break;
                case 'aggregate_function':
                case 'expression':
                case 'bracket_expression':
                case 'in-list':
                    (isset($v['sub_tree']) && is_array($v['sub_tree'])) && static::parseExpression($v['sub_tree'], $hash);
                    break;
                case 'subquery':
                    (isset($v['sub_tree']) && is_array($v['sub_tree'])) && static::parseClause($v['sub_tree'], $hash);
                    break;
                default:
                    #...
                    break;
            }
        }
    }
}
