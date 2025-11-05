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

    public function __construct(SystemMonitorService $systemMonitor)
    {
        $this->systemMonitor = $systemMonitor;
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
        $key = $this->generateKey($request);

        // 获取当前系统资源状态
        $resourceStatus = $this->systemMonitor->getResourceStatus();

        // 根据各资源状态独立计算限流参数
        $adjustedMaxAttempts = $this->calculateResourceBasedRateLimit($maxAttempts, $resourceStatus);

        if ($this->shouldRateLimit($key, $strategy, $adjustedMaxAttempts, $decayMinutes)) {
            return $this->buildRateLimitResponse($adjustedMaxAttempts, $decayMinutes, $resourceStatus);
        }

        $response = $next($request);

        // 添加限流头部信息
        return $this->addRateLimitHeaders($response, $key, $adjustedMaxAttempts, $decayMinutes);
    }

    /**
     * 检查是否应该限流
     */
    protected function shouldRateLimit($key, $strategy, $maxAttempts, $decayMinutes)
    {
        switch ($strategy) {
            case 'fixed_window':
                return $this->fixedWindowRateLimit($key, $maxAttempts, $decayMinutes);
            case 'sliding_window':
                return $this->slidingWindowRateLimit($key, $maxAttempts, $decayMinutes);
            case 'token_bucket':
                return $this->tokenBucketRateLimit($key, $maxAttempts, $decayMinutes);
            default:
                return $this->slidingWindowRateLimit($key, $maxAttempts, $decayMinutes);
        }
    }

    /**
     * 固定窗口限流
     */
    protected function fixedWindowRateLimit($key, $maxAttempts, $decayMinutes)
    {
        try {
            $windowKey = $key . ':' . floor(time() / ($decayMinutes * 60));
            $redis = Redis::connection();

            // 使用 Lua 脚本保证原子性
            $luaScript = <<<'LUA'
            local current = redis.call('INCR', KEYS[1])
            if current == 1 then
                redis.call('EXPIRE', KEYS[1], ARGV[1])
            end
            return current
LUA;

            // 执行 Lua 脚本并获取结果
            $attempts = $redis->eval($luaScript, 1, $windowKey, $decayMinutes * 60);

            return intval($attempts) > $maxAttempts;
        } catch (\Exception $e) {
            Log::warning('Redis限流失败，回退到内存限流', ['error' => $e->getMessage()]);
            return $this->memoryRateLimit($key, $maxAttempts, $decayMinutes);
        }
    }

    /**
     * 滑动窗口限流
     */
    protected function slidingWindowRateLimit($key, $maxAttempts, $decayMinutes)
    {
        $now = time();
        $window = $decayMinutes * 60;

        try {
            $redis = Redis::connection();

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
            $count = $redis->eval(
                $luaScript,
                [$key],                         // keys array
                [
                    $now - $window,             // ARGV[1] 清理时间点
                    $now,                       // ARGV[2] 当前时间戳（score）
                    $now . ':' . uniqid(),     // ARGV[3] 唯一标识（member）
                    $window                     // ARGV[4] 过期时间
                ]
            );

            return intval($count) >= $maxAttempts;
        } catch (\Exception $e) {
            Log::warning('Redis限流失败，使用内存备用方案', ['error' => $e->getMessage()]);
            return $this->memoryRateLimit($key, $maxAttempts, $decayMinutes);
        }
    }

    /**
     * 令牌桶限流
     */
    protected function tokenBucketRateLimit($key, $maxAttempts, $decayMinutes)
    {
        try {
            $now = time();
            $refillRate = $maxAttempts / ($decayMinutes * 60); // 每秒补充的令牌数
            $redis = Redis::connection();

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
            $result = $redis->eval(
                $luaScript,
                1,                          // 1 个 key
                [$key],                     // KEYS[1]
                [
                    $now,                   // ARGV[1] 当前时间
                    $maxAttempts,           // ARGV[2] 最大令牌数
                    $refillRate,            // ARGV[3] 补充速率
                    $decayMinutes * 60      // ARGV[4] 过期时间
                ]
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
    protected function calculateResourceBasedRateLimit($maxAttempts, array $resourceStatus)
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

        // 记录限流触发日志
        if ($strictestFactor < 1.0) {
            Log::info('Resource-based rate limiting triggered', [
                'resource' => $triggeredResource,
                'usage' => $resourceStatus[$triggeredResource] ?? 'unknown',
                'factor' => $strictestFactor,
                'original_limit' => $maxAttempts,
                'adjusted_limit' => $adjustedAttempts
            ]);
        }

        return $adjustedAttempts;
    }


    /**
     * 生成限流键
     */
    protected function generateKey(Request $request)
    {
        $route = $request->route() ? $request->route()->getName() : $request->getPathInfo();
        return 'rate_limit:' . md5($route . ':' . $request->ip());
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
}
