<?php
/*
Plugin Name: Telegram to WP AutoPost
Description: Принимает сообщения из Telegram и автоматически публикует их как записи.
Version: 1.0
Author: Разработчик
*/

// Подключаем конфигурацию Telegram бота
function telegram_to_wp_load_config() {
    // Путь к файлу конфигурации бота
    $config_file = ABSPATH . 'bot-config.php';
    
    if (file_exists($config_file)) {
        require_once($config_file);
    } else {
        // Если файл не найден, определяем константы по умолчанию
        if (!defined('TELEGRAM_BOT_TOKEN')) {
            define('TELEGRAM_BOT_TOKEN', '8159307406:AAF-IWXac2mEoridOIjE_txBLD7LNX8ycgs');
        }
        if (!defined('TELEGRAM_BOT_USERNAME')) {
            define('TELEGRAM_BOT_USERNAME', 'denis_myblog_bot');
        }
    }
}
add_action('plugins_loaded', 'telegram_to_wp_load_config');

// Функция для логирования запросов (для отладки)
function telegram_to_wp_log($message) {
    $log_file = WP_CONTENT_DIR . '/telegram-wp-log.txt';
    file_put_contents($log_file, date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

// Добавляем опцию для хранения секретного токена
add_action('admin_init', 'telegram_to_wp_register_settings');
function telegram_to_wp_register_settings() {
    register_setting('telegram_to_wp_options', 'telegram_to_wp_secret_token');
}

// Регистрируем REST API endpoint для приёма POST-запросов
add_action('rest_api_init', function () {
    register_rest_route('telegram/v1', '/post', array(
        'methods'             => 'POST',
        'callback'            => 'handle_telegram_post',
        'permission_callback' => 'telegram_to_wp_verify_request',
        'args'                => array(
            'message' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_array($param);
                }
            ),
            'secret_token' => array(
                'required' => false
            )
        )
    ));
});

/**
 * Проверка валидности запроса и проверка секретного токена
 */
function telegram_to_wp_verify_request(WP_REST_Request $request) {
    // Получаем данные запроса
    $data = $request->get_json_params();
    
    // Проверяем наличие обязательных полей
    if (empty($data) || empty($data['message'])) {
        telegram_to_wp_log('Ошибка: отсутствуют обязательные поля');
        return false;
    }
    
    // Проверка секретного токена, если он настроен
    $stored_token = get_option('telegram_to_wp_secret_token');
    if (!empty($stored_token)) {
        $request_token = isset($data['secret_token']) ? $data['secret_token'] : '';
        if ($request_token !== $stored_token) {
            telegram_to_wp_log('Ошибка: неверный секретный токен');
            return false;
        }
    }
    
    return true;
}

/**
 * Загрузка медиафайла из URL и прикрепление к посту
 */
function telegram_to_wp_handle_media($url, $post_id, $description = '') {
    if (empty($url) || empty($post_id)) {
        return false;
    }
    
    // Загружаем все необходимые файлы для обработки медиа
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    // Загружаем медиафайл в библиотеку WordPress
    $attachment_id = media_sideload_image($url, $post_id, $description, 'id');
    
    if (is_wp_error($attachment_id)) {
        telegram_to_wp_log('Ошибка загрузки медиа: ' . $attachment_id->get_error_message());
        return false;
    }
    
    // Устанавливаем медиафайл как изображение записи
    set_post_thumbnail($post_id, $attachment_id);
    
    return $attachment_id;
}

/**
 * Получение URL медиафайла из сообщения Telegram
 */
function telegram_to_wp_get_media_url($message) {
    // Проверяем наличие фото
    if (!empty($message['photo'])) {
        // Берем последнее (самое большое) фото
        $photo = end($message['photo']);
        if (!empty($photo['file_id'])) {
            return telegram_to_wp_get_file_url($photo['file_id']);
        }
    }
    
    // Проверяем наличие документа (файла)
    if (!empty($message['document'])) {
        if (!empty($message['document']['file_id'])) {
            return telegram_to_wp_get_file_url($message['document']['file_id']);
        }
    }
    
    // Проверяем наличие видео
    if (!empty($message['video'])) {
        if (!empty($message['video']['file_id'])) {
            return telegram_to_wp_get_file_url($message['video']['file_id']);
        }
    }
    
    // Проверяем наличие аудио
    if (!empty($message['audio'])) {
        if (!empty($message['audio']['file_id'])) {
            return telegram_to_wp_get_file_url($message['audio']['file_id']);
        }
    }
    
    return false;
}

/**
 * Получение URL файла по его ID в Telegram
 */
