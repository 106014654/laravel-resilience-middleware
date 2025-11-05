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
        | CPU 监控配置（本地服务器）
        */
        'cpu' => [
            'enabled' => env('RESILIENCE_CPU_MONITOR', true),
            'thresholds' => [
                'medium' => env('RESILIENCE_CPU_MEDIUM', 70.0),    // CPU使用率70%触发中等压力
                'high' => env('RESILIENCE_CPU_HIGH', 85.0),        // CPU使用率85%触发高压力  
                'critical' => env('RESILIENCE_CPU_CRITICAL', 95.0), // CPU使用率95%触发临界压力
            ],
        ],

        /*
        | 内存监控配置（本地服务器）
        */
        'memory' => [
            'enabled' => env('RESILIENCE_MEMORY_MONITOR', true),
            'thresholds' => [
                'medium' => env('RESILIENCE_MEMORY_MEDIUM', 70.0),    // 内存使用率70%
                'high' => env('RESILIENCE_MEMORY_HIGH', 85.0),        // 内存使用率85%
                'critical' => env('RESILIENCE_MEMORY_CRITICAL', 95.0), // 内存使用率95%
            ],
        ],

        /*
        | Redis 监控配置（可能为远程服务器）
        */
        'redis' => [
            'enabled' => env('RESILIENCE_REDIS_MONITOR', true),
            'connection' => env('RESILIENCE_REDIS_CONNECTION', 'default'), // Redis连接名称
            'thresholds' => [
                'medium' => env('RESILIENCE_REDIS_MEDIUM', 70.0),    // Redis内存使用率70%
                'high' => env('RESILIENCE_REDIS_HIGH', 85.0),        // Redis内存使用率85%
                'critical' => env('RESILIENCE_REDIS_CRITICAL', 95.0), // Redis内存使用率95%
            ],
        ],

        /*
        | 数据库监控配置（可能为远程服务器）
        */
        'database' => [
            'enabled' => env('RESILIENCE_DB_MONITOR', true),
            'connection' => env('RESILIENCE_DB_CONNECTION', 'mysql'), // 数据库连接名称
            'thresholds' => [
                'medium' => env('RESILIENCE_DB_MEDIUM', 70.0),    // 数据库磁盘使用率70%
                'high' => env('RESILIENCE_DB_HIGH', 85.0),        // 数据库磁盘使用率85%
                'critical' => env('RESILIENCE_DB_CRITICAL', 95.0), // 数据库磁盘使用率95%
            ],
        ],

        // 系统压力等级权重配置（用于计算综合压力等级）
        'pressure_weights' => [
            'cpu' => 0.4,       // CPU权重40%
            'memory' => 0.3,     // 内存权重30%
            'redis' => 0.2,      // Redis权重20%
            'mysql' => 0.1,      // MySQL权重10%
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 限流中间件配置
    |--------------------------------------------------------------------------
    | 基于系统资源使用情况的自适应限流策略
    */
    'rate_limiting' => [

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
    */
    'circuit_breaker' => [
        'failure_threshold' => env('RESILIENCE_CB_FAILURE_THRESHOLD', 10),
        'max_response_time' => env('RESILIENCE_CB_MAX_RESPONSE_TIME', 5000), // ms
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
                    'database_strategies'=>[
                        'query_strategy'=> 'no_database_access' , // 数据库查询不可用
                        'cache_strategy'=> 'mandatory_caching' , // 强制缓存所有查询
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
        'non_essential_paths' => [
          
        ],

    ],
];
