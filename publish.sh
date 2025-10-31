#!/bin/bash

# Laravel Resilience Middleware 发布脚本

echo "准备发布 Laravel Resilience Middleware..."

# 检查是否有 git 仓库
if [ ! -d ".git" ]; then
    echo "初始化 Git 仓库..."
    git init
    git add .
    git commit -m "Initial commit: Laravel Resilience Middleware v1.0.0"
fi

# 创建 .gitignore
cat > .gitignore << EOF
/vendor/
composer.lock
.env
.DS_Store
Thumbs.db
EOF

echo ".gitignore 已创建"

# 检查 composer.json 语法
echo "检查 composer.json 语法..."
composer validate

if [ $? -ne 0 ]; then
    echo "composer.json 语法有误，请检查后重试"
    exit 1
fi

echo
echo "发布清单："
echo "✓ composer.json - Composer 包配置"
echo "✓ README.md - 详细文档"
echo "✓ CHANGELOG.md - 更新日志"
echo "✓ LICENSE - MIT 许可证"
echo "✓ config/resilience.php - 配置文件"
echo "✓ src/ - 源代码目录"
echo "  ├── Services/SystemMonitorService.php - 系统监控服务"
echo "  ├── Middleware/ - 中间件目录"
echo "  │   ├── ServiceDegradationMiddleware.php - 服务降级中间件"
echo "  │   ├── RateLimitingMiddleware.php - 限流中间件"
echo "  │   └── CircuitBreakerMiddleware.php - 熔断器中间件"
echo "  ├── Facades/ - Facade 目录"
echo "  └── ResilienceMiddlewareServiceProvider.php - 服务提供者"
echo "✓ examples/routes.php - 示例路由"
echo "✓ tests/ - 测试目录"
echo "✓ install.sh - 安装脚本"

echo
echo "包信息："
echo "名称: onelap/laravel-resilience-middleware"
echo "版本: 1.0.0"
echo "描述: Laravel 应用韧性中间件包，提供限流、熔断器、服务降级等功能"
echo "兼容性: Laravel 5.5+, PHP 7.1+"

echo
echo "发布步骤："
echo "1. 提交所有更改到 Git 仓库"
echo "2. 创建 v1.0.0 标签"
echo "3. 推送到 GitHub/GitLab"
echo "4. 在 Packagist 上注册包"
echo "5. 等待 Packagist 同步"

read -p "是否继续提交和标记版本? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]
then
    echo "添加所有文件到 Git..."
    git add .
    
    echo "提交更改..."
    git commit -m "feat: 发布 Laravel Resilience Middleware v1.0.0

包含功能:
- 限流中间件 (固定窗口/滑动窗口/令牌桶)
- 熔断器中间件 (三状态熔断器)
- 服务降级中间件 (双模式降级)
- 系统监控服务 (CPU/内存/Redis/数据库)
- Laravel 自动发现支持
- 丰富的配置选项和示例"

    echo "创建 v1.0.0 标签..."
    git tag -a v1.0.0 -m "Laravel Resilience Middleware v1.0.0"
    
    echo "✓ Git 提交和标签创建完成"
    echo
    echo "下一步："
    echo "1. 推送到远程仓库: git push origin main --tags"
    echo "2. 在 Packagist.org 注册包"
    echo "3. 配置 GitHub Webhooks 自动更新"
fi

echo
echo "发布准备完成！"