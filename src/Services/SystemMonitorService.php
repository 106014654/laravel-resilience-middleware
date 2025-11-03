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
    protected $redisConnectionTimeout = 2;  // Redis连接超时时间（秒）
    protected $mysqlConnectionTimeout = 3;  // MySQL连接超时时间（秒）

    public function __construct()
    {
        $this->config = config('resilience.system_monitor', []);
        $this->redisConnectionTimeout = $this->config['redis_connection_timeout'] ?? 2;
        $this->mysqlConnectionTimeout = $this->config['mysql_connection_timeout'] ?? 3;
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
            'load_average' => $this->getLoadAverage(),
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
            // 连接Redis并获取信息
            $redis = Redis::connection();
            $pong = $redis->ping();

            if ($pong !== 'PONG' && $pong !== true) {
                Log::warning('Redis ping failed: unexpected response');
                return null;
            }

            // 获取Redis服务器信息
            $info = $redis->info();

            // 计算内存使用率
            if (isset($info['used_memory'], $info['maxmemory']) && $info['maxmemory'] > 0) {
                $memoryUsage = ($info['used_memory'] / $info['maxmemory']) * 100;

                Log::debug('Redis内存使用情况', [
                    'used_memory' => $info['used_memory'],
                    'max_memory' => $info['maxmemory'],
                    'usage_percentage' => $memoryUsage
                ]);

                return round($memoryUsage, 2);
            }

            // 如果没有设置maxmemory，只返回已用内存大小（字节）
            if (isset($info['used_memory'])) {
                Log::debug('Redis内存使用情况（未设置最大内存）', [
                    'used_memory_bytes' => $info['used_memory'],
                    'used_memory_mb' => round($info['used_memory'] / 1024 / 1024, 2)
                ]);

                // 返回已用内存的MB数作为参考值
                return round($info['used_memory'] / 1024 / 1024, 2);
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
     * @return array|null 返回内存和磁盘使用情况，null表示无法获取状态
     */
    protected function getMysqlHealth(): ?array
    {
        try {
            // 基础连接检查
            DB::select('SELECT 1');

            // 获取MySQL状态和变量
            $status = collect(DB::select("SHOW GLOBAL STATUS"))->pluck('Value', 'Variable_name');
            $variables = collect(DB::select("SHOW GLOBAL VARIABLES"))->pluck('Value', 'Variable_name');

            $result = [];

            // 1. 缓冲池内存使用情况
            if ($status->has('Innodb_buffer_pool_pages_total') && $status->has('Innodb_buffer_pool_pages_free')) {
                $totalPages = (int)$status->get('Innodb_buffer_pool_pages_total');
                $freePages = (int)$status->get('Innodb_buffer_pool_pages_free');
                $pageSize = (int)$variables->get('innodb_page_size', 16384); // 默认16KB

                if ($totalPages > 0) {
                    $usedPages = $totalPages - $freePages;
                    $usageRate = ($usedPages / $totalPages) * 100;
                    $totalMemoryMB = ($totalPages * $pageSize) / 1024 / 1024;
                    $usedMemoryMB = ($usedPages * $pageSize) / 1024 / 1024;

                    $result['buffer_pool'] = [
                        'total_mb' => round($totalMemoryMB, 2),
                        'used_mb' => round($usedMemoryMB, 2),
                        'usage_percentage' => round($usageRate, 2)
                    ];
                }
            }

            // 2. 临时表磁盘使用情况
            if ($status->has('Created_tmp_disk_tables') && $status->has('Created_tmp_tables')) {
                $totalTempTables = (int)$status->get('Created_tmp_tables');
                $diskTempTables = (int)$status->get('Created_tmp_disk_tables');

                if ($totalTempTables > 0) {
                    $diskUsageRate = ($diskTempTables / $totalTempTables) * 100;
                    $result['temp_tables'] = [
                        'total_created' => $totalTempTables,
                        'disk_created' => $diskTempTables,
                        'disk_usage_percentage' => round($diskUsageRate, 2)
                    ];
                }
            }

            // 3. 数据库文件大小（磁盘使用）
            try {
                $databases = DB::select("SHOW DATABASES");
                $totalSize = 0;

                foreach ($databases as $db) {
                    if (!in_array($db->Database, ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                        $tables = DB::select("SELECT 
                            SUM(data_length + index_length) as size 
                            FROM information_schema.tables 
                            WHERE table_schema = ?", [$db->Database]);

                        if (isset($tables[0]->size)) {
                            $totalSize += (int)$tables[0]->size;
                        }
                    }
                }

                $result['database_size'] = [
                    'total_bytes' => $totalSize,
                    'total_mb' => round($totalSize / 1024 / 1024, 2),
                    'total_gb' => round($totalSize / 1024 / 1024 / 1024, 2)
                ];
            } catch (\Exception $e) {
                Log::warning('无法获取数据库大小信息', ['error' => $e->getMessage()]);
            }

            Log::debug('MySQL资源使用情况', $result);

            return $result;
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
