<?php

namespace OneLap\LaravelResilienceMiddleware\Console;

use Illuminate\Console\Command;

/**
 * Laravel Resilience Middleware 配置状态检查命令
 * 
 * 帮助用户检查配置文件的加载状态和配置项的有效性
 */
class ResilienceConfigStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resilience:config-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Laravel Resilience Middleware configuration status and validate settings';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Laravel Resilience Middleware Configuration Status');
        $this->line('================================================');

        // 检查配置文件发布状态
        $this->checkConfigurationFiles();

        // 检查配置加载状态
        $this->checkConfigurationLoading();

        // 检查环境变量配置
        $this->checkEnvironmentVariables();

        // 检查关键配置项
        $this->checkCriticalSettings();

        // 提供解决建议
        $this->provideSuggestions();

        return 0;
    }

    /**
     * 检查配置文件发布状态
     */
    protected function checkConfigurationFiles()
    {
        $this->line('');
        $this->info('1. Configuration Files Status:');

        $userConfigPath = config_path('resilience.php');
        $packageConfigPath = __DIR__ . '/../../config/resilience.php';

        // 检查用户配置文件
        if (file_exists($userConfigPath)) {
            $this->line('   ✓ User config file published: ' . $userConfigPath);
            $userModified = filemtime($userConfigPath);
            $this->line('   ✓ Last modified: ' . date('Y-m-d H:i:s', $userModified));
        } else {
            $this->warn('   ✗ User config file not published');
            $this->line('   → Run: php artisan vendor:publish --tag=resilience-config');
        }

        // 检查包配置文件
        if (file_exists($packageConfigPath)) {
            $this->line('   ✓ Package config file exists: ' . $packageConfigPath);
        } else {
            $this->error('   ✗ Package config file missing: ' . $packageConfigPath);
        }
    }

    /**
     * 检查配置加载状态
     */
    protected function checkConfigurationLoading()
    {
        $this->line('');
        $this->info('2. Configuration Loading Status:');

        $config = config('resilience');

        if (empty($config)) {
            $this->error('   ✗ No resilience configuration loaded');
            return;
        }

        $this->line('   ✓ Configuration loaded successfully');

        // 检查主要配置块
        $mainSections = ['system_monitor', 'rate_limiting', 'circuit_breaker', 'service_degradation'];
        foreach ($mainSections as $section) {
            if (isset($config[$section])) {
                $this->line("   ✓ Section '{$section}' loaded");
            } else {
                $this->warn("   ✗ Section '{$section}' missing");
            }
        }

        // 显示配置来源
        $userConfigExists = file_exists(config_path('resilience.php'));
        $configSource = $userConfigExists ? 'User Published Config' : 'Package Default Config';
        $this->line("   ✓ Config source: {$configSource}");
    }

    /**
     * 检查环境变量配置
     */
    protected function checkEnvironmentVariables()
    {
        $this->line('');
        $this->info('3. Environment Variables Status:');

        $envVars = [
            'RESILIENCE_RATE_LIMITING_ENABLED' => 'Rate limiting enabled',
            'RESILIENCE_RL_DETAILED_LOG' => 'Rate limiting detailed logging',
            'RESILIENCE_CB_WINDOW_SIZE' => 'Circuit breaker window size',
            'RESILIENCE_CB_FAILURE_THRESHOLD' => 'Circuit breaker failure threshold',
            'RESILIENCE_DEGRADATION_ENABLED' => 'Service degradation enabled',
        ];

        $setCount = 0;
        foreach ($envVars as $key => $description) {
            $value = env($key);
            if ($value !== null) {
                $this->line("   ✓ {$key} = {$value} ({$description})");
                $setCount++;
            }
        }

        if ($setCount === 0) {
            $this->warn('   ✗ No resilience environment variables set');
            $this->line('   → Consider setting environment variables for better flexibility');
        } else {
            $this->line("   ✓ {$setCount} environment variables configured");
        }
    }

    /**
     * 检查关键配置项
     */
    protected function checkCriticalSettings()
    {
        $this->line('');
        $this->info('4. Critical Settings Validation:');

        // 检查限流配置
        $rateLimitEnabled = config('resilience.rate_limiting.enabled', false);
        $this->line('   ✓ Rate Limiting: ' . ($rateLimitEnabled ? 'Enabled' : 'Disabled'));

        // 检查熔断器配置
        $cbWindowSize = config('resilience.circuit_breaker.sliding_window.window_size', 0);
        $cbFailureThreshold = config('resilience.circuit_breaker.sliding_window.failure_threshold', 0);
        $this->line("   ✓ Circuit Breaker Window: {$cbWindowSize}s, Failure Threshold: {$cbFailureThreshold}%");

        // 检查服务降级配置
        $degradationEnabled = config('resilience.service_degradation.enabled', false);
        $this->line('   ✓ Service Degradation: ' . ($degradationEnabled ? 'Enabled' : 'Disabled'));

        // 检查Redis配置
        $redisConnection = config('resilience.system_monitor.redis.connection', 'default');
        $this->line("   ✓ Redis Connection: {$redisConnection}");

        // 检查监控配置
        $detailedLogging = config('resilience.rate_limiting.monitoring.enable_detailed_logging', false);
        $this->line('   ✓ Detailed Logging: ' . ($detailedLogging ? 'Enabled' : 'Disabled'));
    }

    /**
     * 提供解决建议
     */
    protected function provideSuggestions()
    {
        $this->line('');
        $this->info('5. Suggestions:');

        $userConfigExists = file_exists(config_path('resilience.php'));

        if (!$userConfigExists) {
            $this->line('   → Publish config file: php artisan vendor:publish --tag=resilience-config');
        }

        $config = config('resilience');
        if (empty($config)) {
            $this->line('   → Clear config cache: php artisan config:clear');
            $this->line('   → Restart server/workers after config changes');
        }

        $detailedLogging = config('resilience.rate_limiting.monitoring.enable_detailed_logging', false);
        if (!$detailedLogging && app()->environment('local', 'development')) {
            $this->line('   → Enable detailed logging in development: RESILIENCE_RL_DETAILED_LOG=true');
        }

        $this->line('');
        $this->info('For more detailed setup guide, see: CONFIG_SETUP_GUIDE.md');
    }
}
