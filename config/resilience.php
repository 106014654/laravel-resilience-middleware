<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel Resilience Middleware Configuration
    |--------------------------------------------------------------------------
    |
    | 这里配置韧性中间件的各项参数，包括系统监控、限流、熔断和降级策略
    | 采用独立资源监控模式，每个资源根据自身状态独立执行相应的韧性策略
    |
    */

    /*
    |--------------------------------------------------------------------------
    | 系统监控配置
    |--------------------------------------------------------------------------
    | 监控本地和远程服务的资源使用情况
    */

    'system_monitor' => [
        // 连接超时配置
        'redis_connection_timeout' => env('RESILIENCE_REDIS_CONNECTION_TIMEOUT', 2),
        'mysql_connection_timeout' => env('RESILIENCE_MYSQL_CONNECTION_TIMEOUT', 3),
        /*
        /*
        | Redis 监控配置（可能为远程服务器）
        */
        'redis' => [
            'connection' => env('RESILIENCE_REDIS_CONNECTION', 'default'), // Redis连接名称
        ],

        /*
        | 数据库监控配置（可能为远程服务器）
        */
        'database' => [
            'connection' => env('RESILIENCE_DB_CONNECTION', 'mysql'), // 数据库连接名称
        ],
        /*
        | 监控和日志配置
        */
        'monitoring' => [
            'enable_detailed_logging' => env('RESILIENCE_RL_DETAILED_LOG', true), // 是否启用详细日志
            'log_rate_limit_hits' => env('RESILIENCE_RL_LOG_HITS', true),         // 是否记录限流命中事件
            'log_allowed_requests' => env('RESILIENCE_RL_LOG_ALLOWED', false),     // 是否记录允许通过的请求
            'metrics_collection' => env('RESILIENCE_RL_METRICS', true),           // 是否收集限流指标
        ],


    ],

    /*
    |--------------------------------------------------------------------------
    | 限流中间件配置
    |--------------------------------------------------------------------------
    | 基于系统资源使用情况的自适应限流策略
    */
    'rate_limiting' => [
        /*
        | 基础配置
        */
        'enabled' => env('RESILIENCE_RATE_LIMITING_ENABLED', true),


        // 单项资源阈值限流策略（独立资源监控模式）
        'resource_thresholds' => [
            'cpu' => [
                70 => 0.9,    // CPU 70%时，限流到90%
                80 => 0.7,    // CPU 80%时，限流到70%
                90 => 0.4,    // CPU 90%时，限流到40%
                95 => 0.2,    // CPU 95%时，限流到20%
            ],
            'memory' => [
                70 => 0.9,    // 内存 70%时，限流到90%
                80 => 0.7,    // 内存 80%时，限流到70%
                90 => 0.4,    // 内存 90%时，限流到40%
                95 => 0.2,    // 内存 95%时，限流到20%
            ],
            'redis' => [
                70 => 0.8,    // Redis更敏感，70%时就限流到80%
                80 => 0.6,    // Redis 80%时，限流到60%
                90 => 0.3,    // Redis 90%时，限流到30%
                95 => 0.1,    // Redis 95%时，限流到10%
            ],
            'mysql' => [
                70 => 0.8,    // 数据库也更敏感，70%时限流到80%
                80 => 0.6,    // MySQL 80%时，限流到60%
                90 => 0.3,    // MySQL 90%时，限流到30%
                95 => 0.1,    // MySQL 95%时，限流到10%
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 熔断器中间件配置
    |--------------------------------------------------------------------------
    | 基于滑动窗口的智能熔断器，提供更精确的失败率统计和熔断控制
    | 
    | 工作原理：
    | 1. 使用滑动时间窗口记录所有请求（成功/失败）
    | 2. 实时计算窗口内的失败率
    | 3. 当失败率超过阈值且请求数满足最小要求时触发熔断
    | 4. 熔断后进入恢复周期，逐步恢复服务
    */
    'circuit_breaker' => [
        /*
        | 响应时间阈值配置
        */
        'max_response_time' => env('RESILIENCE_CB_MAX_RESPONSE_TIME', 5000), // 最大响应时间（毫秒），超过视为失败
        /*
        | 熔断器状态配置
        */
        'recovery_timeout' => env('RESILIENCE_CB_RECOVERY_TIMEOUT', 60),     // 熔断后的恢复等待时间（秒）
        'success_threshold' => env('RESILIENCE_CB_SUCCESS_THRESHOLD', 3),    // 半开状态下的成功阈值，连续成功此数量后关闭熔断器
        'window_size' => env('RESILIENCE_CB_WINDOW_SIZE', 60),           // 滑动窗口大小（秒）
        'min_request_count' => env('RESILIENCE_CB_MIN_REQUESTS', 10),    // 最小请求数，低于此数不触发熔断
        'failure_threshold' => env('RESILIENCE_CB_FAILURE_THRESHOLD', 50), // 失败率阈值（百分比），如50表示50%

    ],

    /*
    |--------------------------------------------------------------------------
    | 服务降级中间件配置
    |--------------------------------------------------------------------------
    | 当系统资源压力过大时，自动降级服务功能以保护核心业务
    | 采用分级降级策略：轻度→中度→重度，逐步减少系统负载
    */
    'service_degradation' => [
        // 基础配置
        'enabled' => env('RESILIENCE_DEGRADATION_ENABLED', true),

        /*
        | 监控和日志配置
        */
        'monitoring' => [
            'enable_detailed_logging' => env('RESILIENCE_SD_DETAILED_LOG', false), // 是否启用详细日志
            'log_degradation_events' => env('RESILIENCE_SD_LOG_EVENTS', true),     // 是否记录降级事件
            'log_recovery_events' => env('RESILIENCE_SD_LOG_RECOVERY', true),      // 是否记录恢复事件  
            'log_strategy_execution' => env('RESILIENCE_SD_LOG_STRATEGY', false),  // 是否记录策略执行详情
            'log_resource_monitoring' => env('RESILIENCE_SD_LOG_RESOURCE', false), // 是否记录资源监控数据
            'metrics_collection' => env('RESILIENCE_SD_METRICS', true),           // 是否收集降级指标
        ],

        /*
        |--------------------------------------------------------------------------
        | 缓存标签配置
        |--------------------------------------------------------------------------
        | 配置缓存标签清理的兼容性选项
        */
        'cache' => [
            // 是否允许在标签清理失败时回退到全局清理（谨慎使用）
            'fallback_to_global_flush' => env('RESILIENCE_CACHE_FALLBACK_GLOBAL', false),

            // 针对不同缓存驱动的标签支持检测
            'tag_support_drivers' => ['redis', 'memcached', 'array'],

            // 在不支持标签的驱动上，是否尝试按键名模式清理
            'enable_pattern_cleanup' => env('RESILIENCE_CACHE_PATTERN_CLEANUP', true),
        ],

        /*
        |--------------------------------------------------------------------------
        | 降级级别定义
        |--------------------------------------------------------------------------
        | 1级：轻度降级 - 关闭非核心功能，使用缓存数据
        | 2级：中度降级 - 关闭大部分增值功能，简化响应数据 
        | 3级：重度降级 - 仅保留核心功能，返回静态响应
        | 4级：紧急降级 - 系统保护模式，拒绝非关键请求
        */
        'levels' => [
            1 => [
                'name' => 'light_degradation',
                'description' => '轻度降级：关闭非核心功能，优先使用缓存',
                'response_template' => [
                    'success' => true,
                    'message' => '服务运行正常，部分功能临时优化中',
                ],
                'http_status' => 200,
                'cache_headers' => ['Cache-Control' => 'public, max-age=300'],
            ],

            2 => [
                'name' => 'moderate_degradation',
                'description' => '中度降级：大幅简化功能，返回基础数据',
                'response_template' => [
                    'success' => true,
                    'message' => '服务负载较高，已切换到简化模式',
                ],
                'http_status' => 200,
                'cache_headers' => ['Cache-Control' => 'public, max-age=600'],
            ],

            3 => [
                'name' => 'heavy_degradation',
                'description' => '重度降级：仅保留核心功能，静态响应',
                'response_template' => [
                    'success' => false,
                    'message' => '系统繁忙，请稍后重试',
                ],
                'http_status' => 503,
                'cache_headers' => ['Cache-Control' => 'no-cache', 'Retry-After' => '300'],
            ],

            4 => [
                'name' => 'emergency_degradation',
                'description' => '紧急降级：系统保护模式，拒绝非关键请求',
                'response_template' => [
                    'success' => false,
                    'message' => '系统维护中，请稍后访问',
                ],
                'http_status' => 503,
                'cache_headers' => ['Cache-Control' => 'no-cache', 'Retry-After' => '600'],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | 资源阈值与降级级别映射
        |--------------------------------------------------------------------------
        | 定义各项资源使用率对应的降级级别
        | 数值表示资源使用率百分比，触发相应的降级级别
        */
        'resource_thresholds' => [
            'cpu' => [
                70 => 1,  // CPU 70% 触发1级降级
                80 => 2,  // CPU 80% 触发2级降级  
                90 => 3,  // CPU 90% 触发3级降级
                95 => 4,  // CPU 95% 触发4级降级（紧急）
            ],
            'memory' => [
                75 => 1,  // 内存 75% 触发1级降级（内存更敏感）
                85 => 2,  // 内存 85% 触发2级降级
                92 => 3,  // 内存 92% 触发3级降级
                96 => 4,  // 内存 96% 触发4级降级（紧急）
            ],
            'redis' => [
                70 => 1,  // Redis 70% 触发1级降级
                80 => 2,  // Redis 80% 触发2级降级
                90 => 3,  // Redis 90% 触发3级降级
                95 => 4,  // Redis 95% 触发4级降级
            ],
            'mysql' => [
                70 => 1,  // MySQL 70% 触发1级降级
                80 => 2,  // MySQL 80% 触发2级降级  
                90 => 3,  // MySQL 90% 触发3级降级
                95 => 4,  // MySQL 95% 触发4级降级
            ],
        ],


        'strategies' => [
            // CPU 降级策略
            'cpu' => [
                70 => [
                    'level' => 1,
                    'actions' => [
                        'disable_heavy_analytics',    // 禁用重度分析功能，释放CPU资源
                        'reduce_log_verbosity',       // 降低日志详细程度，减少I/O操作
                        'reject_non_essential_requests',    // 拒绝非必要请求

                        'reduce_cache_size_20_percent',  // 随机清理指定百分比的临时缓存,优先清理 `temp`, `analytics`, `reports` 标签的缓存

                        'disable_file_processing', // 禁用文件处理功能

                        'reject_large_requests', // 拒绝大型请求

                        'reduce_redis_operations', // 减少redis 操作

                        'redis_read_only_mode', // redis只读模式

                        'complete_redis_bypass', // 完全弃用redis
                    ],
                    'performance_optimizations' => [],
                    'memory_management' => [
                        'cache_cleanup' => 'non_essential', // 清理非必要缓存 'temp', 'analytics' 标签

                    ],
                    'fallback_strategies' => [],
                    'database_strategies' => [
                        'query_strategy' => 'no_database_access', // 数据库查询不可用
                        'cache_strategy' => 'mandatory_caching', // 强制缓存所有查询
                    ]
                ],
            ],

            // Memory 内存降级策略
            'memory' => [],

            // Redis 降级策略
            'redis' => [],

            // Database 数据库降级策略  
            'mysql' => []
        ],

        /*
        |--------------------------------------------------------------------------
        | 恢复策略配置
        |--------------------------------------------------------------------------
        | 定义系统资源压力减轻后的恢复策略
        | 
        | gradual_recovery: 是否启用渐进式恢复，逐步从高级别降级恢复到正常状态
        | recovery_step_interval: 恢复步骤间隔时间，避免频繁切换
        | recovery_threshold_buffer: 恢复阈值缓冲区，防止在临界值附近频繁切换
        | max_recovery_attempts: 最大恢复尝试次数，防止无限重试
        */
        'recovery' => [
            'gradual_recovery' => true,              // 启用渐进式恢复，避免瞬间切换造成系统震荡
            'recovery_step_interval' => 30,          // 恢复步骤间隔30秒，给系统稳定时间
            'recovery_threshold_buffer' => 5,        // 恢复阈值缓冲5%，如70%降级需65%才恢复
            'max_recovery_attempts' => 3,            // 最大恢复尝试3次，防止异常情况下的无限重试
            'recovery_validation_time' => 120,       // 恢复验证时间120秒，确保系统稳定后才完全恢复
        ],

        /*
        |--------------------------------------------------------------------------
        | 非必要请求路径配置
        |--------------------------------------------------------------------------
        | 当系统压力过大时，这些路径的请求会被标记为非必要请求可能被拒绝
        */
        'non_essential_paths' => [],

    ],
];
