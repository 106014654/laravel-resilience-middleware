<?php

namespace OneLap\LaravelResilienceMiddleware\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use OneLap\LaravelResilienceMiddleware\Services\SystemMonitorService;

/**
 * 限流中间件
 * 支持多种限流策略：固定窗口、滑动窗口、令牌桶
 */
class RateLimitingMiddleware
{
    protected $systemMonitor;

    protected $redis;

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
     * @param  string  $strategy 限流策略 (fixed_window|sliding_window|token_bucket)
     * @param  int  $maxAttempts 最大请求数
     * @param  int  $decayMinutes 时间窗口（分钟）
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $strategy = 'sliding_window', $maxAttempts = 60, $decayMinutes = 1)
    {
        // 获取配置
        $config = config('resilience.rate_limiting', []);
        $enabled = $config['enabled'] ?? true;

        // 如果限流被禁用，直接通过
        if (!$enabled) {
            return $next($request);
        }

        $key = $this->generateKey($request);

        // 获取当前系统资源状态
        $resourceStatus = $this->systemMonitor->getResourceStatus();

        // 根据各资源状态独立计算限流参数
        $adjustedMaxAttempts = $this->calculateResourceBasedRateLimit($maxAttempts, $resourceStatus, $config);

        if ($this->shouldRateLimit($key, $strategy, $adjustedMaxAttempts, $decayMinutes, $config)) {
            // 记录限流命中事件
            $this->logRateLimitHit($key, $strategy, $adjustedMaxAttempts, $decayMinutes, $resourceStatus, $config);
            return $this->buildRateLimitResponse($adjustedMaxAttempts, $decayMinutes, $resourceStatus);
        }

        // 记录允许通过的请求（如果启用）
        $this->logAllowedRequest($key, $strategy, $adjustedMaxAttempts, $decayMinutes, $resourceStatus, $config);

        $response = $next($request);

        // 添加限流头部信息
        return $this->addRateLimitHeaders($response, $key, $adjustedMaxAttempts, $decayMinutes);
    }

    /**
     * 检查是否应该限流
     */
    protected function shouldRateLimit($key, $strategy, $maxAttempts, $decayMinutes, $config = [])
    {
        switch ($strategy) {
            case 'fixed_window':
                return $this->fixedWindowRateLimit($key, $maxAttempts, $decayMinutes, $config);
            case 'sliding_window':
                return $this->slidingWindowRateLimit($key, $maxAttempts, $decayMinutes, $config);
            case 'token_bucket':
                return $this->tokenBucketRateLimit($key, $maxAttempts, $decayMinutes, $config);
            default:
                return $this->slidingWindowRateLimit($key, $maxAttempts, $decayMinutes, $config);
        }
    }

    /**
     * 固定窗口限流
     */
    protected function fixedWindowRateLimit($key, $maxAttempts, $decayMinutes, $config = [])
    {
        try {
            $windowKey = $key . ':' . floor(time() / ($decayMinutes * 60));

            // 使用 Lua 脚本保证原子性
            $luaScript = <<<'LUA'
            local current = redis.call('INCR', KEYS[1])
            if current == 1 then
                redis.call('EXPIRE', KEYS[1], ARGV[1])
            end
            return current
LUA;

            // 执行 Lua 脚本并获取结果
            $attempts = $this->redis->eval($luaScript, 1, $windowKey, $decayMinutes * 60);

            $enableDetailedLogging = $config['monitoring']['enable_detailed_logging'] ?? false;
            if ($enableDetailedLogging) {
                Log::info('Fixed window rate limit check', [
                    'key' => $key,
                    'window_key' => $windowKey,
                    'attempts' => $attempts,
                    'max_attempts' => $maxAttempts
                ]);
            }
            
            return intval($attempts) > $maxAttempts;
        } catch (\Exception $e) {
            Log::warning('Redis限流失败，回退到内存限流', ['error' => $e->getMessage()]);
            return $this->memoryRateLimit($key, $maxAttempts, $decayMinutes);
        }
    }

    /**
     * 滑动窗口限流
     */
    protected function slidingWindowRateLimit($key, $maxAttempts, $decayMinutes, $config = [])
    {
        $now = time();
        $window = $decayMinutes * 60;

        try {

            // 使用 Lua 脚本确保原子性
            $luaScript = <<<'LUA'
            -- 清理过期记录
            redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, ARGV[1])
            -- 添加当前请求
            redis.call('ZADD', KEYS[1], ARGV[2], ARGV[3])
            -- 获取当前窗口内的请求数
            local count = redis.call('ZCARD', KEYS[1])
            -- 更新过期时间
            redis.call('EXPIRE', KEYS[1], ARGV[4])
            -- 返回当前请求数
            return count
LUA;

            // 执行 Lua 脚本
            $count = $this->redis->eval(
                $luaScript,
                1,
                $key,
                $now - $window,             // ARGV[1] 清理时间点
                $now,                       // ARGV[2] 当前时间戳（score）
                $now . ':' . uniqid(),     // ARGV[3] 唯一标识（member）
                $window                     // ARGV[4] 过期时间
            );
            $enableDetailedLogging = $config['monitoring']['enable_detailed_logging'] ?? false;
            if ($enableDetailedLogging) {
                Log::info('Sliding window rate limit check', [
                    'key' => $key,
                    'count' => $count,
                    'max_attempts' => $maxAttempts
                ]);
            }
            return intval($count) >= $maxAttempts;
        } catch (\Exception $e) {
            Log::warning('Redis限流失败，使用内存备用方案', ['error' => $e->getMessage()]);
            return $this->memoryRateLimit($key, $maxAttempts, $decayMinutes);
        }
    }

    /**
     * 令牌桶限流
     */
    protected function tokenBucketRateLimit($key, $maxAttempts, $decayMinutes, $config = [])
    {
        try {
            $now = time();
            $refillRate = $maxAttempts / ($decayMinutes * 60); // 每秒补充的令牌数

            // 使用 Lua 脚本确保原子性
            $luaScript = <<<'LUA'
            local bucket = redis.call('HGETALL', KEYS[1])
            local now = tonumber(ARGV[1])
            local maxTokens = tonumber(ARGV[2])
            local refillRate = tonumber(ARGV[3])
            local tokens = maxTokens
            local lastRefill = now
            
            -- 如果桶已存在，获取当前状态
            if #bucket > 0 then
                tokens = tonumber(bucket[2])
                lastRefill = tonumber(bucket[4])
                
                -- 计算需要补充的令牌
                local timeDiff = now - lastRefill
                local tokensToAdd = timeDiff * refillRate
                tokens = math.min(maxTokens, tokens + tokensToAdd)
            end
            
            -- 尝试消耗令牌
            if tokens < 1 then
                -- 更新桶状态
                redis.call('HMSET', KEYS[1], 'tokens', tokens, 'last_refill', now)
                redis.call('EXPIRE', KEYS[1], ARGV[4])
                return 0
            end
            
            -- 消耗一个令牌
            tokens = tokens - 1
            redis.call('HMSET', KEYS[1], 'tokens', tokens, 'last_refill', now)
            redis.call('EXPIRE', KEYS[1], ARGV[4])
            return 1
LUA;

            // 执行 Lua 脚本
            $result = $this->redis->eval(
                $luaScript,
                1,                          // 1 个 key
                $key,                     // KEYS[1]
                $now,                   // ARGV[1] 当前时间
                $maxAttempts,           // ARGV[2] 最大令牌数
                $refillRate,            // ARGV[3] 补充速率
                $decayMinutes * 60      // ARGV[4] 过期时间
            );

            return $result === 0;  // 0 表示没有令牌可用（应该限流）
        } catch (\Exception $e) {
            Log::warning('Redis令牌桶限流失败，回退到内存限流', ['error' => $e->getMessage()]);
            return $this->memoryRateLimit($key, $maxAttempts, $decayMinutes);
        }
    }

    /**
     * 内存限流备用方案
     */
    protected function memoryRateLimit($key, $maxAttempts, $decayMinutes)
    {
        static $memoryLimiter = [];

        $windowKey = $key . ':' . floor(time() / ($decayMinutes * 60));

        if (!isset($memoryLimiter[$windowKey])) {
            $memoryLimiter[$windowKey] = 0;
        }

        if ($memoryLimiter[$windowKey] >= $maxAttempts) {
            return true;
        }

        $memoryLimiter[$windowKey]++;
        return false;
    }

    /**
     * 根据各资源状态独立计算限流参数
     * 采用最严格的限流策略（取最小值）
     */
    protected function calculateResourceBasedRateLimit($maxAttempts, array $resourceStatus, array $config = [])
    {
        $resourceThresholds = config('resilience.rate_limiting.resource_thresholds', []);
        $strictestFactor = 1.0; // 默认不限流
        $triggeredResource = null;

        foreach ($resourceStatus as $resource => $usage) {
            if (!isset($resourceThresholds[$resource])) {
                continue;
            }

            foreach ($resourceThresholds[$resource] as $threshold => $factor) {
                if ($usage >= $threshold) {
                    // 找到最严格的限流因子
                    if ($factor < $strictestFactor) {
                        $strictestFactor = $factor;
                        $triggeredResource = $resource;
                    }
                }
            }
        }

        $adjustedAttempts = max(1, (int)ceil($maxAttempts * $strictestFactor));

        // 根据配置决定是否记录限流触发日志
        if ($strictestFactor < 1.0) {
            $enableDetailedLogging = $config['monitoring']['enable_detailed_logging'] ?? false;
            if ($enableDetailedLogging) {
                Log::info('Resource-based rate limiting triggered', [
                    'resource' => $triggeredResource,
                    'usage' => $resourceStatus[$triggeredResource] ?? 'unknown',
                    'factor' => $strictestFactor,
                    'original_limit' => $maxAttempts,
                    'adjusted_limit' => $adjustedAttempts
                ]);
            }
        }

        return $adjustedAttempts;
    }


    /**
     * 生成限流键
     */
    protected function generateKey(Request $request)
    {
        // 构建服务标识符：优先使用域名，其次使用IP+端口
        $serviceIdentifier = $this->buildServiceIdentifier($request);
        return 'rate_limit:' . md5($serviceIdentifier);
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
     * 构建限流响应
     */
    protected function buildRateLimitResponse($maxAttempts, $decayMinutes, $resourceStatus = [])
    {
        $response = [
            'message' => 'Too many requests',
            'error' => 'rate_limit_exceeded',
            'retry_after' => $decayMinutes * 60
        ];

        // 添加资源状态信息
        if (!empty($resourceStatus)) {
            $response['rate_limit_info'] = [
                'trigger_resource' => $this->getTriggeredResource($resourceStatus),
                'resource_usage' => $resourceStatus
            ];
        }

        return response()->json($response, 429, [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => time() + ($decayMinutes * 60),
            'X-RateLimit-Reason' => 'resource_pressure'
        ]);
    }

    /**
     * 获取触发限流的资源
     */
    protected function getTriggeredResource(array $resourceStatus)
    {
        $resourceThresholds = config('resilience.rate_limiting.resource_thresholds', []);
        $triggeredResources = [];

        foreach ($resourceStatus as $resource => $usage) {
            if (!isset($resourceThresholds[$resource])) {
                continue;
            }

            foreach ($resourceThresholds[$resource] as $threshold => $factor) {
                if ($usage >= $threshold) {
                    $triggeredResources[$resource] = [
                        'usage' => $usage,
                        'threshold' => $threshold,
                        'factor' => $factor
                    ];
                    break; // 找到第一个匹配的阈值就跳出
                }
            }
        }

        return $triggeredResources;
    }

    /**
     * 添加限流头部信息
     */
    protected function addRateLimitHeaders($response, $key, $maxAttempts, $decayMinutes)
    {
        // 这里简化处理，实际应用中需要根据具体策略计算剩余次数
        $remaining = max(0, $maxAttempts - 1);

        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', $remaining);
        $response->headers->set('X-RateLimit-Reset', time() + ($decayMinutes * 60));

        return $response;
    }

    /**
     * 记录限流命中事件
     * 
     * @param string $key 限流键
     * @param string $strategy 限流策略
     * @param int $maxAttempts 最大尝试次数
     * @param int $decayMinutes 衰减时间（分钟）
     * @param array $resourceStatus 资源状态
     * @param array $config 配置数组
     */
    protected function logRateLimitHit($key, $strategy, $maxAttempts, $decayMinutes, array $resourceStatus, array $config)
    {
        $logRateLimitHits = $config['monitoring']['log_rate_limit_hits'] ?? true;
        $enableDetailedLogging = $config['monitoring']['enable_detailed_logging'] ?? false;

        if ($logRateLimitHits) {
            $logData = [
                'event' => 'rate_limit_hit',
                'key' => $key,
                'strategy' => $strategy,
                'max_attempts' => $maxAttempts,
                'decay_minutes' => $decayMinutes,
                'timestamp' => time()
            ];

            if ($enableDetailedLogging) {
                $logData['resource_status'] = $resourceStatus;
                $logData['triggered_resources'] = $this->getTriggeredResource($resourceStatus);
            }

            Log::warning('Rate limit exceeded', $logData);
        }
    }

    /**
     * 记录允许通过的请求
     * 
     * @param string $key 限流键
     * @param string $strategy 限流策略
     * @param int $maxAttempts 最大尝试次数
     * @param int $decayMinutes 衰减时间（分钟）
     * @param array $resourceStatus 资源状态
     * @param array $config 配置数组
     */
    protected function logAllowedRequest($key, $strategy, $maxAttempts, $decayMinutes, array $resourceStatus, array $config)
    {
        $logAllowedRequests = $config['monitoring']['log_allowed_requests'] ?? false;

        if ($logAllowedRequests) {
            $logData = [
                'event' => 'request_allowed',
                'key' => $key,
                'strategy' => $strategy,
                'max_attempts' => $maxAttempts,
                'decay_minutes' => $decayMinutes,
                'timestamp' => time()
            ];

            $enableDetailedLogging = $config['monitoring']['enable_detailed_logging'] ?? false;
            if ($enableDetailedLogging) {
                $logData['resource_status'] = $resourceStatus;
            }

            Log::info('Request allowed through rate limiter', $logData);
        }
    }

    /**
     * 获取限流统计信息
     * 
     * 提供限流中间件的统计数据，包括命中率、资源状态等
     * 
     * @return array 限流统计信息
     */
    public function getRateLimitStats()
    {
        $config = config('resilience.rate_limiting', []);
        $resourceStatus = $this->systemMonitor->getResourceStatus();

        return [
            'enabled' => $config['enabled'] ?? true,
            'current_resource_status' => $resourceStatus,
            'triggered_resources' => $this->getTriggeredResource($resourceStatus),
            'monitoring_config' => $config['monitoring'] ?? [],
            'resource_thresholds' => $config['resource_thresholds'] ?? [],
            'timestamp' => time()
        ];
    }
}
