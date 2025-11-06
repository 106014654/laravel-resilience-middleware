<?php

namespace OneLap\LaravelResilienceMiddleware\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use OneLap\LaravelResilienceMiddleware\Services\SystemMonitorService;

/**
 * 熔断器中间件
 * 实现熔断器模式，防止服务雪崩
 */
class CircuitBreakerMiddleware
{
    const STATE_CLOSED = 'closed';
    const STATE_OPEN = 'open';
    const STATE_HALF_OPEN = 'half_open';

    protected $redis;

    protected $systemMonitor;

    protected $config;


    public function __construct(SystemMonitorService $systemMonitor)
    {
        $this->systemMonitor = $systemMonitor;

        $this->config = config('resilience.system_monitor', []);

        // 初始化Redis连接
        if (isset($this->config['redis']['connection'])) {
            $this->redis = Redis::connection($this->config['redis']['connection']);
        } else {
            $this->redis = Redis::connection('default');
        }
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $service 服务名称
     * @param  int|null  $failureThreshold 失败率阈值（百分比），null表示使用配置文件
     * @param  int|null  $recoveryTimeout 恢复超时时间（秒），null表示使用配置文件
     * @param  int|null  $successThreshold 半开状态成功阈值，null表示使用配置文件
     * @param  int|null  $windowSize 滑动窗口大小（秒），null表示使用配置文件
     * @param  int|null  $minRequestCount 最小请求数，null表示使用配置文件
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $service = 'default', $failureThreshold = null, $recoveryTimeout = null, $successThreshold = null, $windowSize = null, $minRequestCount = null)
    {
        // 从配置文件获取默认参数
        $config = config('resilience.circuit_breaker', []);
        $failureThreshold = $failureThreshold ?? $config['failure_threshold'] ?? 50;
        $recoveryTimeout = $recoveryTimeout ?? $config['recovery_timeout'] ?? 60;
        $successThreshold = $successThreshold ?? $config['success_threshold'] ?? 3;
        $windowSize = $windowSize ?? $config['window_size'] ?? 60;
        $minRequestCount = $minRequestCount ?? $config['min_request_count'] ?? 10;

        $circuitKey = $this->getCircuitKey($service, $request);
        $state = $this->getCircuitState($circuitKey);

        // 根据配置决定是否记录详细日志
        $enableDetailedLogging = $this->config['monitoring']['enable_detailed_logging'] ?? false;
        if ($enableDetailedLogging) {
            Log::info("Circuit Breaker State for {$service}: {$state}", [
                'circuit_key' => $circuitKey,
                'config' => [
                    'failure_threshold' => $failureThreshold,
                    'recovery_timeout' => $recoveryTimeout,
                    'success_threshold' => $successThreshold,
                    'window_size' => $windowSize,
                    'min_request_count' => $minRequestCount
                ]
            ]);
        }

        // 清理过期的滑动窗口数据
        $this->cleanExpiredWindowData($circuitKey, $windowSize);

        // 检查熔断器状态
        if ($state === self::STATE_OPEN) {
            if ($this->shouldAttemptReset($circuitKey, $recoveryTimeout)) {
                $this->setCircuitState($circuitKey, self::STATE_HALF_OPEN);
            } else {
                return $this->buildCircuitOpenResponse($service);
            }
        }

        if ($enableDetailedLogging) {
            Log::info("Processing request for service {$service} with circuit state {$state}");
        }
        $startTime = microtime(true);

        try {
            $response = $next($request);
            $responseTime = (microtime(true) - $startTime) * 1000; // ms

            // 检查响应是否成功
            if ($this->isSuccessResponse($response, $responseTime)) {
                $this->recordSuccess($circuitKey, $state, $successThreshold);
            } else {
                $this->recordFailure($circuitKey, $state, $failureThreshold, $minRequestCount);
            }
            return $response;
        } catch (\Exception $e) {
            $this->recordFailure($circuitKey, $state, $failureThreshold, $minRequestCount);
            throw $e;
        }
    }

    /**
     * 获取熔断器键
     * 
     * 使用IP+端口号或域名作为键的一部分，更好地区分不同的服务实例
     * 
     * @param string $service 服务名称
     * @param Request $request 请求对象
     * @return string 熔断器键
     */
    protected function getCircuitKey($service, Request $request)
    {
        // 构建服务标识符：优先使用域名，其次使用IP+端口
        $serviceIdentifier = $this->buildServiceIdentifier($request);

        // 拼接完整的标识符

        return "circuit_breaker:{$service}:" . md5($serviceIdentifier);
    }

