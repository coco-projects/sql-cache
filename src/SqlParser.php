<?php

    declare(strict_types=1);

    namespace Coco\sqlCache;

    use Coco\logger\Logger;
    use PHPSQLParser\PHPSQLParser;

    /**
     * SQL 解析器
     *
     * 功能：
     * 1. 解析 SQL 原文
     * 2. 生成 SQL 哈希
     * 3. 提取 SQL 涉及的表
     * 4. 对解析结果做静态实例缓存
     * 5. 内置简单 LRU 淘汰，避免无限增长
     *
     * 说明：
     * - 主解析依赖 PHPSQLParser
     * - 解析失败时使用正则回退
     * - getInstance() 保持旧调用兼容
     */
    final class SqlParser
    {
        use Logger;


        /**
         * 最大缓存实例数
         */
        private const MAX_INSTANCES = 1000;

        /**
         * 原始 SQL
         */
        private string $sql = '';

        /**
         * SQL 哈希
         */
        private string $sqlHash = '';

        /**
         * SQL 涉及的数据表
         *
         * @var array<int,string>
         */
        private array $tables = [];

        /**
         * 递归解析临时缓存
         *
         * @var array<string,array<int,string>>
         */
        private static array $temp = [];

        /**
         * 解析器实例缓存
         *
         * @var array<string,self>
         */
        private static array $instance = [];

        /**
         * LRU 队列
         *
         * 越靠前越旧，越靠后越新
         *
         * @var array<int,string>
         */
        private static array $lruQueue = [];

        /**
         * 获取解析器实例
         *
         * @param string $sql SQL 原文
         * @param string $prefix 命名空间前缀，用于隔离实例缓存
         *
         * @return static
         */
        public static function getInstance(string $sql, string $prefix = 'default_db'): static
        {
            $cacheKey = $prefix . ':' . static::makeSqlHash($sql);

            if (!isset(static::$instance[$cacheKey])) {
                if (count(static::$instance) >= self::MAX_INSTANCES) {
                    static::evictLru();
                }

                static::$instance[$cacheKey] = new static($sql);
            }

            static::updateLru($cacheKey);

            return static::$instance[$cacheKey];
        }

        /**
         * 构造函数
         *
         * @param string $sql SQL 原文
         */
        private function __construct(string $sql)
        {
            $this->setSql($sql);
            $this->parseSqlTable();
        }

        /**
         * 获取 SQL 原文
         */
        public function getSql(): string
        {
            return $this->sql;
        }

        /**
         * 获取 SQL 哈希
         */
        public function getSqlHash(): string
        {
            return $this->sqlHash;
        }

        /**
         * 获取表列表
         *
         * @return array<int,string>
         */
        public function getTables(): array
        {
            return $this->tables;
        }

        /**
         * 判断是否包含某张表
         *
         * @param string $table 表名
         *
         * @return bool
         */
        public function hasTable(string $table): bool
        {
            return in_array(strtolower($table), array_map('strtolower', $this->tables), true);
        }

        /**
         * 清空实例缓存
         *
         * 可用于测试或长生命周期进程手动释放
         */
        public static function clearInstanceCache(): void
        {
            static::$instance = [];
            static::$lruQueue = [];
            static::$temp     = [];
        }

        /**
         * 设置 SQL 并生成哈希
         *
         * @param string $sql SQL 原文
         *
         * @return self
         */
        private function setSql(string $sql): self
        {
            $this->sql     = trim($sql);
            $this->sqlHash = static::makeSqlHash($this->sql);

            return $this;
        }

        /**
         * 生成 SQL 哈希
         *
         * 这里不做语义归一化，只做首尾 trim，
         * 保持与原始 SQL 更高的一致性和可控性。
         *
         * @param string $sql SQL 原文
         *
         * @return string
         */
        private static function makeSqlHash(string $sql): string
        {
            return hash('sha256', trim($sql));
        }

        /**
         * 解析 SQL 涉及的表
         *
         * 优先使用 PHPSQLParser
         * 失败则回退到正则提取
         *
         * @return array<int,string>
         */
        private function parseSqlTable(): array
        {
            $tables = [];

            try {
                $parser      = new PHPSQLParser();
                $parsedArray = $parser->parse($this->sql, false);
                $hash        = $this->sqlHash;

                static::$temp[$hash] = [];
                static::parseClause($parsedArray, $hash);

                $tables = static::$temp[$hash] ?? [];
                unset(static::$temp[$hash]);

                $this->tables = static::normalizeTables($tables);
            } catch (\Throwable $e) {
                $this->logError(
                    'SQL parsing failed, fallback to regex. SQL: ' .
                    substr($this->sql, 0, 200) .
                    ' Error: ' .
                    $e->getMessage()
                );

                $this->tables = static::extractTablesByRegex($this->sql);
            }

            return $this->tables;
        }

        /**
         * 使用正则回退提取表名
         *
         * 支持：
         * - FROM
         * - JOIN
         * - INSERT INTO
         * - UPDATE
         * - DELETE FROM
         *
         * @param string $sql SQL 原文
         *
         * @return array<int,string>
         */
        private static function extractTablesByRegex(string $sql): array
        {
            $tables = [];

            if (preg_match_all('/\bFROM\s+`?([a-zA-Z_][a-zA-Z0-9_\.]*)`?/i', $sql, $matches)) {
                $tables = array_merge($tables, $matches[1]);
            }

            if (preg_match_all('/\bJOIN\s+`?([a-zA-Z_][a-zA-Z0-9_\.]*)`?/i', $sql, $matches)) {
                $tables = array_merge($tables, $matches[1]);
            }

            if (preg_match('/\bINSERT\s+INTO\s+`?([a-zA-Z_][a-zA-Z0-9_\.]*)`?/i', $sql, $matches)) {
                $tables[] = $matches[1];
            }

            if (preg_match('/\bUPDATE\s+`?([a-zA-Z_][a-zA-Z0-9_\.]*)`?/i', $sql, $matches)) {
                $tables[] = $matches[1];
            }

            if (preg_match('/\bDELETE\s+FROM\s+`?([a-zA-Z_][a-zA-Z0-9_\.]*)`?/i', $sql, $matches)) {
                $tables[] = $matches[1];
            }

            return static::normalizeTables($tables);
        }

        /**
         * 规范化表名列表
         *
         * 处理：
         * - 去空
         * - 去反引号
         * - 去数据库名前缀
         * - 去重
         *
         * @param array $tables 原始表名数组
         *
         * @return array<int,string>
         */
        private static function normalizeTables(array $tables): array
        {
            $result = [];

            foreach ($tables as $table) {
                if (!is_string($table)) {
                    continue;
                }

                $table = trim($table, " \t\n\r\0\x0B`");
                if ($table === '') {
                    continue;
                }

                if (str_contains($table, '.')) {
                    $parts = explode('.', $table);
                    $table = (string) end($parts);
                }

                $table = trim($table, "` \t\n\r\0\x0B");
                if ($table === '') {
                    continue;
                }

                $result[$table] = $table;
            }

            return array_values($result);
        }

        /**
         * 更新 LRU
         *
         * @param string $key 缓存键
         */
        private static function updateLru(string $key): void
        {
            $index = array_search($key, static::$lruQueue, true);
            if ($index !== false) {
                unset(static::$lruQueue[$index]);
                static::$lruQueue = array_values(static::$lruQueue);
            }

            static::$lruQueue[] = $key;

            if (count(static::$lruQueue) > self::MAX_INSTANCES) {
                array_shift(static::$lruQueue);
            }
        }

        /**
         * 淘汰最久未使用实例
         */
        private static function evictLru(): void
        {
            $oldestKey = array_shift(static::$lruQueue);

            if ($oldestKey !== null) {
                unset(static::$instance[$oldestKey]);
            }
        }

        /**
         * 解析 SQL 语句块
         *
         * @param mixed  $clause PHPSQLParser 子结构
         * @param string $hash 当前 SQL 哈希
         */
        private static function parseClause(mixed $clause, string $hash): void
        {
            if (!is_array($clause)) {
                return;
            }

            foreach ($clause as $value) {
                if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                    static::parseExpression($value, $hash);
                }
            }
        }

        /**
         * 递归解析表达式
         *
         * @param mixed  $expression 表达式
         * @param string $hash 当前 SQL 哈希
         */
        private static function parseExpression(mixed $expression, string $hash): void
        {
            if (!is_array($expression)) {
                return;
            }

            foreach ($expression as $node) {
                if (!is_array($node) || !isset($node['expr_type'])) {
                    continue;
                }

                switch ($node['expr_type']) {
                    case 'table':
                        $table = static::extractTableNameFromNode($node);
                        if ($table !== '') {
                            static::$temp[$hash][] = $table;
                        }

                        if (isset($node['ref_clause']) && is_array($node['ref_clause'])) {
                            static::parseExpression($node['ref_clause'], $hash);
                        }
                        break;

                    case 'aggregate_function':
                    case 'expression':
                    case 'bracket_expression':
                    case 'in-list':
                        if (isset($node['sub_tree']) && is_array($node['sub_tree'])) {
                            static::parseExpression($node['sub_tree'], $hash);
                        }
                        break;

                    case 'subquery':
                        if (isset($node['sub_tree']) && is_array($node['sub_tree'])) {
                            static::parseClause($node['sub_tree'], $hash);
                        }
                        break;

                    default:
                        break;
                }
            }
        }

        /**
         * 从 PHPSQLParser 节点中提取表名
         *
         * @param array $node 节点
         *
         * @return string
         */
        private static function extractTableNameFromNode(array $node): string
        {
            if (isset($node['no_quotes']['parts']) && is_array($node['no_quotes']['parts'])) {
                $parts = $node['no_quotes']['parts'];

                if (isset($parts[1]) && is_string($parts[1])) {
                    return $parts[1];
                }

                if (isset($parts[0]) && is_string($parts[0])) {
                    return $parts[0];
                }
            }

            if (isset($node['table']) && is_string($node['table'])) {
                $table = trim($node['table'], '`');
                if (str_contains($table, '.')) {
                    $parts = explode('.', $table);
                    $table = (string) end($parts);
                }

                return trim($table);
            }

            return '';
        }

    }