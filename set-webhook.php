<?php
// Подключаем конфигурацию бота
require_once 'bot-config.php';

// URL, который будет получать обновления от Telegram
// В реальном проекте замените на ваш домен
$webhookUrl = 'https://YOUR_NGROK_URL/telegram-webhook.php';

// API URL для установки вебхука
$apiUrl = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/setWebhook';

// Параметры запроса
$params = [
    'url' => $webhookUrl,
    'allowed_updates' => json_encode(['message', 'callback_query'])
];

// Отправка запроса к Telegram API
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
$response = curl_exec($ch);
curl_close($ch);

// Вывод результата
$result = json_decode($response, true);
echo '<pre>';
print_r($result);
echo '</pre>';
?>
