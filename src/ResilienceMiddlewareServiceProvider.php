<?php

namespace OneLap\LaravelResilienceMiddleware;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use OneLap\LaravelResilienceMiddleware\Services\SystemMonitorService;
use OneLap\LaravelResilienceMiddleware\Middleware\RateLimitingMiddleware;
use OneLap\LaravelResilienceMiddleware\Middleware\CircuitBreakerMiddleware;
use OneLap\LaravelResilienceMiddleware\Middleware\ServiceDegradationMiddleware;
use OneLap\LaravelResilienceMiddleware\Console\ResilienceConfigStatusCommand;

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

        // 验证配置文件加载是否正确
        $this->validateConfigurationLoading();

        // 注册Artisan命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                ResilienceConfigStatusCommand::class,
            ]);
        }

        // 如果是 Laravel 5.4+，自动注册中间件
        if (method_exists($this->app['router'], 'aliasMiddleware')) {
            $this->registerMiddleware();
        }
    }

    /**
     * 验证配置文件是否正确加载
     * 
     * 在开发环境下提供配置加载状态的提示信息
     */
    protected function validateConfigurationLoading()
    {
        if ($this->app->environment('local', 'development') && config('app.debug')) {
            $userConfigExists = file_exists(config_path('resilience.php'));
            $currentConfig = config('resilience');

            // 记录配置加载状态
            Log::info('Laravel Resilience Middleware Configuration Status', [
                'user_config_published' => $userConfigExists,
                'config_loaded' => !empty($currentConfig),
                'config_source' => $userConfigExists ? 'user_published' : 'package_default',
                'config_keys' => array_keys($currentConfig ?? [])
            ]);
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        // 智能配置加载：优先使用用户发布的配置，否则使用包默认配置
        $this->registerConfiguration();

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
     * 注册配置文件
     * 
     * 优先级：用户发布的配置 > 包默认配置
     * 确保用户的配置能正确覆盖vendor包中的配置
     */
    protected function registerConfiguration()
    {
        $userConfigPath = config_path('resilience.php');
        $packageConfigPath = __DIR__ . '/../config/resilience.php';

        // 如果用户已发布配置文件，Laravel会自动加载用户配置
        // 我们只需要将包的默认配置作为fallback，确保缺失的配置项有默认值
        if (file_exists($userConfigPath)) {
            // 用户已发布配置文件，仅合并默认值作为fallback
            $this->mergeConfigFrom($packageConfigPath, 'resilience');
        } else {
            // 用户未发布配置文件，使用包默认配置
            $this->mergeConfigFrom($packageConfigPath, 'resilience');
        }
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
