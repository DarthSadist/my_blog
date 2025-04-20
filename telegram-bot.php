<?php
// Подключаем конфигурацию бота
require_once 'bot-config.php';

// Функция для логирования запросов (для отладки)
function logMessage($message) {
    file_put_contents('telegram-log.txt', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

// Функция для отправки сообщения пользователю
function sendMessage($chat_id, $text) {
    $telegram_api_url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $parameters = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init($telegram_api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    logMessage('Отправлен ответ: ' . $text);
    return $result;
}

// Функция для обработки сообщений
function processMessage($message) {
    if (isset($message['text'])) {
        $chat_id = $message['chat']['id'];
        $text = $message['text'];
        
        logMessage("Получено сообщение: $text от пользователя {$message['from']['username']}");
        
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
        
        sendMessage($chat_id, $response);
    }
}

// Получение обновлений от Telegram (long polling)
function getUpdates($offset = 0) {
    $telegram_api_url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/getUpdates';
    $parameters = [
        'offset' => $offset,
        'timeout' => 10,
    ];
    
    $url = $telegram_api_url . '?' . http_build_query($parameters);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true);
}

// Основной цикл обработки сообщений
logMessage('Бот запущен - ожидаю сообщения...');
echo "Бот запущен. Нажмите Ctrl+C для остановки.\n";

$offset = 0; // Начальное значение для offset

while (true) {
    $updates = getUpdates($offset);
    
    if (isset($updates['result']) && !empty($updates['result'])) {
        foreach ($updates['result'] as $update) {
            // Обновляем offset для получения только новых сообщений
            $offset = $update['update_id'] + 1;
            
            if (isset($update['message'])) {
                processMessage($update['message']);
            }
        }
    }
    
    // Небольшая пауза чтобы не перегружать API
    sleep(1);
}
?>
