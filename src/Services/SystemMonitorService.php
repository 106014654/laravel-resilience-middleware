<?php

namespace OneLap\LaravelResilienceMiddleware\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * 系统监控服务
 * 监控CPU、内存、Redis、MySQL等系统资源使用情况
 */
class SystemMonitorService
{
    protected $redis;
    protected $config;

    public function __construct()
    {
        $this->config = config('resilience.system_monitor', []);
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

    /**
     * 获取CPU使用率
     */
    protected function getCpuUsage(): ?float
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return $this->getCpuUsageWindows();
        } else {
            return $this->getCpuUsageLinux();
        }
    }

    /**
     * Windows系统CPU使用率
     */
    protected function getCpuUsageWindows(): ?float
    {
        try {
            // 方法1: 尝试使用 typeperf
            $output = shell_exec('typeperf "\\Processor(_Total)\\% Processor Time" -sc 1 2>nul');
            if ($output && preg_match('/\d+\.\d+/', $output, $matches)) {
                return (float) $matches[0];
            }

            // 方法2: 尝试使用 wmic
            $output = shell_exec('wmic cpu get loadpercentage /value 2>nul');
            if ($output && preg_match('/LoadPercentage=(\d+)/', $output, $matches)) {
                return (float) $matches[1];
            }

            // 方法3: PowerShell方法
            $cmd = 'powershell "Get-Counter \'\\Processor(_Total)\\% Processor Time\' -SampleInterval 1 -MaxSamples 1 | ForEach-Object {$_.CounterSamples.CookedValue}"';
            $output = shell_exec($cmd . ' 2>nul');
            if ($output && is_numeric(trim($output))) {
                return (float) trim($output);
            }

            // 方法4: 估算方法（基于系统负载）
            return $this->estimateCpuUsage();
        } catch (\Exception $e) {
            Log::debug('CPU监控失败', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Linux系统CPU使用率
     */
    protected function getCpuUsageLinux(): ?float
    {
        try {
            $load = sys_getloadavg();
            if ($load !== false && isset($load[0])) {
                // 获取CPU核心数
                $cpuCount = (int) shell_exec('nproc') ?: 1;
                return min(100, ($load[0] / $cpuCount) * 100);
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('CPU监控失败', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 估算CPU使用率
     */
    protected function estimateCpuUsage(): float
    {
        // 基于当前进程数和系统负载进行估算
        $processes = (int) shell_exec('tasklist | find /c "."') ?: 50;
        return min(100, $processes / 10); // 简单估算
    }

    /**
     * 获取内存使用率
     */
    protected function getMemoryUsage(): ?float
    {
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                return $this->getMemoryUsageWindows();
            } else {
                return $this->getMemoryUsageLinux();
            }
        } catch (\Exception $e) {
            Log::debug('内存监控失败', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Windows内存使用率
     */
    protected function getMemoryUsageWindows(): ?float
    {
        $output = shell_exec('wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /value 2>nul');
        if ($output) {
            preg_match('/FreePhysicalMemory=(\d+)/', $output, $free);
            preg_match('/TotalVisibleMemorySize=(\d+)/', $output, $total);

            if (isset($free[1]) && isset($total[1])) {
                $freeMemory = (int) $free[1];
                $totalMemory = (int) $total[1];
                return (($totalMemory - $freeMemory) / $totalMemory) * 100;
            }
        }

        return null;
    }

    /**
     * Linux内存使用率
     */
    protected function getMemoryUsageLinux(): ?float
    {
        $meminfo = file_get_contents('/proc/meminfo');
        if ($meminfo) {
            preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);

            if (isset($total[1]) && isset($available[1])) {
                $totalMemory = (int) $total[1];
                $availableMemory = (int) $available[1];
                return (($totalMemory - $availableMemory) / $totalMemory) * 100;
            }
        }

        return null;
    }

    /**
     * 获取Redis健康状态
     */
    protected function getRedisHealth(): ?float
    {
        try {
            $timeout = $this->config['redis']['connection_timeout'] ?? 2;

            // 延迟连接Redis
            $redis = Redis::connection();
            $start = microtime(true);
            $pong = $redis->ping();
            $responseTime = (microtime(true) - $start) * 1000; // 毫秒

            if ($pong === 'PONG' || $pong === true) {
                // 基于响应时间计算健康度
                if ($responseTime < 10) return 10;      // 优秀
                if ($responseTime < 50) return 30;      // 良好
                if ($responseTime < 100) return 60;     // 一般
                if ($responseTime < 500) return 80;     // 较差
                return 95; // 很差但仍可用
            }

            return 100; // Redis不可用
        } catch (\Exception $e) {
            Log::debug('Redis不可用', ['error' => $e->getMessage()]);
            return 100;
        }
    }

    /**
     * 获取MySQL健康状态
     */
    protected function getMysqlHealth(): ?float
    {
        try {
            $timeout = $this->config['mysql']['query_timeout'] ?? 2;

            $start = microtime(true);
            $result = DB::select("SHOW STATUS WHERE Variable_name IN ('Threads_connected', 'Max_used_connections', 'Queries', 'Slow_queries', 'Uptime')");
            $responseTime = (microtime(true) - $start) * 1000;

            if (!empty($result)) {
                // 基于响应时间和连接状态计算健康度
                if ($responseTime < 20) return 10;      // 优秀
                if ($responseTime < 100) return 30;     // 良好
                if ($responseTime < 200) return 60;     // 一般
                if ($responseTime < 1000) return 80;    // 较差
                return 95; // 很差但仍可用
            }

            return 100; // MySQL查询失败
        } catch (\Exception $e) {
            Log::debug('MySQL不可用', ['error' => $e->getMessage()]);
            return 100;
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
