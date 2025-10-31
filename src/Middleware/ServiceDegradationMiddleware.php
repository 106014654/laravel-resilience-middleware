<?php

namespace OneLap\LaravelResilienceMiddleware\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OneLap\LaravelResilienceMiddleware\Services\SystemMonitorService;

/**
 * 服务降级中间件
 * 根据系统压力自动降级服务
 */
class ServiceDegradationMiddleware
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
     * @param  string  $degradationLevel 降级级别 (low|medium|high)
     * @param  string  $mode 处理模式 (block|passthrough)
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $degradationLevel = 'medium', $mode = null)
    {
        // 获取系统压力级别
        $systemPressure = $this->systemMonitor->getSystemPressureLevel();

        // 获取降级配置
        $config = $this->getDegradationConfig($request, $degradationLevel);

        // 如果没有提供模式参数，从配置中获取
        if ($mode === null) {
            $mode = isset($config['mode']) ? $config['mode'] : 'block';
        }

        // 检查是否需要降级
        $shouldDegrade = $this->shouldDegrade($systemPressure, $config);

        if ($shouldDegrade) {
            Log::info('Service degradation activated', [
                'route' => $request->route() ? $request->route()->getName() : null,
                'system_pressure' => $systemPressure,
                'mode' => $mode,
                'degradation_level' => $degradationLevel,
                'ip' => $request->ip()
            ]);

            // 根据模式处理降级
            if ($mode === 'block') {
                // 阻塞模式：直接返回降级响应，不执行后续逻辑
                return $this->handleBlockingDegradation($request, $systemPressure, $config);
            } elseif ($mode === 'passthrough') {
                // 透传模式：设置降级标识，继续执行后续逻辑
                $this->setDegradationContext($request, $systemPressure, $config, $degradationLevel);
            }
        }

        return $next($request);
    }

    /**
     * 获取降级配置
     *
     * @param Request $request
     * @param string $degradationLevel
     * @return array
     */
    protected function getDegradationConfig(Request $request, $degradationLevel)
    {
        $routeName = $request->route() ? $request->route()->getName() : null;
        $routeConfig = config('resilience.service_degradation.routes', []);

        // 精确匹配路由配置
        if ($routeName && isset($routeConfig[$routeName])) {
            return array_merge($this->getDefaultConfig($degradationLevel), $routeConfig[$routeName]);
        }

        // 模式匹配
        if ($routeName) {
            foreach ($routeConfig as $pattern => $config) {
                if (fnmatch($pattern, $routeName)) {
                    return array_merge($this->getDefaultConfig($degradationLevel), $config);
                }
            }
        }

        return $this->getDefaultConfig($degradationLevel);
    }

    /**
     * 获取默认配置
     *
     * @param string $degradationLevel
     * @return array
     */
    protected function getDefaultConfig($degradationLevel)
    {
        $baseConfig = config('resilience.service_degradation.default', []);
        $levelConfig = config("resilience.service_degradation.levels.{$degradationLevel}", []);

        return array_merge([
            'trigger_pressure' => 'medium',
            'strategy' => 'cache_fallback',
            'cache_ttl' => 300,
            'simplified_response' => false,
            'skip_heavy_operations' => true,
            'reduce_data_quality' => false,
        ], $baseConfig, $levelConfig);
    }

    /**
     * 检查是否需要降级
     *
     * @param string $systemPressure
     * @param array $config
     * @return bool
     */
    protected function shouldDegrade($systemPressure, array $config)
    {
        $triggerPressure = $config['trigger_pressure'];

        $pressureOrder = ['low', 'medium', 'high', 'critical'];
        $currentIndex = array_search($systemPressure, $pressureOrder);
        $triggerIndex = array_search($triggerPressure, $pressureOrder);

        return $currentIndex !== false && $triggerIndex !== false && $currentIndex >= $triggerIndex;
    }

    /**
     * 处理阻塞模式的服务降级
     *
     * @param Request $request
     * @param string $systemPressure
     * @param array $config
     * @return mixed
     */
    protected function handleBlockingDegradation(Request $request, $systemPressure, array $config)
    {
        $strategy = $config['strategy'];

        switch ($strategy) {
            case 'cache_fallback':
                return $this->handleCacheFallback($request, $config);

            case 'simplified_response':
                return $this->handleSimplifiedResponse($request, $config);

            case 'error_response':
                return $this->handleErrorResponse($request, $config);

            case 'redirect':
                return $this->handleRedirect($request, $config);

            case 'queue_delay':
                return $this->handleQueueDelay($request, $config);

            default:
                return $this->handleDefaultDegradation($request, $config);
        }
    }

    /**
     * 设置降级上下文信息 - 透传模式使用
     *
     * @param Request $request
     * @param string $systemPressure
     * @param array $config
     * @param string $degradationLevel
     */
    protected function setDegradationContext(Request $request, $systemPressure, array $config, $degradationLevel)
    {
        // 在请求中设置降级标识
        $request->attributes->set('degraded', true);
        $request->attributes->set('degradation_level', $systemPressure);
        $request->attributes->set('degradation_config', $config);
        $request->attributes->set('degradation_strategy', isset($config['strategy']) ? $config['strategy'] : 'simplified_response');
        $request->attributes->set('degradation_trigger_level', $degradationLevel);

        // 设置 HTTP 头部标识
        $request->headers->set('X-Degraded', 'true');
        $request->headers->set('X-Degradation-Level', $systemPressure);
        $request->headers->set('X-Degradation-Strategy', isset($config['strategy']) ? $config['strategy'] : 'simplified_response');
        $request->headers->set('X-Degradation-Mode', 'passthrough');

        Log::info('Service degradation context set (passthrough mode)', [
            'route' => $request->route() ? $request->route()->getName() : null,
            'system_pressure' => $systemPressure,
            'degradation_level' => $degradationLevel,
            'strategy' => isset($config['strategy']) ? $config['strategy'] : 'simplified_response',
            'ip' => $request->ip()
        ]);
    }

    /**
     * 缓存降级策略
     *
     * @param Request $request
     * @param array $config
     * @return mixed
     */
    protected function handleCacheFallback(Request $request, array $config)
    {
        $cacheKey = $this->generateCacheKey($request);
        $cachedResponse = cache($cacheKey);

        if ($cachedResponse) {
            Log::info('Serving cached response due to degradation', [
                'cache_key' => $cacheKey,
                'route' => $request->route() ? $request->route()->getName() : null
            ]);

            if ($request->expectsJson()) {
                return response()->json($cachedResponse, 200, ['X-Cache' => 'HIT-DEGRADED']);
            }

            return response($cachedResponse, 200, ['X-Cache' => 'HIT-DEGRADED']);
        }

        // 如果没有缓存，返回简化响应
        return $this->handleSimplifiedResponse($request, $config);
    }

    /**
     * 简化响应策略
     *
     * @param Request $request
     * @param array $config
     * @return mixed
     */
    protected function handleSimplifiedResponse(Request $request, array $config)
    {
        if ($request->expectsJson()) {
            $response = [
                'message' => 'Service is running in degraded mode',
                'data' => $this->getSimplifiedData($request, $config),
                'degraded' => true
            ];

            return response()->json($response, 200, ['X-Degraded' => 'true']);
        }

        // 对于非 JSON 请求，返回简化的 HTML 或文本
        return response('Service temporarily simplified due to high load', 200, [
            'X-Degraded' => 'true'
        ]);
    }

    /**
     * 错误响应策略
     *
     * @param Request $request
     * @param array $config
     * @return mixed
     */
    protected function handleErrorResponse(Request $request, array $config)
    {
        $statusCode = isset($config['error_status_code']) ? $config['error_status_code'] : 503;
        $message = isset($config['error_message']) ? $config['error_message'] : 'Service temporarily unavailable';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'error' => 'service_degraded',
                'retry_after' => 60
            ], $statusCode);
        }

        return response($message, $statusCode);
    }

    /**
     * 重定向策略
     *
     * @param Request $request
     * @param array $config
     * @return mixed
     */
    protected function handleRedirect(Request $request, array $config)
    {
        $redirectUrl = isset($config['redirect_url']) ? $config['redirect_url'] : '/maintenance';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Service degraded, please try alternative endpoint',
                'redirect_url' => $redirectUrl
            ], 302);
        }

        return redirect($redirectUrl);
    }

    /**
     * 队列延迟策略
     *
     * @param Request $request
     * @param array $config
     * @return mixed
     */
    protected function handleQueueDelay(Request $request, array $config)
    {
        $delay = isset($config['queue_delay']) ? $config['queue_delay'] : 5;

        // 模拟处理延迟
        sleep($delay);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Request processed with delay due to high load',
                'delayed' => true,
                'delay_seconds' => $delay
            ], 200, ['X-Delayed' => $delay]);
        }

        return response('Request processed', 200, [
            'X-Delayed' => $delay
        ]);
    }

    /**
     * 默认降级策略
     *
     * @param Request $request
     * @param array $config
     * @return mixed
     */
    protected function handleDefaultDegradation(Request $request, array $config)
    {
        // 默认使用简化响应
        return $this->handleSimplifiedResponse($request, $config);
    }

    /**
     * 生成缓存键
     *
     * @param Request $request
     * @return string
     */
    protected function generateCacheKey(Request $request)
    {
        $route = $request->route();
        $routeName = $route ? $route->getName() : $request->getPathInfo();
        $queryParams = $request->query();

        // 只包含关键的查询参数
        $keyParams = [];
        $importantParams = ['id', 'page', 'limit', 'type'];

        foreach ($importantParams as $param) {
            if (isset($queryParams[$param])) {
                $keyParams[$param] = $queryParams[$param];
            }
        }

        return 'degradation:' . md5($routeName . serialize($keyParams));
    }

    /**
     * 获取简化的数据
     *
     * @param Request $request
     * @param array $config
     * @return array
     */
    protected function getSimplifiedData(Request $request, array $config)
    {
        // 根据路由返回不同的简化数据
        $route = $request->route();
        $routeName = $route ? $route->getName() : null;

        // 可以根据具体路由定制简化数据
        switch ($routeName) {
            case 'api.user.profile':
                return ['message' => 'Profile data simplified'];

            case 'api.dashboard':
                return ['stats' => ['message' => 'Statistics temporarily unavailable']];

            default:
                return [
                    'message' => 'Data temporarily simplified',
                    'available' => false
                ];
        }
    }

    /**
     * 获取降级统计信息
     *
     * @return array
     */
    public function getDegradationStats()
    {
        $systemHealth = $this->systemMonitor->getSystemHealth();

        return [
            'system_pressure' => $this->systemMonitor->getSystemPressureLevel(),
            'cpu_usage' => $systemHealth['cpu_usage'],
            'memory_usage' => $systemHealth['memory_usage'],
            'degradation_active' => $this->systemMonitor->getSystemPressureLevel() !== 'low',
            'timestamp' => time()
        ];
    }
}
