<?php

namespace OneLap\LaravelResilienceMiddleware\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Exception;
use PDOException;
use Predis\Connection\ConnectionException;
use Illuminate\Database\QueryException;

/**
 * 系统监控服务
 * 监控CPU、内存、Redis、MySQL等系统资源使用情况
 */
class SystemMonitorService
{
    protected $redis;
    protected $config;
    protected $db;
    protected $redisConnectionTimeout = 2;  // Redis连接超时时间（秒）
    protected $mysqlConnectionTimeout = 3;  // MySQL连接超时时间（秒）

    public function __construct()
    {
        $this->config = config('resilience.system_monitor', []);
        $this->redisConnectionTimeout = $this->config['redis_connection_timeout'] ?? 2;
        $this->mysqlConnectionTimeout = $this->config['mysql_connection_timeout'] ?? 3;

        // 初始化Redis连接
        if (isset($this->config['redis']['connection'])) {
            $this->redis = Redis::connection($this->config['redis']['connection']);
        } else {
            $this->redis = Redis::connection('default');
        }

        // 初始化数据库连接
        try {
            $connection = $this->config['database']['connection'] ?? config('database.default', 'mysql');
            $this->db = DB::connection($connection);
            // 测试连接是否有效
            try {
                DB::raw('SELECT 1');
            } catch (\Exception $e) {
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('数据库连接初始化失败', [
                'error' => $e->getMessage(),
                'connection' => $connection
            ]);
            // 使用默认连接作为备选
            try {
                $this->db = DB::connection('mysql');
                DB::raw('SELECT 1');
            } catch (\Exception $e) {
                Log::error('默认数据库连接也失败', ['error' => $e->getMessage()]);
                throw new \RuntimeException('无法建立数据库连接: ' . $e->getMessage());
            }
        }
    }

    /**
     * 获取系统健康状态
     *
     * @return array
     */
    public function getSystemHealth(): array
    {
        return [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemoryUsage(),
            'redis' => $this->getRedisHealth(),
            'mysql' => $this->getMysqlHealth(),
            // 'load_average' => $this->getLoadAverage(),
            'timestamp' => time()
        ];
    }

    /**
     * 获取系统压力级别
     *
     * @return string low|medium|high|critical
     */
    public function getSystemPressureLevel(): string
    {
        $health = $this->getSystemHealth();
        $weights = $this->config['pressure_weights'] ?? [
            'cpu' => 0.4,
            'memory' => 0.3,
            'redis' => 0.2,
            'mysql' => 0.1
        ];

        $totalScore = 0;
        $totalWeight = 0;

        foreach ($weights as $metric => $weight) {
            if (isset($health[$metric]) && is_numeric($health[$metric])) {
                $totalScore += $health[$metric] * $weight;
                $totalWeight += $weight;
            }
        }

        $averageScore = $totalWeight > 0 ? $totalScore / $totalWeight : 0;

        $thresholds = $this->config['cpu']['thresholds'] ?? [
            'medium' => 70,
            'high' => 85,
            'critical' => 95
        ];

        if ($averageScore >= $thresholds['critical']) {
            return 'critical';
        } elseif ($averageScore >= $thresholds['high']) {
            return 'high';
        } elseif ($averageScore >= $thresholds['medium']) {
            return 'medium';
        }

        return 'low';
    }

    private function getCpuUsage(): ?float
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows系统
            $output = shell_exec('wmic cpu get loadpercentage /value 2>nul');
            if ($output && preg_match('/LoadPercentage=(\d+)/', $output, $matches)) {
                return (float) $matches[1];
            }
            $processes = (int) shell_exec('tasklist | find /c "." 2>nul') ?: 50;
            return min(100, $processes / 10);
        } else {
            // Linux/Unix系统
            try {
                // 方法1: 使用sys_getloadavg()函数
                if (function_exists('sys_getloadavg')) {
                    $load = sys_getloadavg();
                    if ($load !== false && isset($load[0])) {
                        // 获取CPU核心数
                        $cpuCount = (int) shell_exec('nproc 2>/dev/null') ?: 1;
                        return min(100, ($load[0] / $cpuCount) * 100);
                    }
                }

                // 方法2: 读取/proc/loadavg文件
                if (file_exists('/proc/loadavg')) {
                    $loadavg = file_get_contents('/proc/loadavg');
                    if ($loadavg) {
                        $load = explode(' ', trim($loadavg));
                        $cpuCount = (int) shell_exec('nproc 2>/dev/null') ?: 1;
                        return min(100, ((float)$load[0] / $cpuCount) * 100);
                    }
                }

                // 方法3: 使用top命令
                $output = shell_exec('top -bn1 | grep "Cpu(s)" | sed "s/.*, *\([0-9.]*\)%* id.*/\\1/" | awk \'{print 100 - $1}\' 2>/dev/null');
                if ($output && is_numeric(trim($output))) {
                    return (float) trim($output);
                }

                return null;
            } catch (Exception $e) {
                return null;
            }
        }
    }

    private function getMemoryUsage(): ?float
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows系统 - 使用修复版PowerShell方法
            $psCommand = 'powershell "Get-WmiObject -Class Win32_OperatingSystem | Select-Object TotalVisibleMemorySize,FreePhysicalMemory | ConvertTo-Json"';
            $output = shell_exec($psCommand . ' 2>nul');

            if ($output) {
                $memoryData = json_decode(trim($output), true);
                if ($memoryData && isset($memoryData['TotalVisibleMemorySize']) && isset($memoryData['FreePhysicalMemory'])) {
                    $totalMemory = (int) $memoryData['TotalVisibleMemorySize'];
                    $freeMemory = (int) $memoryData['FreePhysicalMemory'];
                    return (($totalMemory - $freeMemory) / $totalMemory) * 100;
                }
            }
        } else {
            // Linux/Unix系统
            try {
                // 方法1: 读取 /proc/meminfo 文件
                if (file_exists('/proc/meminfo')) {
                    $meminfo = file_get_contents('/proc/meminfo');
                    if ($meminfo) {
                        // 解析内存信息
                        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatch);
                        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $availableMatch);

                        if (!empty($availableMatch)) {
                            // 优先使用 MemAvailable（更准确）
                            $totalMemory = (int) $totalMatch[1];
                            $availableMemory = (int) $availableMatch[1];
                            return (($totalMemory - $availableMemory) / $totalMemory) * 100;
                        } else {
                            // 备用方案：使用 MemFree + Buffers + Cached
                            preg_match('/MemFree:\s+(\d+)/', $meminfo, $freeMatch);
                            preg_match('/Buffers:\s+(\d+)/', $meminfo, $buffersMatch);
                            preg_match('/Cached:\s+(\d+)/', $meminfo, $cachedMatch);

                            if (!empty($totalMatch) && !empty($freeMatch)) {
                                $totalMemory = (int) $totalMatch[1];
                                $freeMemory = (int) $freeMatch[1];
                                $buffers = isset($buffersMatch[1]) ? (int) $buffersMatch[1] : 0;
                                $cached = isset($cachedMatch[1]) ? (int) $cachedMatch[1] : 0;

                                $usedMemory = $totalMemory - $freeMemory - $buffers - $cached;
                                return ($usedMemory / $totalMemory) * 100;
                            }
                        }
                    }
                }

                // 方法2: 使用free命令
                $output = shell_exec('free -m | grep "^Mem:" | awk \'{print ($3/$2) * 100.0}\' 2>/dev/null');
                if ($output && is_numeric(trim($output))) {
                    return (float) trim($output);
                }

                return null;
            } catch (Exception $e) {
                return null;
            }
        }
        return null;
    }
    /**
     * 获取Redis健康状态
     * 
     * @return float|null Redis内存使用率 (0-100)，null表示无法获取状态
     */
    protected function getRedisHealth(): ?float
    {
        try {
            // 使用指定的Redis连接
            $pong = $this->redis->ping();

            // 正确处理 Predis 的响应
            $pingSuccess = false;
            if (is_object($pong) && method_exists($pong, 'getPayload')) {
                // Predis\Response\Status 对象
                $pingSuccess = $pong->getPayload() === 'PONG';
            } elseif (is_string($pong)) {
                // 字符串响应
                $pingSuccess = $pong === 'PONG';
            } elseif (is_bool($pong)) {
                // 布尔响应
                $pingSuccess = $pong === true;
            } else {
                // 尝试转换为字符串比较
                $pingSuccess = (string)$pong === 'PONG';
            }

            if (!$pingSuccess) {
                Log::warning('Redis ping failed: unexpected response', [
                    'response_type' => gettype($pong),
                    'response_class' => is_object($pong) ? get_class($pong) : null,
                    'response_value' => is_object($pong) ? (string)$pong : $pong
                ]);
                return null;
            }

            // 获取Redis服务器信息
            $info = $this->redis->info();

            // 从嵌套结构中提取内存信息
            $usedMemory = null;
            $maxMemory = null;

            // 尝试从 Memory 部分获取
            if (isset($info['Memory'])) {
                $usedMemory = $info['Memory']['used_memory'] ?? null;
                $maxMemory = $info['Memory']['maxmemory'] ?? null;
            }

            // 兼容性：如果 Memory 部分不存在，尝试直接从根部获取
            if ($usedMemory === null && isset($info['used_memory'])) {
                $usedMemory = $info['used_memory'];
            }
            if ($maxMemory === null && isset($info['maxmemory'])) {
                $maxMemory = $info['maxmemory'];
            }

            // 调试信息
            Log::debug('Redis 连接成功', [
                'ping_success' => $pingSuccess,
                'info_structure' => isset($info['Memory']) ? 'nested' : 'flat',
                'used_memory' => $usedMemory ?? 'not_set',
                'maxmemory' => $maxMemory ?? 'not_set'
            ]);

            // 计算内存使用率
            if ($usedMemory !== null && $maxMemory !== null && $maxMemory > 0) {
                $memoryUsage = ($usedMemory / $maxMemory) * 100;

                Log::debug('Redis内存使用情况', [
                    'used_memory' => $usedMemory,
                    'max_memory' => $maxMemory,
                    'usage_percentage' => $memoryUsage
                ]);

                return round($memoryUsage, 2);
            }

            // 如果没有设置maxmemory，只返回已用内存大小（MB）
            if ($usedMemory !== null) {
                $usedMemoryMB = round($usedMemory / 1024 / 1024, 2);

                Log::debug('Redis内存使用情况（未设置最大内存）', [
                    'used_memory_bytes' => $usedMemory,
                    'used_memory_mb' => $usedMemoryMB
                ]);

                // 返回已用内存的MB数作为参考值
                return $usedMemoryMB;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Redis健康检查失败', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 获取MySQL健康状态
     * 
     * @return array|null 返回磁盘使用情况，null表示无法获取状态
     */
    protected function getMysqlHealth(): ?float
    {
        try {
            if (!$this->db) {
                throw new \RuntimeException('数据库连接未初始化');
            }

            $result = [];
            $res = null;

            // 获取磁盘使用情况
            try {
                // 获取数据目录路径
                $variables = collect($this->db->select("SHOW GLOBAL VARIABLES WHERE Variable_name = 'datadir'"))->pluck('Value', 'Variable_name');
                $datadir = $variables->get('datadir', '/var/lib/mysql/');

                // 获取数据库文件总大小
                $databases = $this->db->select("SHOW DATABASES");
                $totalSize = 0;

                foreach ($databases as $database) {
                    if (!in_array($database->Database, ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                        $tables = $this->db->select("SELECT 
                            SUM(data_length + index_length) as size 
                            FROM information_schema.tables 
                            WHERE table_schema = ?", [$database->Database]);

                        if (isset($tables[0]->size)) {
                            $totalSize += (int)$tables[0]->size;
                        }
                    }
                }

                // 获取磁盘总空间和可用空间
                if (function_exists('disk_total_space') && function_exists('disk_free_space')) {
                    $diskTotalSpace = disk_total_space($datadir);
                    if ($diskTotalSpace > 0) {
                        $diskFreeSpace = disk_free_space($datadir);
                        $diskUsedSpace = $diskTotalSpace - $diskFreeSpace;
                        $diskUsagePercentage = ($diskUsedSpace / $diskTotalSpace) * 100;

                        $result['disk_usage'] = [
                            'total_gb' => round($diskTotalSpace / 1024 / 1024 / 1024, 2),
                            'used_gb' => round($diskUsedSpace / 1024 / 1024 / 1024, 2),
                            'free_gb' => round($diskFreeSpace / 1024 / 1024 / 1024, 2),
                            'usage_percentage' => round($diskUsagePercentage, 2)
                        ];

                        $res= $result['disk_usage']['usage_percentage'];
                    }
                }

                // 添加数据库大小信息
                $result['database_size'] = [
                    'total_bytes' => $totalSize,
                    'total_mb' => round($totalSize / 1024 / 1024, 2),
                    'total_gb' => round($totalSize / 1024 / 1024 / 1024, 2)
                ];

                // 计算数据库文件占磁盘的百分比
                if (isset($result['disk_usage'], $diskTotalSpace) && $diskTotalSpace > 0) {
                    $dbUsagePercentage = ($totalSize / $diskTotalSpace) * 100;
                    $result['database_size']['disk_usage_percentage'] = round($dbUsagePercentage, 2);
                }
            } catch (\Exception $e) {
                Log::warning('无法获取MySQL磁盘使用信息', ['error' => $e->getMessage()]);
            }

            Log::debug('MySQL磁盘使用情况', $result);

            return $res;
        } catch (\Exception $e) {
            Log::error('MySQL健康检查失败', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 获取系统负载平均值
     */
    protected function getLoadAverage(): ?array
    {
        try {
            if (function_exists('sys_getloadavg')) {
                return sys_getloadavg();
            }
            return null;
        } catch (\Exception $e) {
            Log::debug('系统负载获取失败', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