function telegram_to_wp_get_file_url($file_id) {
    if (empty($file_id) || !defined('TELEGRAM_BOT_TOKEN')) {
        return false;
    }
    
    // Сначала получаем информацию о файле
    $get_file_url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/getFile';
    $ch = curl_init($get_file_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['file_id' => $file_id]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    $response = json_decode($result, true);
    
    if (isset($response['ok']) && $response['ok'] && !empty($response['result']['file_path'])) {
        // Формируем URL для скачивания файла
        return 'https://api.telegram.org/file/bot' . TELEGRAM_BOT_TOKEN . '/' . $response['result']['file_path'];
    }
    
    return false;
}

/**
 * Обработчик входящих сообщений от Telegram
 */
function handle_telegram_post(WP_REST_Request $request) {
    // Логируем входящие данные
    telegram_to_wp_log('Получен запрос на эндпоинт');
    
    // Получаем и декодируем данные
    $data = $request->get_json_params();
    telegram_to_wp_log('Данные запроса: ' . json_encode($data));
    
    // Детальная валидация данных сообщения
    if (empty($data['message'])) {
        telegram_to_wp_log('Ошибка: сообщение отсутствует');
        return new WP_REST_Response(
            ['success' => false, 'error' => 'Сообщение отсутствует'], 
            400
        );
    }
    
    $message = $data['message'];
    
    // Проверяем наличие текста или медиа
    $has_text = !empty($message['text']);
    $has_media = !empty($message['photo']) || !empty($message['document']) || !empty($message['video']) || !empty($message['audio']);
    
    if (!$has_text && !$has_media) {
        telegram_to_wp_log('Ошибка: ни текст, ни медиа не обнаружены');
        return new WP_REST_Response(
            ['success' => false, 'error' => 'Сообщение не содержит ни текста, ни медиа'], 
            400
        );
    }
    
    // Настройки поста по умолчанию
    $title = 'Сообщение из Telegram';
    $content = '';
    
    // Обрабатываем текст, если он есть
    if ($has_text) {
        $text = sanitize_text_field($message['text']);
        $title = mb_substr($text, 0, 50);
        if (strlen($text) > 50) {
            $title .= '...'; 
        }
        $content .= $text;
    }
    
    // Добавляем информацию об отправителе
    $from_info = '';
    if (!empty($message['from']['username'])) {
        $from_info = '<p><em>Отправлено пользователем @' . sanitize_text_field($message['from']['username']) . '</em></p>';
    } elseif (!empty($message['from']['first_name'])) {
        $from_name = sanitize_text_field($message['from']['first_name']);
        if (!empty($message['from']['last_name'])) {
            $from_name .= ' ' . sanitize_text_field($message['from']['last_name']);
        }
        $from_info = '<p><em>Отправлено пользователем ' . $from_name . '</em></p>';
    }
    
    $content .= "\n\n" . $from_info;
    
    // Создаем новый пост
    $post_id = wp_insert_post(array(
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => 'publish',  // Альтернативно, можно использовать статус 'pending' для модерации
        'post_author'  => 1,          // ID администратора или нужного автора
    ));
    
    if (!$post_id) {
        telegram_to_wp_log('Ошибка: не удалось создать пост');
        return new WP_REST_Response(
            ['success' => false, 'error' => 'Не удалось создать пост'], 
            500
        );
    }
    
    telegram_to_wp_log('Успешно создан пост с ID: ' . $post_id);
    
    // Обрабатываем медиа, если оно есть
    $media_info = [];
    if ($has_media) {
        $media_url = telegram_to_wp_get_media_url($message);
        if ($media_url) {
            $attachment_id = telegram_to_wp_handle_media($media_url, $post_id, $title);
            if ($attachment_id) {
                telegram_to_wp_log('Успешно загружен медиафайл с ID: ' . $attachment_id);
                $media_info = ['media_id' => $attachment_id];
                
                // Обновляем контент поста, добавляя тег изображения
                $image_html = wp_get_attachment_image($attachment_id, 'large');
                $updated_content = $image_html . "\n\n" . $content;
                
                wp_update_post([
                    'ID' => $post_id,
                    'post_content' => $updated_content
                ]);
            } else {
                telegram_to_wp_log('Ошибка: не удалось загрузить медиафайл');
            }
        }
    }
    
    // Отправляем подтверждение обратно в Telegram
    $chat_id = $message['chat']['id'];
    $response_text = 'Ваше сообщение успешно опубликовано на сайте! ID поста: ' . $post_id;
    
    if (defined('TELEGRAM_BOT_TOKEN')) {
        $telegram_api_url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
        $parameters = [
            'chat_id' => $chat_id,
            'text' => $response_text,
            'parse_mode' => 'HTML'
        ];
        
        $ch = curl_init($telegram_api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        
        telegram_to_wp_log('Отправлен ответ в Telegram: ' . $response_text);
    }
    
    // Возвращаем успешный ответ с информацией о созданном посте
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Пост успешно создан',
        'post_id' => $post_id,
        'post_url' => get_permalink($post_id),
        'media' => $media_info
    ], 200);
}

// Добавляем страницу настроек в административной панели
add_action('admin_menu', 'telegram_to_wp_menu');

