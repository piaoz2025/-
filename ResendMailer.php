<?php

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ResendMailer {
    private $client;
    private $apiKey;
    private $logFile;
    private $proxyApiUrl;
    private $currentProxy;
    private $totalTasks = 0; // 总任务数
    private $completedTasks = 0; // 已完成任务数
    private $apiKeys = []; // 存储多组 API 密钥
    private $fromEmails = []; // 存储多组发信域名

    public function __construct($logFile = null) {
        $this->logFile = $logFile ?? __DIR__ . '/../logs/resend_mailer.log';
        $this->proxyApiUrl = $_ENV['PROXY_API_URL']; // 从 .env 加载代理 API 地址
        $this->currentProxy = $this->getDynamicProxy(); // 初始化代理 IP

        // 加载多组 API 密钥和发信域名
        $this->loadApiKeys();
        $this->loadFromEmails();

        // 随机选择一组 API 密钥和发信域名
        $this->apiKey = $this->getRandomApiKey();
        $this->fromEmail = $this->getRandomFromEmail();

        $this->client = new Client([
            'base_uri' => 'https://api.resend.com',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    private function loadApiKeys() {
        // 从 .env 加载所有 RESEND_API_KEY_*
        foreach ($_ENV as $key => $value) {
            if (strpos($key, 'RESEND_API_KEY_') === 0) {
                $this->apiKeys[] = $value;
            }
        }
    }

    private function loadFromEmails() {
        // 从 .env 加载所有 FROM_EMAIL_*
        foreach ($_ENV as $key => $value) {
            if (strpos($key, 'FROM_EMAIL_') === 0) {
                $this->fromEmails[] = $value;
            }
        }
    }

    private function getRandomApiKey() {
        if (empty($this->apiKeys)) {
            throw new \Exception("未找到可用的 API 密钥");
        }
        return $this->apiKeys[array_rand($this->apiKeys)];
    }

    private function getRandomFromEmail() {
        if (empty($this->fromEmails)) {
            throw new \Exception("未找到可用的发信域名");
        }
        return $this->fromEmails[array_rand($this->fromEmails)];
    }

    public function sendEmail($to, $subject, $html) {
        try {
            // 使用当前代理 IP 发送邮件
            $response = $this->client->post('/emails', [
                'json' => [
                    'from' => $this->fromEmail, // 使用随机选择的发信域名
                    'to' => $to,
                    'subject' => $subject,
                    'html' => $html,
                ],
                'proxy' => $this->currentProxy, // 设置代理
            ]);

            $this->log("邮件发送成功: {$to} (使用代理: {$this->currentProxy}, 发信域名: {$this->fromEmail})");

            // 发送成功后，更换代理 IP
            $this->currentProxy = $this->getDynamicProxy();
            if (!$this->currentProxy) {
                throw new \Exception("无法获取新的代理 IP");
            }

            // 更新任务进度
            $this->incrementCompletedTasks();

            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            $proxy = isset($this->currentProxy) ? $this->currentProxy : '无'; // 替换 ?? 运算符
            $this->log("邮件发送失败: {$to} - {$e->getMessage()} (使用代理: {$proxy}, 发信域名: {$this->fromEmail})");
            return ['error' => $e->getMessage()];
        } catch (\Exception $e) {
            $proxy = isset($this->currentProxy) ? $this->currentProxy : '无'; // 替换 ?? 运算符
            $this->log("邮件发送失败: {$to} - {$e->getMessage()} (使用代理: {$proxy}, 发信域名: {$this->fromEmail})");
            return ['error' => $e->getMessage()];
        }
    }

    private function getDynamicProxy() {
        try {
            // 调用代理 API 接口，获取代理 IP 和端口
            $proxy = file_get_contents($this->proxyApiUrl);
            if ($proxy === false) {
                throw new \Exception("无法访问代理 API 接口");
            }

            // 返回代理 IP 和端口
            return trim($proxy); // 去除多余的空白字符
        } catch (\Exception $e) {
            $this->log("获取代理 IP 失败: {$e->getMessage()}");
            return null;
        }
    }

    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }

    // 设置总任务数
    public function setTotalTasks($totalTasks) {
        $this->totalTasks = $totalTasks;
    }

    // 获取任务进度百分比
    public function getProgress() {
        if ($this->totalTasks === 0) {
            return 0;
        }
        return intval(($this->completedTasks / $this->totalTasks) * 100);
    }

    // 获取已完成任务数
    public function getCompletedTasks() {
        return $this->completedTasks;
    }

    // 获取总任务数
    public function getTotalTasks() {
        return $this->totalTasks;
    }

    // 增加已完成任务数
    public function incrementCompletedTasks() {
        $this->completedTasks++;
    }
}