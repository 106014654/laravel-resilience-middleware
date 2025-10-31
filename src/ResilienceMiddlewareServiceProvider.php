<?php

namespace OneLap\LaravelResilienceMiddleware;

use Illuminate\Support\ServiceProvider;
use OneLap\LaravelResilienceMiddleware\Services\SystemMonitorService;
use OneLap\LaravelResilienceMiddleware\Middleware\RateLimitingMiddleware;
use OneLap\LaravelResilienceMiddleware\Middleware\CircuitBreakerMiddleware;
use OneLap\LaravelResilienceMiddleware\Middleware\ServiceDegradationMiddleware;

class ResilienceMiddlewareServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        // 发布配置文件
        $this->publishes([
            __DIR__ . '/../config/resilience.php' => config_path('resilience.php'),
        ], 'resilience-config');

        // 发布中间件到应用
        $this->publishes([
            __DIR__ . '/Middleware' => app_path('Http/Middleware/Resilience'),
        ], 'resilience-middleware');

        // 发布服务到应用
        $this->publishes([
            __DIR__ . '/Services' => app_path('Services/Resilience'),
        ], 'resilience-services');

        // 如果是 Laravel 5.4+，自动注册中间件
        if (method_exists($this->app['router'], 'aliasMiddleware')) {
            $this->registerMiddleware();
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        // 合并配置文件
        $this->mergeConfigFrom(
            __DIR__ . '/../config/resilience.php',
            'resilience'
        );

        // 注册系统监控服务
        $this->app->singleton(SystemMonitorService::class, function ($app) {
            return new SystemMonitorService();
        });

        // 注册 Facade
        $this->app->singleton('system-monitor', function ($app) {
            return $app->make(SystemMonitorService::class);
        });
    }

    /**
     * 自动注册中间件
     */
    protected function registerMiddleware()
    {
        $router = $this->app['router'];

        // 注册单个中间件
        $router->aliasMiddleware('resilience.rate-limit', RateLimitingMiddleware::class);
        $router->aliasMiddleware('resilience.circuit-breaker', CircuitBreakerMiddleware::class);
        $router->aliasMiddleware('resilience.service-degradation', ServiceDegradationMiddleware::class);

        // 注册中间件组合
        $router->middlewareGroup('resilience.light', [
            RateLimitingMiddleware::class
        ]);

        $router->middlewareGroup('resilience.medium', [
            RateLimitingMiddleware::class,
            ServiceDegradationMiddleware::class
        ]);

        $router->middlewareGroup('resilience.full', [
            RateLimitingMiddleware::class,
            CircuitBreakerMiddleware::class,
            ServiceDegradationMiddleware::class
        ]);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            SystemMonitorService::class,
            'system-monitor'
        ];
    }
}
