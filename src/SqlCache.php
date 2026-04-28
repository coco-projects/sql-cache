<?php

    declare(strict_types = 1);

    namespace Coco\sqlCache;

    use Coco\logger\Logger;
    use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
    use Symfony\Contracts\Cache\ItemInterface;

    /**
     * SQL 缓存管理器
     *
     * 设计目标：
     * 1. 对外调用方式尽量兼容旧版
     * 2. Redis 不可用时自动降级，不影响主业务
     * 3. 支持基于 SQL 涉及表名的标签失效
     * 4. 支持分析数据采集
     * 5. 尽量避免大批量 KEYS，统一使用 SCAN
     *
     * 兼容保留的方法：
     * - autoCache()
     * - clearBySql()
     * - clearBySqls()
     * - getAnalysisData()
     * - clearAllCache()
     * - clearAllData()
     * - addIgnoreTable()
     * - removeIgnoreTable()
     * - setIsAnalysisEnabled()
     * - setExpiration()
     * - setEnable()
     * - isInFallbackMode()
     * - setMaxRetries()
     * - setRetryDelay()
     * - clearConnectionPool()
     */
    class SqlCache
    {
        use Logger;
        /**
         * 全量缓存标签名后缀
         */
        private const TAG_ALL = '_ALL_';

        /**
         * Redis 原始客户端
         */
        private ?\Redis $redisClient = null;

        /**
         * Symfony Redis 标签缓存适配器
         */
        private ?RedisTagAwareAdapter $cacheManager = null;

        /**
         * 默认缓存过期时间（秒）
         * 0 表示不过期，由底层缓存策略决定
         */
        private int $expiration = 0;

        /**
         * 是否启用 SQL 缓存
         */
        private bool $enable = true;

        /**
         * 是否启用分析功能
         */
        private bool $isAnalysisEnabled = false;

        /**
         * 忽略缓存的表
         * 结构：['table_name' => true]
         */
        private array $ignoreTables = [];

        /**
         * 当前 Redis 是否已连接成功
         */
        private bool $redisConnected = false;

        /**
         * 最大重试次数
         */
        private int $maxRetries = 3;

        /**
         * 重试基础延迟，单位毫秒
         */
        private int $retryDelay = 100;

        /**
         * 静态连接池
         *
         * 注意：
         * - 这里不 clone Redis 对象，直接复用
         * - 使用前会做 ping 检查
         *
         * @var array<string,\Redis>
         */
        private static array $connectionPool = [];

        /**
         * 构造函数
         *
         * @param string $redisHost     Redis 主机
         * @param int    $redisPort     Redis 端口
         * @param string $redisPassword Redis 密码
         * @param int    $redisDb       Redis 数据库编号
         * @param string $prefix        缓存前缀/命名空间
         */
        public function __construct(private string $redisHost = '127.0.0.1', private int $redisPort = 6379, private string $redisPassword = '', private int $redisDb = 9, private string $prefix = 'default_db')
        {
            $this->initRedisConnection();
        }

        /**
         * 自动缓存 SQL 查询结果
         *
         * 使用方式：
         * - 对 SELECT 类查询：自动按 SQL 哈希缓存
         * - 标签使用 SQL 所涉及的数据表
         * - 命中缓存时直接返回
         * - 未命中时执行回调并写入缓存
         * - Redis 异常时自动降级为直接执行回调
         *
         * @param string   $sql          SQL 语句
         * @param callable $dataCallback 数据回调
         *
         * @return mixed
         */
        public function autoCache(string $sql, callable $dataCallback): mixed
        {
            $sqlStructure = SqlParser::getInstance($sql, $this->prefix);
            $sqlTables    = $sqlStructure->getTables();

            if (!$this->enable || $this->shouldSkipCache($sqlTables))
            {
                return $dataCallback();
            }

            if (!$this->ensureRedisReady())
            {
                return $dataCallback();
            }

            $this->recordReadAnalysis($sqlStructure);

            try
            {
                return $this->cacheManager->get($sqlStructure->getSqlHash(), function(ItemInterface $item) use ($sqlStructure, $dataCallback) {
                    $sqlTables = $sqlStructure->getTables();

                    $this->recordDbReadAnalysis($sqlStructure);

                    if (!empty($sqlTables))
                    {
                        $item->tag($sqlTables);
                    }

                    $item->tag([$this->makeAllTag()]);

                    if ($this->expiration > 0)
                    {
                        $item->expiresAfter($this->expiration);
                    }

                    return $dataCallback();
                });
            }
            catch (\Throwable $e)
            {
                $this->logError('Redis cache operation failed: ' . $e->getMessage());

                return $dataCallback();
            }
        }

        /**
         * 根据单条 SQL 清理相关表缓存
         *
         * 常用于：
         * - INSERT
         * - UPDATE
         * - DELETE
         *
         * @param string $sql SQL 语句
         *
         * @return static
         */
        public function clearBySql(string $sql): static
        {
            if (!$this->ensureRedisReady())
            {
                return $this;
            }

            $sqlStructure = SqlParser::getInstance($sql, $this->prefix);
            $tables       = $sqlStructure->getTables();

            if (empty($tables))
            {
                return $this;
            }

            try
            {
                $this->cacheManager->invalidateTags(array_values(array_unique($tables)));
            }
            catch (\Throwable $e)
            {
                $this->logError('Redis invalidateTags failed: ' . $e->getMessage());

                return $this;
            }

            $this->recordInvalidateAnalysis($sqlStructure);

            return $this;
        }

        /**
         * 根据多条 SQL 批量清理缓存
         *
         * @param array $sqls SQL 语句数组
         *
         * @return static
         */
        public function clearBySqls(array $sqls): static
        {
            if (!$this->ensureRedisReady())
            {
                return $this;
            }

            $parsedSqls = [];
            $allTables  = [];

            foreach ($sqls as $sql)
            {
                if (!is_string($sql) || trim($sql) === '')
                {
                    continue;
                }

                $sqlStructure = SqlParser::getInstance($sql, $this->prefix);
                $parsedSqls[] = $sqlStructure;
                $allTables    = array_merge($allTables, $sqlStructure->getTables());
            }

            $allTables = array_values(array_unique($allTables));

            if (!empty($allTables))
            {
                try
                {
                    $this->cacheManager->invalidateTags($allTables);
                }
                catch (\Throwable $e)
                {
                    $this->logError('Redis bulk invalidateTags failed: ' . $e->getMessage());
                }
            }

            foreach ($parsedSqls as $sqlStructure)
            {
                $this->recordInvalidateAnalysis($sqlStructure);
            }

            return $this;
        }

        /**
         * 获取分析数据
         *
         * 返回格式：
         * [
         *     'sql' => [
         *         'sqlHash' => ['field' => 'value', ...]
         *     ],
         *     'table' => [
         *         'tableName' => ['field' => 'value', ...]
         *     ]
         * ]
         *
         * @return array{sql: array<string,array>, table: array<string,array>}
         */
        public function getAnalysisData(): array
        {
            if (!$this->ensureRedisReady())
            {
                return [
                    'sql'   => [],
                    'table' => [],
                ];
            }

            $data = [
                'sql'   => [],
                'table' => [],
            ];

            try
            {
                $tablePrefix = $this->makeAnalysisTableKey('');
                foreach ($this->scanKeys($tablePrefix . '*') as $key)
                {
                    $tableName = substr($key, strlen($tablePrefix));
                    if ($tableName === false || $tableName === '')
                    {
                        continue;
                    }

                    $row = $this->redisClient->hGetAll($key);
                    if (is_array($row) && !empty($row))
                    {
                        $data['table'][$tableName] = $row;
                    }
                }

                $sqlPrefix = $this->makeAnalysisSqlKey('');
                foreach ($this->scanKeys($sqlPrefix . '*') as $key)
                {
                    $sqlHash = substr($key, strlen($sqlPrefix));
                    if ($sqlHash === false || $sqlHash === '')
                    {
                        continue;
                    }

                    $row = $this->redisClient->hGetAll($key);
                    if (is_array($row) && !empty($row))
                    {
                        $data['sql'][$sqlHash] = $row;
                    }
                }
            }
            catch (\Throwable $e)
            {
                $this->logError('Redis getAnalysisData failed: ' . $e->getMessage());
            }

            return $data;
        }

        /**
         * 清理当前 prefix 下全部缓存标签对应的数据
         *
         * 实现方式：
         * - 通过统一的全量 tag 失效
         *
         * @return static
         */
        public function clearAllCache(): static
        {
            if (!$this->ensureRedisReady())
            {
                return $this;
            }

            try
            {
                $this->cacheManager->invalidateTags([$this->makeAllTag()]);
            }
            catch (\Throwable $e)
            {
                $this->logError('Redis clearAllCache failed: ' . $e->getMessage());
            }

            return $this;
        }

        /**
         * 清理当前 prefix 下所有数据
         *
         * 注意：
         * - 会删除当前 prefix 命名空间下所有 Redis 键
         * - 包括缓存键和分析键
         *
         * @return static
         */
        public function clearAllData(): static
        {
            if (!$this->ensureRedisReady())
            {
                return $this;
            }

            try
            {
                $keys = $this->scanKeys($this->prefix . ':*');

                if (!empty($keys))
                {
                    foreach (array_chunk($keys, 200) as $chunk)
                    {
                        $this->redisClient->del($chunk);
                    }
                }
            }
            catch (\Throwable $e)
            {
                $this->logError('Redis clearAllData failed: ' . $e->getMessage());
            }

            return $this;
        }

        /**
         * 添加忽略表
         *
         * 被忽略的表：
         * - 不参与缓存
         * - 不参与分析
         *
         * @param string $table 表名
         *
         * @return static
         */
        public function addIgnoreTable(string $table): static
        {
            $table = trim($table);
            if ($table !== '')
            {
                $this->ignoreTables[strtolower($table)] = true;
            }

            return $this;
        }

        /**
         * 移除忽略表
         *
         * @param string $table 表名
         *
         * @return static
         */
        public function removeIgnoreTable(string $table): static
        {
            unset($this->ignoreTables[strtolower(trim($table))]);

            return $this;
        }

        /**
         * 设置是否启用分析
         *
         * @param bool $isAnalysisEnabled 是否启用
         *
         * @return static
         */
        public function setIsAnalysisEnabled(bool $isAnalysisEnabled): static
        {
            $this->isAnalysisEnabled = $isAnalysisEnabled;

            return $this;
        }

        /**
         * 设置缓存过期时间（秒）
         *
         * @param int $expiration 过期时间
         *
         * @return static
         */
        public function setExpiration(int $expiration): static
        {
            $this->expiration = max(0, $expiration);

            return $this;
        }

        /**
         * 设置是否启用缓存
         *
         * @param bool $enable 是否启用
         *
         * @return static
         */
        public function setEnable(bool $enable): static
        {
            $this->enable = $enable;

            return $this;
        }

        /**
         * 当前是否处于降级模式
         *
         * true 表示 Redis 不可用，缓存已自动降级
         */
        public function isInFallbackMode(): bool
        {
            return !$this->redisConnected || $this->cacheManager === null;
        }

        /**
         * 设置最大重试次数
         *
         * 注意：
         * - 兼容旧接口保留
         * - 若希望立即生效，会尝试重新初始化连接
         *
         * @param int $maxRetries 最大重试次数
         *
         * @return static
         */
        public function setMaxRetries(int $maxRetries): static
        {
            $this->maxRetries = max(1, $maxRetries);

            if (!$this->redisConnected)
            {
                $this->initRedisConnection(true);
            }

            return $this;
        }

        /**
         * 设置重试延迟（毫秒）
         *
         * @param int $retryDelay 重试延迟
         *
         * @return static
         */
        public function setRetryDelay(int $retryDelay): static
        {
            $this->retryDelay = max(0, $retryDelay);

            if (!$this->redisConnected)
            {
                $this->initRedisConnection(true);
            }

            return $this;
        }

        /**
         * 清空静态连接池
         */
        public static function clearConnectionPool(): void
        {
            self::$connectionPool = [];
        }

        /**
         * 初始化 Redis 连接
         *
         * @param bool $forceReconnect 是否强制重连
         */
        private function initRedisConnection(bool $forceReconnect = false): void
        {
            $this->redisConnected = false;
            $this->cacheManager   = null;

            $connectionKey = $this->getConnectionKey();

            if (!$forceReconnect && isset(self::$connectionPool[$connectionKey]))
            {
                $pooledClient = self::$connectionPool[$connectionKey];

                if ($this->isRedisAlive($pooledClient))
                {
                    $this->redisClient    = $pooledClient;
                    $this->redisConnected = true;
                    $this->cacheManager   = new RedisTagAwareAdapter($this->redisClient, $this->prefix);

                    return;
                }

                unset(self::$connectionPool[$connectionKey]);
            }

            $attempt = 0;

            while ($attempt < $this->maxRetries)
            {
                $attempt++;

                try
                {
                    $client = new \Redis();

                    $connected = $client->connect($this->redisHost, $this->redisPort);
                    if ($connected !== true)
                    {
                        throw new \RedisException('Failed to connect to Redis');
                    }

                    if ($this->redisPassword !== '')
                    {
                        if ($client->auth($this->redisPassword) !== true)
                        {
                            throw new \RedisException('Redis auth failed');
                        }
                    }

                    if ($client->select($this->redisDb) !== true)
                    {
                        throw new \RedisException('Redis select db failed');
                    }

                    $this->redisClient    = $client;
                    $this->redisConnected = true;
                    $this->cacheManager   = new RedisTagAwareAdapter($this->redisClient, $this->prefix);

                    self::$connectionPool[$connectionKey] = $client;

                    return;
                }
                catch (\Throwable $e)
                {
                    $this->redisConnected = false;
                    $this->cacheManager   = null;

                    if ($attempt >= $this->maxRetries)
                    {
                        $this->logError(sprintf('Redis connection failed after %d attempts: %s. Entering fallback mode.', $this->maxRetries, $e->getMessage()));
                        break;
                    }

                    $sleepMs = $this->retryDelay * (2 ** ($attempt - 1));
                    usleep($sleepMs * 1000);
                }
            }
        }

        /**
         * 确保 Redis 已就绪
         *
         * 若当前不可用，会尝试重新连接一次。
         */
        private function ensureRedisReady(): bool
        {
            if ($this->redisConnected && $this->redisClient !== null && $this->cacheManager !== null)
            {
                if ($this->isRedisAlive($this->redisClient))
                {
                    return true;
                }

                $this->redisConnected = false;
                $this->cacheManager   = null;
            }

            $this->initRedisConnection(true);

            return $this->redisConnected && $this->cacheManager !== null && $this->redisClient !== null;
        }

        /**
         * 判断 Redis 连接是否存活
         *
         * @param \Redis $client Redis 客户端
         *
         * @return bool
         */
        private function isRedisAlive(\Redis $client): bool
        {
            try
            {
                $pong = $client->ping();

                return $pong !== false;
            }
            catch (\Throwable)
            {
                return false;
            }
        }

        /**
         * 使用 SCAN 安全扫描键
         *
         * @param string $pattern 键模式
         *
         * @return array<int,string>
         */
        private function scanKeys(string $pattern): array
        {
            if ($this->redisClient === null)
            {
                return [];
            }

            $keys     = [];
            $iterator = null;

            do
            {
                $batch = $this->redisClient->scan($iterator, $pattern, 100);

                if (is_array($batch) && !empty($batch))
                {
                    $keys = array_merge($keys, $batch);
                }
            } while ($iterator !== 0);

            return array_values(array_unique($keys));
        }

        /**
         * 判断是否应跳过缓存
         *
         * 只要 SQL 涉及任一忽略表，就不缓存
         *
         * @param array $tables 表名数组
         *
         * @return bool
         */
        private function shouldSkipCache(array $tables): bool
        {
            foreach ($tables as $table)
            {
                if (isset($this->ignoreTables[strtolower($table)]))
                {
                    return true;
                }
            }

            return false;
        }

        /**
         * 记录读取分析
         *
         * total_read:
         * - 每次调用 autoCache 都会累计
         *
         * @param SqlParser $sqlStructure SQL 解析对象
         */
        private function recordReadAnalysis(SqlParser $sqlStructure): void
        {
            if (!$this->isAnalysisEnabled || !$this->redisConnected || $this->redisClient === null)
            {
                return;
            }

            try
            {
                $analysisSqlKey = $this->makeAnalysisSqlKey($sqlStructure->getSqlHash());

                $this->redisClient->hMSet($analysisSqlKey, [
                    'sql'    => $sqlStructure->getSql(),
                    'tables' => implode(',', $sqlStructure->getTables()),
                ]);
                $this->redisClient->hIncrBy($analysisSqlKey, 'total_read', 1);

                foreach ($sqlStructure->getTables() as $table)
                {
                    if (isset($this->ignoreTables[strtolower($table)]))
                    {
                        continue;
                    }

                    $analysisTableKey = $this->makeAnalysisTableKey($table);
                    $this->redisClient->hIncrBy($analysisTableKey, 'total_read', 1);
                }
            }
            catch (\Throwable $e)
            {
                $this->logError('Redis analysis error: ' . $e->getMessage());
            }
        }

        /**
         * 记录数据库真实读取分析
         *
         * db_read:
         * - 仅缓存未命中时累计
         *
         * @param SqlParser $sqlStructure SQL 解析对象
         */
        private function recordDbReadAnalysis(SqlParser $sqlStructure): void
        {
            if (!$this->isAnalysisEnabled || !$this->redisConnected || $this->redisClient === null)
            {
                return;
            }

            try
            {
                $analysisSqlKey = $this->makeAnalysisSqlKey($sqlStructure->getSqlHash());
                $this->redisClient->hIncrBy($analysisSqlKey, 'db_read', 1);

                foreach ($sqlStructure->getTables() as $table)
                {
                    if (isset($this->ignoreTables[strtolower($table)]))
                    {
                        continue;
                    }

                    $analysisTableKey = $this->makeAnalysisTableKey($table);
                    $this->redisClient->hIncrBy($analysisTableKey, 'db_read', 1);
                }
            }
            catch (\Throwable $e)
            {
                $this->logError('Redis analysis error in callback: ' . $e->getMessage());
            }
        }

        /**
         * 记录失效分析
         *
         * 支持统计：
         * - invalidate_insert
         * - invalidate_update
         * - invalidate_delete
         *
         * @param SqlParser $sqlStructure SQL 解析对象
         */
        private function recordInvalidateAnalysis(SqlParser $sqlStructure): void
        {
            if (!$this->isAnalysisEnabled || !$this->redisConnected || $this->redisClient === null)
            {
                return;
            }

            $sqlText = ltrim($sqlStructure->getSql());
            $action  = null;

            if (preg_match('/^update\b/i', $sqlText))
            {
                $action = 'invalidate_update';
            }
            elseif (preg_match('/^insert\b/i', $sqlText))
            {
                $action = 'invalidate_insert';
            }
            elseif (preg_match('/^delete\b/i', $sqlText))
            {
                $action = 'invalidate_delete';
            }

            if ($action === null)
            {
                return;
            }

            foreach ($sqlStructure->getTables() as $table)
            {
                if (isset($this->ignoreTables[strtolower($table)]))
                {
                    continue;
                }

                try
                {
                    $analysisTableKey = $this->makeAnalysisTableKey($table);
                    $this->redisClient->hIncrBy($analysisTableKey, $action, 1);
                }
                catch (\Throwable $e)
                {
                    $this->logError('Redis analysis increment failed: ' . $e->getMessage());
                }
            }
        }

        /**
         * 构造 SQL 分析键
         *
         * @param string $sqlHash SQL 哈希
         *
         * @return string
         */
        private function makeAnalysisSqlKey(string $sqlHash): string
        {
            return $this->prefix . ':analysis:sql:' . $sqlHash;
        }

        /**
         * 构造表分析键
         *
         * @param string $tableName 表名
         *
         * @return string
         */
        private function makeAnalysisTableKey(string $tableName): string
        {
            return $this->prefix . ':analysis:table:' . $tableName;
        }

        /**
         * 构造全量标签名
         */
        private function makeAllTag(): string
        {
            return $this->prefix . self::TAG_ALL;
        }

        /**
         * 生成连接池键
         *
         * @return string
         */
        private function getConnectionKey(): string
        {
            return sprintf('%s:%d:%s:%d', $this->redisHost, $this->redisPort, $this->redisPassword, $this->redisDb);
        }
    }