<?php

namespace OneLap\LaravelResilienceMiddleware\Examples;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 展示如何在业务代码中检查和响应降级策略
 */
class DegradationAwareController
{
    /**
     * 支持降级的分析接口示例
     */
    public function analytics(Request $request): JsonResponse
    {
        // 检查重型分析是否被禁用
        if (app()->bound('analytics.heavy.disabled')) {
            return response()->json([
                'message' => '分析功能已暂时简化',
                'simplified' => true,
                'data' => $this->getSimpleAnalytics()
            ]);
        }

        // 检查是否启用了最小响应处理
        if (app()->bound('response.minimal')) {
            return response()->json([
                'data' => $this->getMinimalAnalytics()
            ]);
        }

        // 正常的完整分析
        return response()->json([
            'data' => $this->getFullAnalytics()
        ]);
    }

    /**
     * 支持降级的推荐接口示例
     */
    public function recommendations(Request $request): JsonResponse
    {
        // 检查推荐引擎是否被禁用
        if (
            app()->bound('recommendations.disabled') ||
            Cache::get('recommendations_disabled', false)
        ) {

            return response()->json([
                'message' => '推荐功能暂时不可用',
                'fallback_data' => $this->getFallbackRecommendations()
            ]);
        }

        // 检查是否需要拒绝非必要请求
        if (app()->bound('request.non_essential')) {
            return response()->json([
                'error' => '系统负载较高，请稍后重试'
            ], 503);
        }

        return response()->json([
            'data' => $this->getRecommendations($request)
        ]);
    }

    /**
     * 文件上传接口 - 支持降级
     */
    public function uploadFile(Request $request): JsonResponse
    {
        // 检查文件处理是否被禁用
        if (app()->bound('file_processing_disabled')) {
            return response()->json([
                'error' => '文件处理功能暂时不可用',
                'message' => '请稍后重试'
            ], 503);
        }

        // 检查是否拒绝大型请求
        if (app()->bound('request.too_large')) {
            return response()->json([
                'error' => '请求过大，请上传较小的文件'
            ], 413);
        }

        // 正常文件处理逻辑
        return $this->processFileUpload($request);
    }

    /**
     * 数据库查询接口 - 支持降级
     */
    public function getReports(Request $request): JsonResponse
    {
        // 检查是否启用了缓存优先策略
        if (config('database.cache_only') || app()->bound('database.cache_only')) {
            $cacheKey = 'reports_' . md5($request->getQueryString());
            $cachedData = Cache::get($cacheKey);

            if ($cachedData) {
                return response()->json([
                    'data' => $cachedData,
                    'cached' => true,
                    'message' => '数据来自缓存'
                ]);
            } else {
                return response()->json([
                    'error' => '数据暂时不可用',
                    'message' => '请稍后重试'
                ], 503);
            }
        }

        // 检查是否禁用了复杂查询
        if (Cache::get('complex_queries_disabled', false)) {
            return response()->json([
                'data' => $this->getSimpleReports(),
                'simplified' => true,
                'message' => '返回简化报告数据'
            ]);
        }

        // 检查数据库是否被完全绕过
        if (app()->bound('database.bypassed')) {
            return response()->json([
                'data' => $this->getStaticReports(),
                'static' => true,
                'message' => '返回静态报告数据'
            ]);
        }

        // 正常的数据库查询
        return response()->json([
            'data' => $this->getFullReports($request)
        ]);
    }

    /**
     * Redis操作示例 - 支持降级
     */
    public function cacheOperation(Request $request): JsonResponse
    {
        $key = 'user_data_' . $request->user()->id;

        // 检查Redis是否被绕过
        if (app()->bound('redis.bypassed')) {
            // 使用本地数组缓存或文件缓存
            return response()->json([
                'message' => 'Redis不可用，使用备用缓存',
                'data' => $this->getDataFromFallbackCache($key)
            ]);
        }

        // 检查Redis是否为只读模式
        if (Cache::get('redis_read_only', false)) {
            $data = Cache::get($key);
            if ($data) {
                return response()->json(['data' => $data]);
            } else {
                return response()->json([
                    'error' => 'Redis只读模式，无法写入新数据'
                ], 503);
            }
        }

        // 检查是否启用了本地缓存后备
        if (app()->bound('cache.local_fallback')) {
            $data = $this->getDataWithLocalFallback($key);
            return response()->json(['data' => $data]);
        }

        // 正常Redis操作
        return response()->json([
            'data' => $this->normalCacheOperation($key, $request)
        ]);
    }