    /**
     * 构建服务标识符
     * 
     * 优先使用域名，如果没有域名则使用IP+端口的组合
     * 
     * @param Request $request 请求对象
     * @return string 服务标识符
     */
    protected function buildServiceIdentifier(Request $request)
    {
        // 优先获取域名
        $host = $request->getHost();
        if ($host && $host !== 'localhost' && !filter_var($host, FILTER_VALIDATE_IP)) {
            // 如果是有效的域名（非localhost且非IP地址）
            $port = $request->getPort();
            // 标准端口（80/443）可以省略，非标准端口需要包含
            if (($request->isSecure() && $port != 443) || (!$request->isSecure() && $port != 80)) {
                return $host . ':' . $port;
            }
            return $host;
        }

        // 如果没有有效域名，使用IP+端口
        $serverIp = $this->getServerIp($request);
        $port = $request->getPort();

        return $serverIp . ':' . $port;
    }

    /**
     * 获取服务器IP地址
     * 
     * @param Request $request 请求对象
     * @return string IP地址
     */
    protected function getServerIp(Request $request)
    {
        // 优先从HTTP_HOST获取
        $httpHost = $request->header('Host');
        if ($httpHost && filter_var(explode(':', $httpHost)[0], FILTER_VALIDATE_IP)) {
            return explode(':', $httpHost)[0];
        }

        // 从SERVER变量获取
        $serverAddr = $request->server('SERVER_ADDR');
        if ($serverAddr && filter_var($serverAddr, FILTER_VALIDATE_IP)) {
            return $serverAddr;
        }

        // 从HTTP_X_FORWARDED_FOR获取（负载均衡场景）
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor) {
            $ips = explode(',', $forwardedFor);
            $firstIp = trim($ips[0]);
            if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
                return $firstIp;
            }
        }

        // 从HTTP_X_REAL_IP获取（代理场景）
        $realIp = $request->header('X-Real-IP');
        if ($realIp && filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        // 最后使用请求的IP
        $clientIp = $request->ip();
        if ($clientIp && filter_var($clientIp, FILTER_VALIDATE_IP)) {
            return $clientIp;
        }

        // 默认返回本地IP
        return '127.0.0.1';
    }

    /**
     * 获取熔断器状态
     */
    protected function getCircuitState($circuitKey)
    {
        $circuit = $this->getCircuitData($circuitKey);
        return $circuit['state'] ?? self::STATE_CLOSED;
    }

    /**
     * 设置熔断器状态
     * 
     * 更新熔断器状态并记录状态变更时间，保持滑动窗口数据的完整性
     * 
     * @param string $circuitKey 熔断器键
     * @param string $state 新的状态
     */
    protected function setCircuitState($circuitKey, $state)
    {
        $circuit = $this->getCircuitData($circuitKey);
        $now = time();
        $previousState = $circuit['state'] ?? self::STATE_CLOSED;

        $circuit['state'] = $state;
        $circuit['state_changed_at'] = $now;

        if ($state === self::STATE_OPEN) {
            $circuit['opened_at'] = $now;
            Log::warning("Circuit breaker opened", [
                'circuit_key' => $circuitKey,
                'previous_state' => $previousState
            ]);
        } elseif ($state === self::STATE_CLOSED) {
            $circuit['closed_at'] = $now;
            // 关闭状态时可以选择清理部分滑动窗口数据以节省存储空间
            // 但保留一些历史数据用于分析
            if (isset($circuit['sliding_window']) && count($circuit['sliding_window']) > 100) {
                // 只保留最近的100条记录
                $circuit['sliding_window'] = array_slice($circuit['sliding_window'], -100);
            }
            Log::info("Circuit breaker closed", [
                'circuit_key' => $circuitKey,
                'previous_state' => $previousState
            ]);
        } elseif ($state === self::STATE_HALF_OPEN) {
            // 半开状态时保留滑动窗口数据，用于统计成功请求
            Log::info("Circuit breaker half-opened", [
                'circuit_key' => $circuitKey,
                'previous_state' => $previousState
            ]);
        }

        $this->saveCircuitData($circuitKey, $circuit);
    }

    /**
     * 获取熔断器数据
     */
    protected function getCircuitData($circuitKey)
    {
        try {

            // 兼容 predis 和 phpredis
            $data = method_exists($this->redis, '__call')
                ? $this->redis->__call('get', [$circuitKey])  // predis
                : $this->redis->get($circuitKey);             // phpredis

            if (is_string($data) && strlen($data) > 0) {
                $decoded = json_decode($data, true);
                // 如果解码失败，返回默认值
                return is_array($decoded) ? $decoded : $this->getDefaultCircuitData();
            }
            // 如果没有数据，返回默认值
            return $this->getDefaultCircuitData();
        } catch (\Exception $e) {
            Log::warning('获取熔断器数据失败，使用默认值', ['key' => $circuitKey, 'error' => $e->getMessage()]);
            return $this->getDefaultCircuitData();
        }
    }

    /**
     * 保存熔断器数据
     */
    protected function saveCircuitData($circuitKey, $data)
    {
        try {
            $jsonData = json_encode($data);

            // 兼容 predis 和 phpredis
            if (method_exists($this->redis, '__call')) {
                $this->redis->__call('setex', [$circuitKey, 3600, $jsonData]);  // predis
            } else {
                $this->redis->setex($circuitKey, 3600, $jsonData);              // phpredis
            }
        } catch (\Exception $e) {
            Log::error('保存熔断器数据失败', ['key' => $circuitKey, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 获取默认熔断器数据
     */
    protected function getDefaultCircuitData()
    {
        return [
            'state' => self::STATE_CLOSED,
            'created_at' => time(),
            'state_changed_at' => time(),
            'sliding_window' => [] // 滑动窗口数据
        ];
    }

    /**
     * 清理过期的滑动窗口数据
     * 
     * 移除滑动窗口中超出时间范围的请求记录，保持窗口数据的准确性
     * 
     * @param string $circuitKey 熔断器键
     * @param int $windowSize 窗口大小（秒）
     */
    protected function cleanExpiredWindowData($circuitKey, $windowSize)
    {
        $circuit = $this->getCircuitData($circuitKey);
        $currentTime = time();
        $cutoffTime = $currentTime - $windowSize;

        // 清理过期的滑动窗口数据
        if (isset($circuit['sliding_window']) && is_array($circuit['sliding_window'])) {
            $circuit['sliding_window'] = array_filter($circuit['sliding_window'], function ($record) use ($cutoffTime) {
                return $record['timestamp'] >= $cutoffTime;
            });

            // 重新索引数组
            $circuit['sliding_window'] = array_values($circuit['sliding_window']);

            $this->saveCircuitData($circuitKey, $circuit);
        }
    }

    /**
     * 计算滑动窗口内的统计数据
     * 
     * @param array $slidingWindow 滑动窗口数据
     * @return array 包含总请求数、成功数、失败数、失败率等统计信息
     */
    protected function calculateWindowStats($slidingWindow)
    {
        if (empty($slidingWindow)) {
            return [
                'total_requests' => 0,
                'success_count' => 0,
                'failure_count' => 0,
                'failure_rate' => 0
            ];
        }

        $totalRequests = count($slidingWindow);
        $successCount = 0;
        $failureCount = 0;

        foreach ($slidingWindow as $record) {
            if ($record['success']) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        $failureRate = $totalRequests > 0 ? ($failureCount / $totalRequests) : 0;

        return [
            'total_requests' => $totalRequests,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'failure_rate' => $failureRate
        ];
    }

    /**
     * 检查是否应该尝试重置
     */
    protected function shouldAttemptReset($circuitKey, $recoveryTimeout)
    {
        $circuit = $this->getCircuitData($circuitKey);
        $openedAt = $circuit['opened_at'] ?? time();

        return (time() - $openedAt) >= $recoveryTimeout;
    }

    /**
     * 记录成功
     * 
     * 使用滑动窗口记录成功的请求，提供更准确的成功率统计
     * 
     * @param string $circuitKey 熔断器键
     * @param string $currentState 当前熔断器状态
     * @param int $successThreshold 半开状态成功阈值
     * @param int $windowSize 滑动窗口大小（秒）
     * @param array $config 配置数组
     */
    protected function recordSuccess($circuitKey, $currentState, $successThreshold)
    {
        $circuit = $this->getCircuitData($circuitKey);
        $currentTime = time();

        // 初始化滑动窗口
        if (!isset($circuit['sliding_window'])) {
            $circuit['sliding_window'] = [];
        }

        // 添加成功记录到滑动窗口
        $circuit['sliding_window'][] = [
            'timestamp' => $currentTime,
            'success' => true,
            'response_time' => microtime(true) // 可选：记录响应时间用于分析
        ];

        if ($currentState === self::STATE_HALF_OPEN) {
            // 在半开状态下，计算最近的成功请求数
            $recentSuccessCount = 0;
            $halfOpenStartTime = $circuit['state_changed_at'] ?? $currentTime;

            foreach ($circuit['sliding_window'] as $record) {
                if ($record['timestamp'] >= $halfOpenStartTime && $record['success']) {
                    $recentSuccessCount++;
                }
            }
            $enableDetailedLogging = $this->config['monitoring']['enable_detailed_logging'] ?? false;
            if ($enableDetailedLogging) {
                Log::info("Half-open state success tracking", [
                    'circuit_key' => $circuitKey,
                    'recent_success_count' => $recentSuccessCount,
                    'success_threshold' => $successThreshold,
                    'half_open_start_time' => $halfOpenStartTime
                ]);
            }

            // 当成功请求数达到阈值时，转为关闭状态
            if ($recentSuccessCount >= $successThreshold) {
                $enableDetailedLogging = $this->config['monitoring']['enable_detailed_logging'] ?? false;
                if ($enableDetailedLogging) {
                    Log::info("Success threshold reached in half-open state", [
                        'circuit_key' => $circuitKey,
                        'recent_success_count' => $recentSuccessCount,
                        'success_threshold' => $successThreshold
                    ]);
                }

                $this->setCircuitState($circuitKey, self::STATE_CLOSED);
                return;
            }
        }

        $this->saveCircuitData($circuitKey, $circuit);
    }

    /**
     * 记录失败
     * 
     * 使用滑动窗口记录失败的请求，并根据失败率和阈值决定是否触发熔断
     * 
     * @param string $circuitKey 熔断器键
     * @param string $currentState 当前熔断器状态
     * @param int $failureThreshold 失败阈值（失败率百分比，如50表示50%）
     * @param int $windowSize 滑动窗口大小（秒）
     * @param int $minRequestCount 最小请求数，低于此数不触发熔断
     * @param array $config 配置数组
     */
    protected function recordFailure($circuitKey, $currentState, $failureThreshold, $minRequestCount)
    {
        $circuit = $this->getCircuitData($circuitKey);
        $currentTime = time();

        // 初始化滑动窗口
        if (!isset($circuit['sliding_window'])) {
            $circuit['sliding_window'] = [];
        }

        // 添加失败记录到滑动窗口
        $circuit['sliding_window'][] = [
            'timestamp' => $currentTime,
            'success' => false,
            'response_time' => microtime(true)
        ];

        if ($currentState === self::STATE_HALF_OPEN) {
            // 半开状态下任何失败都立即转为打开状态
            Log::warning("Circuit breaker opening: failure in half-open state", [
                'circuit_key' => $circuitKey
            ]);
            $this->setCircuitState($circuitKey, self::STATE_OPEN);
            return;
        }

        if ($currentState === self::STATE_CLOSED) {
            // 闭合状态下分析滑动窗口数据决定是否熔断
            $stats = $this->calculateWindowStats($circuit['sliding_window']);

            $enableDetailedLogging = $this->config['monitoring']['enable_detailed_logging'] ?? false;
            if ($enableDetailedLogging) {
                Log::info("Sliding window stats for failure analysis", [
                    'circuit_key' => $circuitKey,
                    'window_stats' => $stats,
                    'failure_threshold' => $failureThreshold,
                    'min_request_count' => $minRequestCount
                ]);
            }


            // 检查是否满足熔断条件：
            // 1. 请求数量达到最小阈值
            // 2. 失败率超过配置的阈值
            if (
                $stats['total_requests'] >= $minRequestCount &&
                ($stats['failure_rate'] * 100) >= $failureThreshold
            ) {
                $enableDetailedLogging = $this->config['monitoring']['enable_detailed_logging'] ?? false;
                if ($enableDetailedLogging) {
                    Log::warning("Circuit breaker opening: failure rate threshold exceeded", [
                        'circuit_key' => $circuitKey,
                        'failure_rate' => $stats['failure_rate'] * 100,
                        'threshold' => $failureThreshold,
                        'total_requests' => $stats['total_requests'],
                        'failure_count' => $stats['failure_count']
                    ]);
                }
                $this->setCircuitState($circuitKey, self::STATE_OPEN);
                return;
            }
        }

        $this->saveCircuitData($circuitKey, $circuit);
    }

    /**
     * 检查响应是否成功
     */
    protected function isSuccessResponse($response, $responseTime)
    {
        $statusCode = $response->getStatusCode();

        // HTTP 状态码检查
        if ($statusCode >= 500) {
            return false;
        }

        // 响应时间检查（可配置）
        $maxResponseTime = config('resilience.circuit_breaker.max_response_time', 5000); // 5秒
        if ($responseTime > $maxResponseTime) {
            return false;
        }


        return true;
    }

    /**
     * 构建熔断器开启响应
     */
    protected function buildCircuitOpenResponse($service)
    {
        return response()->json([
            'message' => 'Service temporarily unavailable',
            'error' => 'circuit_breaker_open',
            'service' => $service,
            'retry_after' => 60
        ], 503, [
            'X-Circuit-Breaker-State' => 'open',
            'X-Service-Name' => $service,
            'Retry-After' => 60
        ]);
    }
}
