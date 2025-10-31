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
     * @param  string  $service 服务名称
     * @param  int  $failureThreshold 失败阈值
     * @param  int  $recoveryTimeout 恢复超时时间（秒）
     * @param  int  $successThreshold 半开状态成功阈值
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $service = 'default', $failureThreshold = 5, $recoveryTimeout = 60, $successThreshold = 3)
    {
        $circuitKey = $this->getCircuitKey($service, $request);
        $state = $this->getCircuitState($circuitKey);

        // 检查熔断器状态
        if ($state === self::STATE_OPEN) {
            if ($this->shouldAttemptReset($circuitKey, $recoveryTimeout)) {
                $this->setCircuitState($circuitKey, self::STATE_HALF_OPEN);
            } else {
                return $this->buildCircuitOpenResponse($service);
            }
        }

        $startTime = microtime(true);

        try {
            $response = $next($request);
            $responseTime = (microtime(true) - $startTime) * 1000; // ms

            // 检查响应是否成功
            if ($this->isSuccessResponse($response, $responseTime)) {
                $this->recordSuccess($circuitKey, $state, $successThreshold);
            } else {
                $this->recordFailure($circuitKey, $state, $failureThreshold);
            }

            return $response;
        } catch (\Exception $e) {
            $this->recordFailure($circuitKey, $state, $failureThreshold);
            throw $e;
        }
    }

    /**
     * 获取熔断器键
     */
    protected function getCircuitKey($service, Request $request)
    {
        $route = $request->route() ? $request->route()->getName() : $request->getPathInfo();
        return "circuit_breaker:{$service}:" . md5($route);
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
     */
    protected function setCircuitState($circuitKey, $state)
    {
        $circuit = $this->getCircuitData($circuitKey);
        $circuit['state'] = $state;
        $circuit['state_changed_at'] = time();

        if ($state === self::STATE_OPEN) {
            $circuit['opened_at'] = time();
        } elseif ($state === self::STATE_CLOSED) {
            $circuit['closed_at'] = time();
            $circuit['failure_count'] = 0;
            $circuit['success_count'] = 0;
        } elseif ($state === self::STATE_HALF_OPEN) {
            $circuit['half_opened_at'] = time();
            $circuit['success_count'] = 0;
        }

        $this->saveCircuitData($circuitKey, $circuit);
    }

    /**
     * 获取熔断器数据
     */
    protected function getCircuitData($circuitKey)
    {
        try {
            $data = Cache::store('redis')->get($circuitKey);
            return $data ? json_decode($data, true) : $this->getDefaultCircuitData();
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
            Cache::store('redis')->put($circuitKey, json_encode($data), 3600);
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
            'failure_count' => 0,
            'success_count' => 0,
            'created_at' => time(),
            'state_changed_at' => time()
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
     */
    protected function recordSuccess($circuitKey, $currentState, $successThreshold)
    {
        $circuit = $this->getCircuitData($circuitKey);

        if ($currentState === self::STATE_HALF_OPEN) {
            $circuit['success_count'] = ($circuit['success_count'] ?? 0) + 1;

            if ($circuit['success_count'] >= $successThreshold) {
                $this->setCircuitState($circuitKey, self::STATE_CLOSED);
                return;
            }
        } elseif ($currentState === self::STATE_CLOSED) {
            // 重置失败计数
            $circuit['failure_count'] = 0;
        }

        $this->saveCircuitData($circuitKey, $circuit);
    }

    /**
     * 记录失败
     */
    protected function recordFailure($circuitKey, $currentState, $failureThreshold)
    {
        $circuit = $this->getCircuitData($circuitKey);
        $circuit['failure_count'] = ($circuit['failure_count'] ?? 0) + 1;
        $circuit['last_failure_at'] = time();

        if (
            $circuit['failure_count'] >= $failureThreshold &&
            ($currentState === self::STATE_CLOSED || $currentState === self::STATE_HALF_OPEN)
        ) {
            $this->setCircuitState($circuitKey, self::STATE_OPEN);
        } else {
            $this->saveCircuitData($circuitKey, $circuit);
        }
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

        // 系统压力检查
        $systemPressure = $this->systemMonitor->getSystemPressureLevel();
        if ($systemPressure === 'critical') {
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

    /**
     * 获取熔断器统计信息
     */
    public function getCircuitStats($service = null)
    {
        $pattern = $service ? "circuit_breaker:{$service}:*" : "circuit_breaker:*";
        $stats = [];

        try {
            $redis = Redis::connection();
            $keys = $redis->keys($pattern);

            foreach ($keys as $key) {
                $data = $redis->get($key);
                if ($data) {
                    $circuit = json_decode($data, true);
                    $keyParts = explode(':', $key);
                    $serviceName = $keyParts[1] ?? 'unknown';

                    if (!isset($stats[$serviceName])) {
                        $stats[$serviceName] = [
                            'total_circuits' => 0,
                            'open_circuits' => 0,
                            'half_open_circuits' => 0,
                            'closed_circuits' => 0,
                            'total_failures' => 0
                        ];
                    }

                    $stats[$serviceName]['total_circuits']++;
                    $stats[$serviceName]['total_failures'] += $circuit['failure_count'] ?? 0;

                    switch ($circuit['state']) {
                        case self::STATE_OPEN:
                            $stats[$serviceName]['open_circuits']++;
                            break;
                        case self::STATE_HALF_OPEN:
                            $stats[$serviceName]['half_open_circuits']++;
                            break;
                        case self::STATE_CLOSED:
                            $stats[$serviceName]['closed_circuits']++;
                            break;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('获取熔断器统计信息失败', ['error' => $e->getMessage()]);
        }

        return $stats;
    }
}