function telegram_to_wp_menu() {
    add_options_page(
        'Настройки Telegram to WP',          // Заголовок страницы
        'Telegram to WP',                     // Название пункта меню
        'manage_options',                     // Необходимые права доступа
        'telegram-to-wp',                     // Уникальный идентификатор страницы
        'telegram_to_wp_options_page'         // Функция для отображения страницы
    );
}

function telegram_to_wp_options_page() {
    // Сохранение секретного токена
    if (isset($_POST['telegram_to_wp_save_settings'])) {
        if (isset($_POST['telegram_to_wp_secret_token'])) {
            update_option('telegram_to_wp_secret_token', sanitize_text_field($_POST['telegram_to_wp_secret_token']));
            echo '<div class="notice notice-success"><p>Настройки успешно сохранены.</p></div>';
        }
    }
    
    // Получаем сохраненный токен
    $secret_token = get_option('telegram_to_wp_secret_token', '');
    
    // Информация о боте
    $bot_token = defined('TELEGRAM_BOT_TOKEN') ? substr(TELEGRAM_BOT_TOKEN, 0, 8) . '...' : 'Не настроен';
    $bot_username = defined('TELEGRAM_BOT_USERNAME') ? '@' . TELEGRAM_BOT_USERNAME : 'Не настроен';
    
    // URL для установки webhook
    $webhook_url = rest_url('telegram/v1/post');
    $params = [];
    if (!empty($secret_token)) {
        $params['secret_token'] = $secret_token;
    }
    $webhook_params = !empty($params) ? '?' . http_build_query($params) : '';
    $full_webhook_url = $webhook_url . $webhook_params;
    $set_webhook_url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/setWebhook?url=' . urlencode($full_webhook_url);
    ?>
    <div class="wrap">
        <h1>Настройки интеграции Telegram</h1>
        
        <form method="post" action="">
            <div class="card">
                <h2>Информация о боте</h2>
                <p><strong>Токен бота:</strong> <?php echo esc_html($bot_token); ?></p>
                <p><strong>Имя бота:</strong> <?php echo esc_html($bot_username); ?></p>
            </div>
            
            <div class="card">
                <h2>Безопасность</h2>
                <p>
                    <label for="telegram_to_wp_secret_token"><strong>Секретный токен:</strong></label>
                    <input type="text" id="telegram_to_wp_secret_token" name="telegram_to_wp_secret_token" value="<?php echo esc_attr($secret_token); ?>" class="regular-text" />
                </p>
                <p class="description">Этот токен будет проверяться при получении сообщений от Telegram для дополнительной безопасности.</p>
                <p>
                    <input type="submit" name="telegram_to_wp_save_settings" class="button button-primary" value="Сохранить настройки" />
                </p>
            </div>
            
            <div class="card">
                <h2>Настройка Webhook</h2>
                <p>URL для приема сообщений (webhook):</p>
                <code><?php echo esc_html($webhook_url); ?></code>
                
                <?php if (!empty($secret_token)): ?>
                <p><strong>С включенной проверкой безопасности:</strong></p>
                <code><?php echo esc_html($full_webhook_url); ?></code>
                <?php endif; ?>
                
                <p>Чтобы настроить webhook для вашего бота, перейдите по следующей ссылке:</p>
                <p><a href="<?php echo esc_url($set_webhook_url); ?>" target="_blank">Установить webhook</a></p>
                <p><em>Примечание: Ваш сайт должен быть доступен из интернета и иметь SSL-сертификат (HTTPS).</em></p>
            </div>
        </form>
        
        <div class="card">
            <h2>Инструкции</h2>
            <p>1. Создайте бота в Telegram через <a href="https://t.me/BotFather" target="_blank">@BotFather</a>.</p>
            <p>2. Получите токен бота и укажите его в файле <code>bot-config.php</code>.</p>
            <p>3. <strong>Рекомендуется</strong>: настройте Секретный токен для дополнительной безопасности.</p>
            <p>4. Установите webhook, используя ссылку выше.</p>
            <p>5. Отправьте сообщение боту, и оно будет опубликовано на вашем сайте как новая запись.</p>
            <p>6. Плагин поддерживает обработку не только текстовых сообщений, но и медиафайлов:</p>
            <ul>
                <li>Фотографии</li>
                <li>Документы</li>
                <li>Видео</li>
                <li>Аудио</li>
            </ul>
        </div>
        
        <div class="card">
            <h2>Журнал активности</h2>
            <?php
            $log_file = WP_CONTENT_DIR . '/telegram-wp-log.txt';
            if (file_exists($log_file)) {
                $log_content = file_get_contents($log_file);
                echo '<pre style="max-height: 300px; overflow-y: scroll;">' . esc_html($log_content) . '</pre>';
            } else {
                echo '<p>Журнал пуст или не существует.</p>';
            }
            ?>
        </div>
    </div>
    <?php
}
?>
