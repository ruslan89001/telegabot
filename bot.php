<?php
require __DIR__ . '/vendor/autoload.php';

use Telegram\Bot\Api;
use Intervention\Image\ImageManager;

$botToken = getenv('TELEGRAM_BOT_TOKEN');
if (!$botToken && file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env');
    foreach ($lines as $line) {
        if (strpos(trim($line), 'TELEGRAM_BOT_TOKEN=') === 0) {
            $botToken = trim(explode('=', $line, 2)[1]);
            break;
        }
    }
}
if (!$botToken) {
    http_response_code(500);
    echo 'Bot token not set in .env';
    exit;
}

$telegram = new Api($botToken);
$manager = new ImageManager(\Intervention\Image\Drivers\Imagick\Driver::class);

$update = $telegram->getWebhookUpdate();

if ($update->getCallbackQuery()) {
    $callback = $update->getCallbackQuery();
    $msg = $callback->getMessage();
    $chatId = isset($msg['chat']['id']) ? $msg['chat']['id'] : null;
    if (!$chatId) exit;
    $data = $callback->getData();
    $originalPath = __DIR__ . "/images/{$chatId}_original";
    $tempPath = __DIR__ . "/images/{$chatId}_temp";

    $showNextActions = function($telegram, $chatId) {
        $keyboard = [
            [
                ['text' => 'Кадрировать', 'callback_data' => 'crop'],
                ['text' => 'Ч/Б', 'callback_data' => 'grayscale'],
                ['text' => 'Формат', 'callback_data' => 'format']
            ],
            [
                ['text' => '✅ Готово', 'callback_data' => 'done']
            ]
        ];
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Можете применить ещё действие или завершить обработку:',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    };

    if ($data === 'done') {
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
        if (file_exists($originalPath)) {
            unlink($originalPath);
        }
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Обработка завершена! Пришлите новое изображение.'
        ]);
        exit;
    }

    $sourcePath = file_exists($tempPath) ? $tempPath : $originalPath;

    if ($data === 'crop') {
        $sizes = [
            ['1:1', 'crop_1_1'],
            ['4:3', 'crop_4_3'],
            ['16:9', 'crop_16_9']
        ];
        $keyboard = [
            [
                ['text' => $sizes[0][0], 'callback_data' => $sizes[0][1]],
                ['text' => $sizes[1][0], 'callback_data' => $sizes[1][1]],
                ['text' => $sizes[2][0], 'callback_data' => $sizes[2][1]]
            ]
        ];
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Выберите размер для кадрирования:',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        exit;
    } elseif ($data === 'grayscale') {
        if (file_exists($sourcePath)) {
            $img = $manager->read($sourcePath)->greyscale();
            $img->save($tempPath);
            $telegram->sendPhoto([
                'chat_id' => $chatId,
                'photo' => fopen($tempPath, 'rb'),
                'caption' => 'Готово! Ч/Б изображение.'
            ]);
            $showNextActions($telegram, $chatId);
        } else {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Изображение не найдено. Пришлите его заново.'
            ]);
        }
        exit;
    } elseif ($data === 'format') {
        $formats = [
            ['PNG', 'format_png'],
            ['JPG', 'format_jpg'],
            ['TIFF', 'format_tiff']
        ];
        $keyboard = [
            [
                ['text' => $formats[0][0], 'callback_data' => $formats[0][1]],
                ['text' => $formats[1][0], 'callback_data' => $formats[1][1]],
                ['text' => $formats[2][0], 'callback_data' => $formats[2][1]]
            ]
        ];
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Выберите формат для сохранения:',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        exit;
    }
    if (strpos($data, 'crop_') === 0) {
        if (file_exists($sourcePath)) {
            $img = $manager->read($sourcePath);
            $originalWidth = $img->width();
            $originalHeight = $img->height();

            if ($data === 'crop_1_1') {
                $size = min($originalWidth, $originalHeight);
                $img = $img->cover($size, $size);
                $caption = "Соотношение сторон 1:1";
            }
            elseif ($data === 'crop_4_3') {
                $width = $originalWidth;
                $height = $width * (3/4);
                $img = $img->cover($width, $height);
                $caption = "Соотношение сторон 4:3";
            }
            elseif ($data === 'crop_16_9') {
                $width = $originalWidth;
                $height = $width * (9/16);
                $img = $img->cover($width, $height);
                $caption = "Соотношение сторон 16:9";
            }

            $img->save($tempPath);
            $telegram->sendPhoto([
                'chat_id' => $chatId,
                'photo' => fopen($tempPath, 'rb'),
                'caption' => "Готово! " . $caption
            ]);
            $showNextActions($telegram, $chatId);
        } else {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Изображение не найдено. Пришлите его заново.'
            ]);
        }
        exit;
    }
    if (strpos($data, 'format_') === 0) {
        if (file_exists($sourcePath)) {
            $format = str_replace('format_', '', $data);
            $ext = $format === 'jpg' ? 'jpeg' : strtolower($format);
            $outputPath = __DIR__ . "/images/{$chatId}_result.$ext";
            $img = $manager->read($sourcePath);
            $img->save($outputPath);
            $telegram->sendDocument([
                'chat_id' => $chatId,
                'document' => fopen($outputPath, 'rb'),
                'caption' => 'Готово! Формат: ' . strtoupper($format)
            ]);
            unlink($outputPath);
            $showNextActions($telegram, $chatId);
        } else {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Изображение не найдено. Пришлите его заново.'
            ]);
        }
        exit;
    }
}

if ($update->getMessage()) {
    $msg = $update->getMessage();
    $chatId = isset($msg['chat']['id']) ? $msg['chat']['id'] : null;
    if ($chatId) {
        $tempPath = __DIR__ . "/images/{$chatId}_temp";
        $originalPath = __DIR__ . "/images/{$chatId}_original";
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
        if (file_exists($originalPath)) {
            unlink($originalPath);
        }
    }

    $getFileInfo = function($message) {
        if ($message->has('photo')) {
            $photos = $message['photo'] ?? [];
            if (!is_array($photos) || count($photos) === 0) return null;
            $lastPhoto = end($photos);
            return [
                'file_id' => $lastPhoto['file_id'] ?? null,
                'mime_type' => 'image/jpeg'
            ];
        } elseif ($message->has('document')) {
            $doc = $message['document'] ?? [];
            $mime_type = $doc['mime_type'] ?? '';
            if (strpos($mime_type, 'image/') === 0) {
                return [
                    'file_id' => $doc['file_id'] ?? null,
                    'mime_type' => $mime_type
                ];
            }
        }
        return null;
    };

    $fileInfo = $getFileInfo($msg);
    if ($fileInfo && $fileInfo['file_id']) {
        $chatId = isset($msg['chat']['id']) ? $msg['chat']['id'] : null;
        if (!$chatId) exit;

        $file = $telegram->getFile(['file_id' => $fileInfo['file_id']]);
        $filePath = $file['file_path'];
        $url = "https://api.telegram.org/file/bot$botToken/$filePath";
        $localPath = __DIR__ . "/images/{$chatId}_original";
        file_put_contents($localPath, file_get_contents($url));

        $keyboard = [
            [
                ['text' => 'Кадрировать', 'callback_data' => 'crop'],
                ['text' => 'Ч/Б', 'callback_data' => 'grayscale'],
                ['text' => 'Формат', 'callback_data' => 'format']
            ]
        ];
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Выберите действие с изображением:',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        exit;
    } else {
        if ($msg->has('chat')) {
            $chatId = $msg['chat']['id'];
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пожалуйста, пришлите изображение (можно как фото или как файл).'
            ]);
        }
    }
}