    // ========== 降级时的替代方法 ==========

    protected function getSimpleAnalytics(): array
    {
        return [
            'total_users' => Cache::remember('simple_user_count', 3600, function () {
                return DB::table('users')->count();
            }),
            'message' => '简化分析数据'
        ];
    }

    protected function getMinimalAnalytics(): array
    {
        return [
            'status' => 'ok',
            'timestamp' => now()->toISOString()
        ];
    }

    protected function getFullAnalytics(): array
    {
        // 完整的分析逻辑
        return [
            'users' => $this->getUserAnalytics(),
            'revenue' => $this->getRevenueAnalytics(),
            'performance' => $this->getPerformanceMetrics(),
            'trends' => $this->getTrendAnalysis()
        ];
    }

    protected function getFallbackRecommendations(): array
    {
        return [
            'popular_items' => Cache::remember('popular_items', 7200, function () {
                return DB::table('items')
                    ->orderBy('view_count', 'desc')
                    ->limit(5)
                    ->pluck('name')
                    ->toArray();
            }),
            'type' => 'popular_fallback'
        ];
    }

    protected function getRecommendations(Request $request): array
    {
        // 完整的推荐算法
        return [
            'personalized' => $this->getPersonalizedRecommendations($request->user()),
            'collaborative' => $this->getCollaborativeRecommendations($request->user()),
            'content_based' => $this->getContentBasedRecommendations($request->user())
        ];
    }

    protected function getSimpleReports(): array
    {
        return Cache::remember('simple_reports', 1800, function () {
            return [
                'total_orders' => DB::table('orders')->count(),
                'total_revenue' => DB::table('orders')->sum('total'),
                'message' => '简化报告数据'
            ];
        });
    }

    protected function getStaticReports(): array
    {
        return [
            'message' => '报告数据暂时不可用',
            'static_info' => [
                'last_update' => '2024-11-01',
                'status' => 'maintenance_mode'
            ]
        ];
    }

    protected function getFullReports(Request $request): array
    {
        // 完整的报告查询逻辑
        return [
            'detailed_analytics' => $this->getDetailedAnalytics($request),
            'charts' => $this->getChartData($request),
            'exports' => $this->getExportOptions($request)
        ];
    }

    protected function getDataFromFallbackCache(string $key): array
    {
        // 从文件缓存获取数据
        try {
            return Cache::store('file')->remember($key, 3600, function () {
                return ['fallback' => true, 'source' => 'file_cache'];
            });
        } catch (\Exception $e) {
            return ['fallback' => true, 'error' => 'File cache unavailable'];
        }
    }

    protected function getDataWithLocalFallback(string $key): array
    {
        // 先尝试Redis，失败则使用本地缓存
        try {
            return Cache::store('redis')->remember($key, 1800, function () use ($key) {
                // Redis不可用时的后备数据
                return ['local_fallback' => true, 'source' => 'redis'];
            });
        } catch (\Exception $e) {
            // Redis完全不可用，使用数组缓存
            return Cache::store('array')->remember($key, 600, function () {
                return ['local_fallback' => true, 'error' => 'Redis unavailable', 'source' => 'array'];
            });
        }
    }

    protected function normalCacheOperation(string $key, Request $request): array
    {
        return Cache::remember($key, 3600, function () use ($request) {
            return [
                'user_data' => $request->user()->toArray(),
                'preferences' => $this->getUserPreferences($request->user()),
                'cached_at' => now()->toISOString()
            ];
        });
    }

    protected function processFileUpload(Request $request): JsonResponse
    {
        // 正常的文件上传处理逻辑
        $file = $request->file('upload');

        // 处理文件...

        return response()->json([
            'message' => '文件上传成功',
            'file_path' => 'uploads/' . $file->getClientOriginalName()
        ]);
    }

    // 其他辅助方法...
    protected function getUserAnalytics()
    {
        return [];
    }
    protected function getRevenueAnalytics()
    {
        return [];
    }
    protected function getPerformanceMetrics()
    {
        return [];
    }
    protected function getTrendAnalysis()
    {
        return [];
    }
    protected function getPersonalizedRecommendations($user)
    {
        return [];
    }
    protected function getCollaborativeRecommendations($user)
    {
        return [];
    }
    protected function getContentBasedRecommendations($user)
    {
        return [];
    }
    protected function getDetailedAnalytics($request)
    {
        return [];
    }
    protected function getChartData($request)
    {
        return [];
    }
    protected function getExportOptions($request)
    {
        return [];
    }
    protected function getUserPreferences($user)
    {
        return [];
    }
}
