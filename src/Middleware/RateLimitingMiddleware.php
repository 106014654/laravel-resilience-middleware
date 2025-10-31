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
        $systemPressure = $this->systemMonitor->getSystemPressureLevel();

        // 根据系统压力调整限流参数
        $adjustedMaxAttempts = $this->adjustRateLimit($maxAttempts, $systemPressure);

        if ($this->shouldRateLimit($key, $strategy, $adjustedMaxAttempts, $decayMinutes)) {
            return $this->buildRateLimitResponse($adjustedMaxAttempts, $decayMinutes);
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
        $windowKey = $key . ':' . floor(time() / ($decayMinutes * 60));
        $attempts = Cache::get($windowKey, 0);

        if ($attempts >= $maxAttempts) {
            return true;
        }

        Cache::put($windowKey, $attempts + 1, $decayMinutes * 60);
        return false;
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
            $pipeline = $redis->pipeline();

            // 清理过期记录
            $pipeline->zremrangebyscore($key, 0, $now - $window);

            // 获取当前窗口内的请求数
            $pipeline->zcard($key);

            // 添加当前请求
            $pipeline->zadd($key, $now, $now . ':' . uniqid());

            // 设置过期时间
            $pipeline->expire($key, $window);

            $results = $pipeline->execute();
            $currentRequests = $results[1];

            return $currentRequests >= $maxAttempts;
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
        $now = time();
        $refillRate = $maxAttempts / ($decayMinutes * 60); // 每秒补充的令牌数

        $bucket = Cache::get($key, [
            'tokens' => $maxAttempts,
            'last_refill' => $now
        ]);

        // 计算需要补充的令牌数
        $timeDiff = $now - $bucket['last_refill'];
        $tokensToAdd = $timeDiff * $refillRate;
        $bucket['tokens'] = min($maxAttempts, $bucket['tokens'] + $tokensToAdd);
        $bucket['last_refill'] = $now;

        if ($bucket['tokens'] < 1) {
            Cache::put($key, $bucket, $decayMinutes * 60);
            return true;
        }

        $bucket['tokens'] -= 1;
        Cache::put($key, $bucket, $decayMinutes * 60);
        return false;
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
     * 根据系统压力调整限流参数
     */
    protected function adjustRateLimit($maxAttempts, $systemPressure)
    {
        $adjustmentFactors = config('resilience.rate_limiting.pressure_adjustment', [
            'low' => 1.0,
            'medium' => 0.7,
            'high' => 0.5,
            'critical' => 0.3
        ]);

        $factor = isset($adjustmentFactors[$systemPressure]) ? $adjustmentFactors[$systemPressure] : 1.0;
        return max(1, (int)($maxAttempts * $factor));
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
    protected function buildRateLimitResponse($maxAttempts, $decayMinutes)
    {
        return response()->json([
            'message' => 'Too many requests',
            'error' => 'rate_limit_exceeded',
            'retry_after' => $decayMinutes * 60
        ], 429, [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => time() + ($decayMinutes * 60)
        ]);
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
