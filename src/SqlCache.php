<?php

    declare(strict_types = 1);

    namespace Coco\sqlCache;

    use Psr\Cache\InvalidArgumentException;
    use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
    use Symfony\Component\Cache\CacheItem;

class SqlCache
{
    private ?RedisTagAwareAdapter $cacheManager      = null;
    private ?\Redis               $redisClient       = null;
    private int                   $expiration        = 0;
    private bool                  $enable            = true;
    private bool                  $isAnalysisEnabled = false;
    private array                 $ignoreTables      = [];
    private const TAG_ALL = '_ALL_';

    /**
     * @param string $redisHost
     * @param int    $redisPort
     * @param string $redisPassword
     * @param int    $redisDb
     * @param string $prefix
     *
     * @throws \RedisException
     */
    public function __construct(private string $redisHost = '127.0.0.1', private int $redisPort = 6379, private string $redisPassword = '', private int $redisDb = 9, private string $prefix = 'default_db')
    {
        $this->redisClient = new \Redis();
        $this->redisClient->connect($this->redisHost, $this->redisPort);
        $this->redisPassword and $this->redisClient->auth($this->redisPassword);
        $this->redisClient->select($this->redisDb);

        $this->cacheManager = new RedisTagAwareAdapter($this->redisClient, $this->prefix);
    }

    /**
     * @param string   $sql
     * @param callable $dataCallback
     *
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException|\RedisException
     */
    public function autoCache(string $sql, callable $dataCallback): mixed
    {
        $sqlStructure = SqlParser::getInstance($sql);
        $sqlTables    = $sqlStructure->getTables();

        $noNotCache = false;
        foreach ($sqlTables as $k => $table) {
            if (isset($this->ignoreTables[$table])) {
                $noNotCache = true;
                break;
            }
        }

        if ((!$this->enable) or $noNotCache) {
            return call_user_func_array($dataCallback, []);
        }

        if ($this->isAnalysisEnabled) {
            $analysisSqlKey = $this->makeAnalysisSqlKey($sqlStructure->getSqlHash());
            $this->redisClient->hMSet($analysisSqlKey, [
                "sql"    => $sqlStructure->getSql(),
                "tables" => implode(',', $sqlStructure->getTables()),
            ]);
            $this->redisClient->hIncrBy($analysisSqlKey, 'total_read', 1);

            foreach ($sqlTables as $k => $table) {
                if (!isset($this->ignoreTables[$table])) {
                    $analysisTableKey = $this->makeAnalysisTableKey($table);
                    $this->redisClient->hIncrBy($analysisTableKey, 'total_read', 1);
                }
            }
        }

        return $this->cacheManager->get($sqlStructure->getSqlHash(), function (CacheItem $item) use ($sqlStructure, $dataCallback) {

            $sqlTables = $sqlStructure->getTables();

            if ($this->isAnalysisEnabled) {
                $analysisSqlKey = $this->makeAnalysisSqlKey($sqlStructure->getSqlHash());
                $this->redisClient->hIncrBy($analysisSqlKey, 'db_read', 1);

                foreach ($sqlTables as $k => $table) {
                    if (!isset($this->ignoreTables[$table])) {
                        $analysisTableKey = $this->makeAnalysisTableKey($table);
                        $this->redisClient->hIncrBy($analysisTableKey, 'db_read', 1);
                    }
                }
            }

            $item->tag($sqlTables);
            $item->tag($this->prefix . self::TAG_ALL);

            if ($this->expiration > 0) {
                $item->expiresAfter($this->expiration);
            }
            return call_user_func_array($dataCallback, []);
        });
    }

    /**
     * @param string $sql
     *
     * @return SqlCache
     * @throws InvalidArgumentException|\RedisException
     */
    public function clearBySql(string $sql): static
    {
        $sqlStructure = SqlParser::getInstance($sql);
        $sqlTables    = $sqlStructure->getTables();

        $this->cacheManager->invalidateTags($sqlTables);

        if ($this->isAnalysisEnabled) {
            foreach ($sqlTables as $k => $table) {
                if (!isset($this->ignoreTables[$table])) {
                    $analysisTableKey = $this->makeAnalysisTableKey($table);

                    $sql = $sqlStructure->getSql();

                    if (preg_match('#^update#i', $sql)) {
                        $this->redisClient->hIncrBy($analysisTableKey, 'invalidate_update', 1);
                    }

                    if (preg_match('#^insert#i', $sql)) {
                        $this->redisClient->hIncrBy($analysisTableKey, 'invalidate_insert', 1);
                    }

                    if (preg_match('#^delete#i', $sql)) {
                        $this->redisClient->hIncrBy($analysisTableKey, 'invalidate_delete', 1);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * @return array|array[]
     * @throws \RedisException
     */
    public function getAnalysisData()
    {
        $data = [
            "sql"   => [],
            "table" => [],
        ];

        $tableKeys = $this->redisClient->keys($this->makeAnalysisTableKey('*'));
        if (!empty($tableKeys)) {
            foreach ($tableKeys as $key) {
                $tableName                 = explode(':', $key)[3];
                $data['table'][$tableName] = $this->redisClient->hGetAll($key);
            }
        }

        $sqlKeys = $this->redisClient->keys($this->makeAnalysisSqlKey('*'));
        if (!empty($sqlKeys)) {
            foreach ($sqlKeys as $key) {
                $data['sql'][] = $this->redisClient->hGetAll($key);
            }
        }

        return $data;
    }

    /**
     * @return SqlCache
     * @throws InvalidArgumentException
     */
    public function clearAllCache(): static
    {
        $this->cacheManager->invalidateTags([$this->prefix . self::TAG_ALL]);

        return $this;
    }

    /**
     * @return $this
     * @throws \RedisException
     */
    public function clearAllData(): static
    {
        $keys = $this->redisClient->keys($this->prefix . ':*');

        if (!empty($keys)) {
            $this->redisClient->del($keys);
        }

        return $this;
    }

    /**
     * @param string $table
     *
     * @return $this
     */
    public function addIgnoreTable(string $table): static
    {
        $this->ignoreTables[$table] = 1;

        return $this;
    }

    /**
     * @param string $table
     *
     * @return $this
     */
    public function removeIgnoreTable(string $table): static
    {
        unset($this->ignoreTables[$table]);

        return $this;
    }

    /**
     * @param bool $isAnalysisEnabled
     *
     * @return SqlCache
     */
    public function setIsAnalysisEnabled(bool $isAnalysisEnabled): static
    {
        $this->isAnalysisEnabled = $isAnalysisEnabled;

        return $this;
    }

    /**
     * @param int $expiration
     *
     * @return SqlCache
     */
    public function setExpiration(int $expiration): static
    {
        $this->expiration = $expiration;

        return $this;
    }

    /**
     * @param bool $enable
     *
     * @return SqlCache
     */
    public function setEnable(bool $enable): static
    {
        $this->enable = $enable;

        return $this;
    }

    /**
     * @param string $sqlHash
     *
     * @return string
     */
    private function makeAnalysisSqlKey(string $sqlHash): string
    {
        return $this->prefix . ':analysis:sql:' . $sqlHash;
    }

    /**
     * @param string $tableName
     *
     * @return string
     */
    private function makeAnalysisTableKey(string $tableName): string
    {
        return $this->prefix . ':analysis:table:' . $tableName;
    }
}
