<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel Resilience Middleware Configuration
    |--------------------------------------------------------------------------
    |
    | 这里配置韧性中间件的各项参数
    |
    */

    'system_monitor' => [
        /*
        |--------------------------------------------------------------------------
        | CPU 监控配置
        |--------------------------------------------------------------------------
        */
        'cpu' => [
            'enabled' => env('RESILIENCE_CPU_MONITOR', true),
            'thresholds' => [
                'medium' => env('RESILIENCE_CPU_MEDIUM', 70.0),
                'high' => env('RESILIENCE_CPU_HIGH', 85.0),
                'critical' => env('RESILIENCE_CPU_CRITICAL', 95.0),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | 内存监控配置
        |--------------------------------------------------------------------------
        */
        'memory' => [
            'enabled' => env('RESILIENCE_MEMORY_MONITOR', true),
            'thresholds' => [
                'medium' => env('RESILIENCE_MEMORY_MEDIUM', 70.0),
                'high' => env('RESILIENCE_MEMORY_HIGH', 85.0),
                'critical' => env('RESILIENCE_MEMORY_CRITICAL', 95.0),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Redis 监控配置
        |--------------------------------------------------------------------------
        */
        'redis' => [
            'enabled' => env('RESILIENCE_REDIS_MONITOR', true),
            'connection' => env('RESILIENCE_REDIS_CONNECTION', 'default'),
            'thresholds' => [
                'memory_medium' => env('RESILIENCE_REDIS_MEMORY_MEDIUM', 70.0),
                'memory_high' => env('RESILIENCE_REDIS_MEMORY_HIGH', 85.0),
                'memory_critical' => env('RESILIENCE_REDIS_MEMORY_CRITICAL', 95.0),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | 数据库监控配置
        |--------------------------------------------------------------------------
        */
        'database' => [
            'enabled' => env('RESILIENCE_DB_MONITOR', true),
            'connection' => env('RESILIENCE_DB_CONNECTION', 'mysql'),
            'thresholds' => [
                'connection_medium' => env('RESILIENCE_DB_CONN_MEDIUM', 70.0),
                'connection_high' => env('RESILIENCE_DB_CONN_HIGH', 85.0),
                'connection_critical' => env('RESILIENCE_DB_CONN_CRITICAL', 95.0),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 限流中间件配置
    |--------------------------------------------------------------------------
    */
    'rate_limiting' => [
        'default_strategy' => env('RESILIENCE_RATE_LIMIT_STRATEGY', 'sliding_window'),
        'default_max_attempts' => env('RESILIENCE_RATE_LIMIT_ATTEMPTS', 60),
        'default_decay_minutes' => env('RESILIENCE_RATE_LIMIT_DECAY', 1),

        /*
        | 系统压力下的限流调整系数
        */
        'pressure_adjustment' => [
            'low' => 1.0,
            'medium' => 0.7,
            'high' => 0.5,
            'critical' => 0.3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 熔断器中间件配置
    |--------------------------------------------------------------------------
    */
    'circuit_breaker' => [
        'default_failure_threshold' => env('RESILIENCE_CB_FAILURE_THRESHOLD', 5),
        'default_recovery_timeout' => env('RESILIENCE_CB_RECOVERY_TIMEOUT', 60),
        'default_success_threshold' => env('RESILIENCE_CB_SUCCESS_THRESHOLD', 3),
        'max_response_time' => env('RESILIENCE_CB_MAX_RESPONSE_TIME', 5000), // ms

        /*
        | 缓存配置
        */
        'cache' => [
            'store' => env('RESILIENCE_CB_CACHE_STORE', 'redis'),
            'ttl' => env('RESILIENCE_CB_CACHE_TTL', 3600), // seconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 服务降级中间件配置
    |--------------------------------------------------------------------------
    */
    'service_degradation' => [
        'default_mode' => env('RESILIENCE_DEGRADATION_MODE', 'block'),
        'cache_ttl' => env('RESILIENCE_DEGRADATION_CACHE_TTL', 300), // seconds

        /*
        | 降级级别配置
        */
        'levels' => [
            1 => [
                'description' => '轻度降级：返回缓存数据',
                'response_template' => [
                    'message' => 'Service partially degraded, returning cached data',
                    'degraded' => true,
                    'level' => 1,
                ],
            ],
            2 => [
                'description' => '中度降级：返回简化数据',
                'response_template' => [
                    'message' => 'Service degraded, returning simplified response',
                    'degraded' => true,
                    'level' => 2,
                ],
            ],
            3 => [
                'description' => '重度降级：返回默认响应',
                'response_template' => [
                    'message' => 'Service heavily degraded, returning default response',
                    'degraded' => true,
                    'level' => 3,
                ],
            ],
        ],

        /*
        | 降级触发条件
        */
        'triggers' => [
            'cpu_high' => 2,
            'cpu_critical' => 3,
            'memory_high' => 2,
            'memory_critical' => 3,
            'redis_unavailable' => 1,
            'database_unavailable' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 日志配置
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('RESILIENCE_LOGGING_ENABLED', true),
        'channel' => env('RESILIENCE_LOG_CHANNEL', 'daily'),
        'level' => env('RESILIENCE_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 监控和指标配置
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'enabled' => env('RESILIENCE_METRICS_ENABLED', true),
        'collection_interval' => env('RESILIENCE_METRICS_INTERVAL', 60), // seconds
        'retention_days' => env('RESILIENCE_METRICS_RETENTION', 7),
    ],
];
