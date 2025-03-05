<?php
// 设置 PHP 输出的字符编码为 UTF-8
header('Content-Type: text/html; charset=utf-8');

// 增加内存限制
ini_set('memory_limit', '256M');

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// 加载环境变量
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// 引入 ResendMailer 类
require __DIR__ . '/../app/ResendMailer.php';

// 初始化 ResendMailer
$mailer = new ResendMailer();

// 处理表单提交
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = $_POST['subject'];
    $html = nl2br($_POST['html']); // 将普通换行符转换为 <br> 标签

    // 处理批量导入的邮箱列表
    if (isset($_FILES['email_list']) && $_FILES['email_list']['error'] === UPLOAD_ERR_OK) {
        $emailList = file($_FILES['email_list']['tmp_name'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($emailList === false) {
            $message = "无法读取邮箱列表文件。<br>";
        } else {
            // 设置总任务数
            $mailer->setTotalTasks(count($emailList));

            $successCount = 0;
            $failCount = 0;

            // 逐个发送邮件
            foreach ($emailList as $email) {
                $email = trim($email);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $message .= "无效的邮箱地址: {$email}<br>";
                    $failCount++;
                    continue;
                }

                $result = $mailer->sendEmail($email, $subject, $html);
                if (isset($result['error'])) {
                    $message .= "发送邮件到 {$email} 失败: {$result['error']}<br>";
                    $failCount++;
                } else {
                    $message .= "发送邮件到 {$email} 成功<br>";
                    $successCount++;
                }
            }

            $message .= "邮件发送完成：成功 {$successCount} 封，失败 {$failCount} 封。<br>";
        }
    } else {
        $message = "请上传有效的邮箱列表文件。<br>";
    }
}

// 添加进度查询 API
if (isset($_GET['action']) && $_GET['action'] === 'get_progress') {
    header('Content-Type: application/json');
    echo json_encode([
        'progress' => $mailer->getProgress(),
        'completed' => $mailer->getCompletedTasks(),
        'total' => $mailer->getTotalTasks(),
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>邮件群发平台</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
        }
        label {
            display: block;
            margin-top: 10px;
        }
        input, textarea, button {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            box-sizing: border-box;
        }
        button {
            background-color: #28a745;
            color: white;
            border: none;
            cursor: pointer;
        }
        button:hover {
            background-color: #218838;
        }
        .message {
            margin-top: 20px;
            padding: 10px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
        }
        .progress-container {
            margin-top: 20px;
            padding: 10px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
        }
        .progress-bar {
            width: 100%;
            height: 20px;
            background-color: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
        }
        .progress {
            height: 100%;
            background-color: #28a745;
            transition: width 0.5s ease;
        }
        #progress-text {
            margin-top: 10px;
            font-size: 14px;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>邮件群发平台</h1>
        <form method="POST" enctype="multipart/form-data">
            <label for="subject">邮件主题:</label>
            <input type="text" id="subject" name="subject" placeholder="请输入邮件主题" required>

            <label for="html">邮件内容（支持换行）:</label>
            <textarea id="html" name="html" rows="10" placeholder="请输入邮件内容，按回车键换行" required></textarea>

            <label for="email_list">批量导入邮箱列表（TXT 或 CSV 文件，每行一个邮箱）:</label>
            <input type="file" id="email_list" name="email_list" accept=".txt,.csv" required>

            <button type="submit">发送邮件</button>
        </form>

        <?php if (!empty($message)): ?>
            <div class="message">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="progress-container">
            <h3>任务进度</h3>
            <div class="progress-bar">
                <div class="progress" id="progress-bar" style="width: 0%;"></div>
            </div>
            <p id="progress-text">0% (0/0)</p>
        </div>
    </div>

    <script>
        function updateProgress() {
            fetch('?action=get_progress')
                .then(response => response.json())
                .then(data => {
                    const progressBar = document.getElementById('progress-bar');
                    const progressText = document.getElementById('progress-text');

                    // 更新进度条和百分比
                    progressBar.style.width = data.progress + '%';
                    progressText.textContent = `${data.progress}% (${data.completed}/${data.total})`;

                    // 如果任务未完成，继续轮询
                    if (data.progress < 100) {
                        setTimeout(updateProgress, 1000); // 每 1 秒轮询一次
                    }
                })
                .catch(error => {
                    console.error('获取进度失败:', error);
                });
        }

        // 表单提交后开始轮询
        document.querySelector('form').addEventListener('submit', function () {
            setTimeout(updateProgress, 1000); // 延迟 1 秒开始轮询
        });
    </script>
</body>
</html>