<?php
// Подключаем конфигурацию бота
require_once 'bot-config.php';

// Функция для логирования запросов (для отладки)
function logRequest($data) {
    file_put_contents('telegram-log.txt', date('Y-m-d H:i:s') . ' - ' . $data . PHP_EOL, FILE_APPEND);
}

// Получаем данные от Telegram
$content = file_get_contents('php://input');
$update = json_decode($content, true);
logRequest($content);

// Проверяем, что мы получили сообщение
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = $message['text'] ?? '';
    
    // Простая обработка команд
    $response = '';
    
    if ($text === '/start') {
        $response = 'Привет! Я бот для WordPress сайта. Чем могу помочь?';
    } elseif ($text === '/help') {
        $response = "Доступные команды:\n/start - Начать общение\n/help - Показать справку\n/info - Информация о сайте";
    } elseif ($text === '/info') {
        $response = "Это бот для WordPress сайта, разработанный для демонстрации интеграции Telegram с WordPress.";
    } else {
        $response = "Получил ваше сообщение: '$text'. Используйте /help для справки.";
    }
    
    // Отправляем ответ обратно в Telegram
    $telegram_api_url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $parameters = [
        'chat_id' => $chat_id,
        'text' => $response,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init($telegram_api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    logRequest('Отправлен ответ: ' . $response);
}

// Отправляем успешный статус Telegram серверу
http_response_code(200);
echo 'OK';
?>
