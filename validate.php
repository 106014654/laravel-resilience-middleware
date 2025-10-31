<?php

/**
 * Laravel Resilience Middleware 包验证脚本
 * 用于验证包结构和配置的完整性
 */

echo "🔍 Laravel Resilience Middleware 包验证\n";
echo "=====================================\n\n";

// 检查基本文件
$requiredFiles = [
    'composer.json' => 'Composer 配置文件',
    'README.md' => '说明文档',
    'LICENSE' => '许可证文件',
    'CHANGELOG.md' => '更新日志',
    'PUBLISH_GUIDE.md' => '发布指南',
    'src/ResilienceMiddlewareServiceProvider.php' => '服务提供者',
    'src/Services/SystemMonitorService.php' => '系统监控服务',
    'src/Middleware/ServiceDegradationMiddleware.php' => '服务降级中间件',
    'src/Middleware/RateLimitingMiddleware.php' => '限流中间件',
    'src/Middleware/CircuitBreakerMiddleware.php' => '熔断器中间件',
    'config/resilience.php' => '配置文件',
    'examples/routes.php' => '示例路由',
];

$errors = [];
$warnings = [];

echo "📁 检查必需文件...\n";
foreach ($requiredFiles as $file => $description) {
    if (file_exists($file)) {
        echo "✅ {$description}: {$file}\n";
    } else {
        echo "❌ 缺少文件: {$file}\n";
        $errors[] = "缺少必需文件: {$file}";
    }
}

echo "\n";

// 检查 composer.json
echo "📋 检查 composer.json...\n";
if (file_exists('composer.json')) {
    $composerData = json_decode(file_get_contents('composer.json'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "❌ composer.json 格式错误\n";
        $errors[] = "composer.json 格式错误";
    } else {
        // 检查必需字段
        $requiredFields = [
            'name' => '包名',
            'description' => '描述', 
            'type' => '类型',
            'license' => '许可证',
            'authors' => '作者信息',
            'require' => '依赖',
            'autoload' => '自动加载',
            'extra' => 'Laravel 自动发现配置'
        ];
        
        foreach ($requiredFields as $field => $label) {
            if (isset($composerData[$field])) {
                echo "✅ {$label}: {$field}\n";
            } else {
                echo "⚠️  缺少字段: {$field}\n";
                $warnings[] = "composer.json 缺少推荐字段: {$field}";
            }
        }
        
        // 检查包名格式
        if (isset($composerData['name'])) {
            if (!preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', $composerData['name'])) {
                echo "❌ 包名格式不正确: {$composerData['name']}\n";
                $errors[] = "包名格式应为: vendor/package-name";
            }
        }
        
        // 检查 Laravel 自动发现
        if (isset($composerData['extra']['laravel']['providers'])) {
            echo "✅ Laravel 自动发现配置正确\n";
        } else {
            echo "⚠️  缺少 Laravel 自动发现配置\n";
            $warnings[] = "建议添加 Laravel 自动发现配置";
        }
    }
} else {
    echo "❌ 找不到 composer.json 文件\n";
    $errors[] = "找不到 composer.json 文件";
}

echo "\n";

// 检查 PSR-4 自动加载
echo "🔄 检查 PSR-4 自动加载...\n";
if (isset($composerData['autoload']['psr-4'])) {
    foreach ($composerData['autoload']['psr-4'] as $namespace => $path) {
        $fullPath = rtrim($path, '/\\');
        if (is_dir($fullPath)) {
            echo "✅ 命名空间映射正确: {$namespace} -> {$path}\n";
        } else {
            echo "❌ 路径不存在: {$path}\n";
            $errors[] = "自动加载路径不存在: {$path}";
        }
    }
} else {
    echo "❌ 缺少 PSR-4 自动加载配置\n";
    $errors[] = "缺少 PSR-4 自动加载配置";
}

echo "\n";

// 检查 PHP 语法
echo "🔧 检查 PHP 语法...\n";
$phpFiles = glob('src/**/*.php');
foreach ($phpFiles as $file) {
    $output = [];
    $return = 0;
    exec("php -l \"{$file}\" 2>&1", $output, $return);
    
    if ($return === 0) {
        echo "✅ 语法正确: {$file}\n";
    } else {
        echo "❌ 语法错误: {$file}\n";
        echo "   " . implode("\n   ", $output) . "\n";
        $errors[] = "PHP 语法错误: {$file}";
    }
}

echo "\n";

// 检查 README 内容
echo "📖 检查 README 内容...\n";
if (file_exists('README.md')) {
    $readme = file_get_contents('README.md');
    
    $requiredSections = [
        '# Laravel Resilience Middleware' => '标题',
        '## 特性' => '特性介绍',
        '## 安装' => '安装说明', 
        '## 快速开始' => '使用示例',
        '## 详细配置' => '配置说明'
    ];
    
    foreach ($requiredSections as $section => $label) {
        if (strpos($readme, $section) !== false) {
            echo "✅ {$label}: 已包含\n";
        } else {
            echo "⚠️  {$label}: 建议添加\n";
            $warnings[] = "README 建议添加: {$label}";
        }
    }
} else {
    echo "❌ 找不到 README.md 文件\n";
    $errors[] = "找不到 README.md 文件";
}

echo "\n";

// 检查 Git 状态
echo "📦 检查 Git 状态...\n";
if (is_dir('.git')) {
    // 检查是否有未提交的更改
    exec('git status --porcelain 2>&1', $gitOutput, $gitReturn);
    
    if ($gitReturn === 0) {
        if (empty($gitOutput)) {
            echo "✅ Git 工作目录干净\n";
        } else {
            echo "⚠️  有未提交的更改:\n";
            foreach ($gitOutput as $line) {
                echo "   {$line}\n";
            }
            $warnings[] = "有未提交的 Git 更改";
        }
    }
    
    // 检查远程仓库
    exec('git remote -v 2>&1', $remoteOutput, $remoteReturn);
    if ($remoteReturn === 0 && !empty($remoteOutput)) {
        echo "✅ Git 远程仓库已配置\n";
        foreach ($remoteOutput as $line) {
            if (strpos($line, 'origin') === 0) {
                echo "   {$line}\n";
                break;
            }
        }
    } else {
        echo "⚠️  未配置 Git 远程仓库\n";
        $warnings[] = "建议配置 Git 远程仓库";
    }
} else {
    echo "⚠️  不是 Git 仓库\n";
    $warnings[] = "建议初始化 Git 仓库";
}

echo "\n";

// 输出验证结果
echo "📊 验证结果\n";
echo "==========\n";

if (empty($errors)) {
    echo "🎉 恭喜！包结构验证通过，可以发布到 Packagist！\n\n";
    
    echo "🚀 发布步骤：\n";
    echo "1. 运行 publish.bat 或 publish.sh\n";
    echo "2. 推送到 GitHub\n"; 
    echo "3. 在 Packagist.org 注册\n";
    echo "4. 配置自动同步\n\n";
    
    echo "📖 详细指南请参考 PUBLISH_GUIDE.md\n\n";
} else {
    echo "❌ 发现错误，请修复后再发布：\n";
    foreach ($errors as $error) {
        echo "  • {$error}\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "⚠️  建议优化：\n";
    foreach ($warnings as $warning) {
        echo "  • {$warning}\n";
    }
    echo "\n";
}

echo "验证完成！\n";