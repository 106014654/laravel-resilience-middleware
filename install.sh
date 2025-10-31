#!/bin/bash

echo "正在安装 Laravel Resilience Middleware..."

# 发布配置文件
echo "发布配置文件..."
php artisan vendor:publish --provider="OneLap\LaravelResilienceMiddleware\ResilienceMiddlewareServiceProvider" --tag="resilience-config"

# 发布示例路由（可选）
read -p "是否发布示例路由文件? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]
then
    echo "发布示例路由..."
    php artisan vendor:publish --provider="OneLap\LaravelResilienceMiddleware\ResilienceMiddlewareServiceProvider" --tag="resilience-examples"
    
    echo "请在 routes/web.php 或 RouteServiceProvider 中添加以下代码来加载示例路由："
    echo "Route::middleware('web')->group(base_path('routes/resilience-examples.php'));"
fi

echo
echo "安装完成！"
echo
echo "下一步："
echo "1. 检查并调整 config/resilience.php 配置文件"  
echo "2. 在 .env 文件中设置相关环境变量"
echo "3. 在路由中使用中间件，例如："
echo "   Route::get('/api/users', 'UserController@index')->middleware('resilience.rate-limit:sliding_window,60,1');"
echo
echo "更多使用方法请参考 README.md"