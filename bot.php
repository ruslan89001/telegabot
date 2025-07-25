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

    if ($data === 'crop') {
        $sizes = [
            ['1:1 (500x500)', 'crop_1_1'],
            ['4:3 (800x600)', 'crop_4_3'],
            ['16:9 (1280x720)', 'crop_16_9']
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
        if (file_exists($originalPath)) {
            $outputPath = __DIR__ . "/images/{$chatId}_grayscale.jpg";
            $img = $manager->read($originalPath)->greyscale();
            $img->save($outputPath);
            $telegram->sendPhoto([
                'chat_id' => $chatId,
                'photo' => fopen($outputPath, 'rb'),
                'caption' => 'Готово! Ч/Б изображение.'
            ]);
            unlink($outputPath);
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
        if (file_exists($originalPath)) {
            $outputPath = __DIR__ . "/images/{$chatId}_cropped.jpg";
            $img = $manager->read($originalPath);
            if ($data === 'crop_1_1') {
                $img = $img->cover(500, 500);
            } elseif ($data === 'crop_4_3') {
                $img = $img->cover(800, 600);
            } elseif ($data === 'crop_16_9') {
                $img = $img->cover(1280, 720);
            }
            $img->save($outputPath);
            $telegram->sendPhoto([
                'chat_id' => $chatId,
                'photo' => fopen($outputPath, 'rb'),
                'caption' => 'Готово! Кадрированное изображение.'
            ]);
            unlink($outputPath);
        } else {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Изображение не найдено. Пришлите его заново.'
            ]);
        }
        exit;
    }
    if (strpos($data, 'format_') === 0) {
        if (file_exists($originalPath)) {
            $format = str_replace('format_', '', $data);
            $ext = $format === 'jpg' ? 'jpeg' : strtolower($format);
            $outputPath = __DIR__ . "/images/{$chatId}_converted.$ext";
            $img = $manager->read($originalPath);
            $img->save($outputPath, 90, $ext);
            $telegram->sendDocument([
                'chat_id' => $chatId,
                'document' => fopen($outputPath, 'rb'),
                'caption' => 'Готово! Формат: ' . strtoupper($format)
            ]);
            unlink($outputPath);
        } else {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Изображение не найдено. Пришлите его заново.'
            ]);
        }
        exit;
    }
}

if ($update->getMessage() && $update->getMessage()->has('photo')) {
    $msg = $update->getMessage();
    $chatId = isset($msg['chat']['id']) ? $msg['chat']['id'] : null;
    if (!$chatId) exit;

    $photos = $msg['photo'] ?? [];
    if (!is_array($photos) || count($photos) === 0) exit;

    $lastPhoto = end($photos);
    $fileId = $lastPhoto['file_id'] ?? null;
    if (!$fileId) exit;

    $file = $telegram->getFile(['file_id' => $fileId]);
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
    if ($update->getMessage()) {
        $msg = $update->getMessage();
        $chatId = isset($msg['chat']['id']) ? $msg['chat']['id'] : null;
        if ($chatId) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пожалуйста, пришлите изображение.'
            ]);
        }
    }
}