#!/bin/bash

# Laravel Resilience Middleware 发布到 Packagist 脚本

echo "🚀 准备发布 Laravel Resilience Middleware 到 Packagist..."

# 检查必要工具
command -v git >/dev/null 2>&1 || { echo "❌ Git 未安装，请先安装 Git"; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "❌ Composer 未安装，请先安装 Composer"; exit 1; }

# 检查是否有 git 仓库
if [ ! -d ".git" ]; then
    echo "📦 初始化 Git 仓库..."
    git init
    echo "✓ Git 仓库初始化完成"
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

# 获取用户输入
echo
echo "📋 发布配置："
read -p "请输入你的 GitHub 用户名: " github_username
read -p "请输入包的版本号 (默认: 1.0.0): " version
version=${version:-1.0.0}

echo
echo "🔍 发布检查清单："
echo "✓ composer.json - Composer 包配置"
echo "✓ README.md - 详细文档"
echo "✓ CHANGELOG.md - 更新日志"
echo "✓ LICENSE - MIT 许可证"
echo "✓ PUBLISH_GUIDE.md - 发布指南"
echo "✓ 源代码和配置文件"
echo "✓ 示例和测试文件"

echo
echo "📦 包信息："
echo "名称: onelap/laravel-resilience-middleware"
echo "版本: v$version"
echo "GitHub: https://github.com/$github_username/laravel-resilience-middleware"
echo "Packagist: https://packagist.org/packages/onelap/laravel-resilience-middleware"

echo
echo "🚀 发布步骤："
echo "1. ✅ 验证 composer.json"
echo "2. 📝 提交所有更改到 Git"
echo "3. 🏷️  创建版本标签"
echo "4. 📤 推送到 GitHub"
echo "5. 🌐 在 Packagist 注册包"
echo "6. 🔄 配置自动同步"

read -p "是否继续发布流程? (y/n): " -n 1 -r
echo

if [[ $REPLY =~ ^[Yy]$ ]]; then
    # 步骤 1: 验证 composer.json
    echo "1️⃣  验证 composer.json..."
    composer validate
    if [ $? -ne 0 ]; then
        echo "❌ composer.json 验证失败，请检查语法"
        exit 1
    fi
    echo "✅ composer.json 验证通过"

    # 步骤 2: 添加 Git remote（如果不存在）
    echo
    echo "2️⃣  配置 Git 远程仓库..."
    if ! git remote get-url origin > /dev/null 2>&1; then
        git remote add origin "https://github.com/$github_username/laravel-resilience-middleware.git"
        echo "✅ 添加远程仓库: https://github.com/$github_username/laravel-resilience-middleware.git"
    else
        echo "✅ 远程仓库已配置"
    fi

    # 步骤 3: 提交更改
    echo
    echo "3️⃣  提交所有更改..."
    git add .
    git commit -m "feat: 发布 Laravel Resilience Middleware v$version

🚀 主要功能:
- 🚦 限流中间件 (固定窗口/滑动窗口/令牌桶)
- 🔄 熔断器中间件 (三状态熔断器机制)
- ⬇️ 服务降级中间件 (双模式降级策略)
- 📊 系统监控服务 (CPU/内存/Redis/数据库)
- 🎯 智能保护 (基于系统压力动态调整)
- 🔧 易于使用 (Laravel 自动发现支持)

📖 文档和示例:
- 详细的安装和使用文档
- 完整的示例路由和配置
- PHPUnit 测试覆盖
- 发布指南和最佳实践"

    # 步骤 4: 创建标签
    echo "4️⃣  创建版本标签..."
    git tag -a "v$version" -m "Release Laravel Resilience Middleware v$version"
    echo "✅ 创建标签 v$version"

    # 步骤 5: 推送到 GitHub
    echo
    echo "5️⃣  推送到 GitHub..."
    echo "执行: git push -u origin main"
    echo "执行: git push origin --tags"
    
    read -p "是否立即推送到 GitHub? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        git push -u origin main
        git push origin --tags
        echo "✅ 代码已推送到 GitHub"
    else
        echo "⚠️  请手动执行推送命令"
    fi

    echo
    echo "🎉 Git 发布准备完成!"
    echo
    echo "📋 接下来的步骤:"
    echo
    echo "1️⃣  在 GitHub 创建仓库 (如果还没有):"
    echo "   - 访问: https://github.com/new"
    echo "   - 仓库名: laravel-resilience-middleware"
    echo "   - 描述: Laravel application resilience middleware with rate limiting, circuit breaker, and service degradation"
    echo "   - 选择 Public"
    echo "   - 不要初始化 README（已存在）"
    echo
    echo "2️⃣  注册到 Packagist:"
    echo "   - 访问: https://packagist.org"
    echo "   - 使用 GitHub 账号登录"
    echo "   - 点击 Submit 按钮"
    echo "   - 输入仓库 URL: https://github.com/$github_username/laravel-resilience-middleware"
    echo "   - 点击 Check 然后 Submit"
    echo
    echo "3️⃣  配置自动同步 (可选但推荐):"
    echo "   - 在 GitHub 仓库设置中添加 Webhook"
    echo "   - Payload URL: https://packagist.org/api/github?username=YOUR_PACKAGIST_USERNAME"
    echo "   - Content type: application/json"
    echo "   - Secret: 从 Packagist 个人资料获取 API Token"
    echo
    echo "4️⃣  验证发布:"
    echo "   - 等待几分钟让 Packagist 处理"
    echo "   - 访问: https://packagist.org/packages/onelap/laravel-resilience-middleware"
    echo "   - 测试安装: composer require onelap/laravel-resilience-middleware"
    echo
    echo "📖 详细指南请参考 PUBLISH_GUIDE.md"
    echo
    echo "🎊 恭喜！你的 Laravel Resilience Middleware 包即将发布到 Packagist！"
else
    echo "❌ 发布流程已取消"
fi