<?php
// php index.php --url "site"
header('Content-Type: application/json');

const CHANNEL_PLATFORM_YOUTUBE = 0;
const CHANNEL_PLATFORM_WASD = 1;

$response = [];

// Получить аргументы из командой строки
$shortopts = '';
$longopts = [
    'url:'
];
$options = getopt($shortopts, $longopts);

if (array_key_exists('url', $options) && $options['url']) {
    // Передан url в командной строке
    $channels = [
        [
            'url' => $options['url']
        ]
    ];
}

foreach ($channels as $channel) {
    try {
        $channel['platform'] = Recorder::getChannelPlatform($channel);
        $channel = array_merge($channel, Recorder::getChannel($channel));
    } catch (Throwable $t) {
        $response['channels'][] = [
            'message' => 'Ошибка получения потока', 
            'error' => $t->getPrevious() ? $t->getPrevious()->getMessage() : $t->getMessage(), 
            'channel' => $channel
        ];
        continue;
    }

    if ($channel['isLive']) {
        $isRecording = Recorder::isRecording($channel);

        if (!$isRecording) {
            $filePath = Recorder::prepareFilePath($channel);
            $command = Recorder::recordToFile($filePath, $channel);
        }

        $response['channels'][] = [
            'message' => $isRecording ? 'Запись уже начата' : 'Запись начата', 
            'channel' => $channel, 
            'command' => $command ?? '', 
            'filePath' => $filePath ?? ''
        ];
    } else {
        $response['channels'][] = ['message' => 'Нет потока', 'channel' => $channel];
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

class Recorder {
    static function getChannel($channel) {
        switch ($channel['platform']) {
            case CHANNEL_PLATFORM_YOUTUBE:
                $channelInfo = Youtube::getChannel($channel);
                break;
            case CHANNEL_PLATFORM_WASD:
                $channelInfo = WASD::getChannel($channel);
                break;
        }

        return [
            'channelName' => $channelInfo['channelName'],
            'isLive'      => $channelInfo['isLive'],
            'streamId'    => $channelInfo['streamId'],
            'hlsUrl'      => $channelInfo['hlsUrl']
        ];
    }

    static function getChannelPlatform($channel) {
        if (stristr($channel['url'], 'youtube.com') || stristr($channel['url'], 'youtu.be')) {
            return CHANNEL_PLATFORM_YOUTUBE;
        }

        if (stristr($channel['url'], 'wasd.tv')) {
            return CHANNEL_PLATFORM_WASD;
        }
    }

    static function isLive($channel) {
        switch ($channel['platform']) {
            case CHANNEL_PLATFORM_YOUTUBE:
                $isLive = Youtube::isLive($channel);
                break;
            case CHANNEL_PLATFORM_WASD:
                $isLive = WASD::isLive($channel);
                break;
            default:
                $isLive = false;
                break;
        }

        return $isLive;
    }

    static function getHlsUrl($channel) {
        switch ($channel['platform']) {
            case CHANNEL_PLATFORM_YOUTUBE:
                $hlsUrl = Youtube::getHlsUrl($channel);
                break;
            case CHANNEL_PLATFORM_WASD:
                $hlsUrl = WASD::getHlsUrl($channel);
                break;
        }

        return $hlsUrl;
    }

    static function getStreamId($channel) {
        switch ($channel['platform']) {
            case CHANNEL_PLATFORM_YOUTUBE:
                $streamId = Youtube::getVideoId($channel);
                break;
            case CHANNEL_PLATFORM_WASD:
                $streamId = WASD::getStreamId($channel);
                break;
        }

        return $streamId;
    }

    static function prepareFilePath($channel) {
        $fileDir = Recorder::prepareFileDir($channel);
        $fileName = Recorder::prepareFileName($channel);
        $filePath = $fileDir . DIRECTORY_SEPARATOR . $fileName;

        return $filePath;
    }

    static function prepareFileDir($channel) {
        $fileDir = implode(DIRECTORY_SEPARATOR, [__DIR__, 'channels', $channel['channelName']]);
        if (!is_dir($fileDir)) {
            mkdir($fileDir, 777, true);
        }
        return $fileDir;
    }

    static function prepareFileName($channel) {
        //$fileName = Recorder::filterFilename((new DateTime())->format('Y-m-d'));
        //$fileName = Recorder::filterFilename($channel['videoId']);
        $fileName = Recorder::filterFilename(
            (new DateTime())->format('Y-m-d') . ' ' . 
            (array_key_exists('streamId', $channel) ? $channel['streamId'] : '')
        );
        $extension = Recorder::getFileExtension();
        return $fileName . '.' . $extension;
    }

    /**
     * Отфильтровать символы имени файла для файловой системы
     */
    static function filterFilename($filename, $beautify = true) {
        // sanitize filename
        $filename = preg_replace('/[^\w]/u', '-', $filename);
        // avoids ".", ".." or ".hiddenFiles"
        $filename = ltrim($filename, '.-');
        // optional beautification
        if ($beautify) $filename = Recorder::beautifyFilename($filename);
        // maximize filename length to 255 bytes http://serverfault.com/a/9548/44086
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $filename = mb_strcut(pathinfo($filename, PATHINFO_FILENAME), 0, 255 - ($ext ? strlen($ext) + 1 : 0), mb_detect_encoding($filename)) . ($ext ? '.' . $ext : '');
        return $filename;
    }
    
    /**
     * Сократить символы имени файла для файловой системы
     */
    static function beautifyFilename($filename) {
        // reduce consecutive characters
        $filename = preg_replace([
            // "file   name.zip" becomes "file-name.zip"
            '/ +/',
            // "file___name.zip" becomes "file-name.zip"
            '/_+/',
            // "file---name.zip" becomes "file-name.zip"
            '/-+/'
        ], '-', $filename);
        $filename = preg_replace([
            // "file--.--.-.--name.zip" becomes "file.name.zip"
            '/-*\.-*/',
            // "file...name..zip" becomes "file.name.zip"
            '/\.{2,}/'
        ], '.', $filename);
        // lowercase for windows/unix interoperability http://support.microsoft.com/kb/100625
        $filename = mb_strtolower($filename, mb_detect_encoding($filename));
        // ".file-name.-" becomes "file-name"
        $filename = trim($filename, '.-');
        return $filename;
    }

    /**
     * Задать расширение файла
     * 
     * - ts для потокового видео
     * - mp4 меньше весит
     * @return string $extension
     */
    static function getFileExtension() {
        return 'ts';
    }

    static function isRecording($channel) {
        // Имя файла для запуска записи
        $pid = $channel['streamId'];
        // Имена файлов запущенных процессов
        $openedProcess = @file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'ffmpeg.pid');

        $isRecording = stripos($openedProcess, $pid) !== false;

        return $isRecording;
    }

    /**
     * Запустить запись в файл
     * - Запустить ffmpeg для сохранения в файл
     *
     * @param string $filePath
     * @param array $channel
     * @return string $command
     */
    static function recordToFile($filePath, $channel, $options = null) {
        $ffmpegPath = implode(DIRECTORY_SEPARATOR, [__DIR__, 'ffmpeg', 'bin', 'ffmpeg.exe']);

        // Настройки ffmpeg
        if (!$options) {
            $options = [
                '-y',                    // Перезапись файла без подтверждения
                '-vsync 0',              // От потери кадров (но это не точно)
                '-hwaccel cuvid',        // Аппаратное ускорение, аргумент должен быть перед входным потоком -i
                '-c:v h264_cuvid',       // Раскодирование видео на NVDEC
                "-i \"{$channel['hlsUrl']}\"",  // Входной поток
                '-c:a copy',             // Не перекодировать звук
                '-c:v hevc_nvenc',       // Кодировать поток на NVENC (hevc_nvenc или h264_nvenc)
                '-rc vbr',               // Переменный битрейт для -b и -maxrate
                '-minrate 1M -b:v 2M -maxrate:v 6M -bufsize:v 8M', // Степень сжатия от 1МБ до 8МБ
                //'-crf 16',             // Степень сжатия (14-17 хорошее качество)
                //'-filter:v scale=1920x1080:flags=lanczos', // Ограничить разрешение
                '-filter:v fps=fps=30',  // Ограничить до 30 кадров в секунду
                '-preset fast',          // Качество сжатия
                '-profile:v main',       // Поддержка телефонов
                //'-tune ll',
                '-movflags +faststart',  // Воспроизведение во время скачивания (перемещается moov атомы в начало файла)
                "\"{$filePath}\""
            ];
        }

        $command = "start /min {$ffmpegPath} " . implode(' ', $options);

        // Запустить процесс
        popen($command, 'r');
        // Сохранить m3u8
        // file_put_contents($filePath . '.m3u8', file_get_contents($channel['hlsUrl']));
        // Записать в ffmpeg.pid имя файла запущенный процесс
        file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'ffmpeg.pid', $channel['streamId'] . PHP_EOL, FILE_APPEND);

        return $command;
    }
}

class Youtube {
    static function getChannel($channel) {
        $channel['channelName'] = end(explode('/', $channel['url']));

        // Получить постоянную страницу стрима
        $liveStreamUrl = Youtube::getStaticURL($channel);
        if ($data = @file_get_contents($liveStreamUrl))
        {
            // Получить videoId
            preg_match('/\"VIDEO_ID\":\"(.*?)\"/', $data, $matches);
            // Если стрим есть, то VIDEO_ID содержит videoId стрима, равно live_stream
            $channel['isLive'] = isset($matches[1]) && $matches[1] !== 'live_stream';
            $channel['streamId'] = (isset($matches[1]) && $matches[1] !== 'live_stream') ? $matches[1] : '';
            $channel['hlsUrl'] = $channel['isLive'] ? Youtube::getHlsUrl($channel) : '';
        } else {
            // Альтернативно
            $channel['isLive'] = Youtube::isLive($channel);
            $channel['streamId'] = Youtube::getVideoId($channel);
            $channel['hlsUrl'] = Youtube::getHlsUrl($channel);
        }

        return [
            'channelName' => $channel['channelName'],
            'isLive' => $channel['isLive'],
            'streamId' => $channel['streamId'],
            'hlsUrl' => $channel['hlsUrl']
        ];
    }

    static function isLive($channel) {
        // Получить постоянную страницу стрима
        $liveStreamUrl = Youtube::getStaticURL($channel);
        if ($data = @file_get_contents($liveStreamUrl))
        {
            // Получить videoId
            preg_match('/\"VIDEO_ID\":\"(.*?)\"/', $data, $matches);
            // Если стрим есть, то VIDEO_ID содержит videoId стрима, равно live_stream
            $isLive = isset($matches[1]) && $matches[1] !== 'live_stream';
            return $isLive;
        } else {
            throw new Exception("Ошибка загрузки постоянной страницы стрима: {$liveStreamUrl}");
        }
    }

    static function getStaticURL($channel) {
        return "https://www.youtube.com/embed/live_stream?channel={$channel['channelName']}";
    }

    /**
     * Получить videoId стрима через channelId
     * - Получить VIDEO_ID в скрипте js
     * 
     * @param array $channel
     * @return string $videoId
     */
    static function getVideoId($channel) {
        // Получить постоянную страницу стрима
        $liveStreamUrl = Youtube::getStaticURL($channel);
        if ($data = @file_get_contents($liveStreamUrl))
        {
            // Получить videoId
            preg_match('/\"VIDEO_ID\":\"(.*?)\"/', $data, $matches);
            // Если стрим есть, то VIDEO_ID содержит videoId стрима, иначе значение будет равно live_stream
            $videoId = (isset($matches[1]) && $matches[1] !== 'live_stream') ? $matches[1] : '';
            return $videoId;
        } else {
            throw new Exception("Ошибка загрузки постоянной страницы стрима: {$liveStreamUrl}");
        }
    }

    /**
     * Получить hlsUrl
     *
     * @param array $channel
     * @return array $hls 
     */
    static function getHlsUrl($channel)
    {
        try {
            $videoUrl = "https://www.youtube.com/watch?v={$channel['streamId']}";
            $videoData = file_get_contents($videoUrl);
        } catch (Throwable $t) {
            throw new Exception("Не удалось получить информацию о видео (альтернативно): {$videoUrl}", 0, $t);
        }

        // Получить hlsManifestUrl
        preg_match('/\"hlsManifestUrl\":\"(.*?)\"/', $videoData, $matches);
        $hlsUrl = (isset($matches[1]) && strpos($matches[1], '.m3u8')) ? $matches[1] : '';

        if (!$hlsUrl) {
            // Получить hlsUrl альтернативно
            try {
                // Получить информацию о видео
                $videoData = Youtube::getVideoData($channel['streamId']);
                if ($videoData && $videoData['videoDetails'] && $videoData['videoDetails']['isLive']) {
                    $hlsUrl = $videoData['streamingData']['hlsManifestUrl'];
                }
            } catch (Throwable $t) {
                throw new Exception('Не удалось получить информацию HLS', 0, $t);
            }
        }

        return $hlsUrl;
    }

    /**
     * Получить информацию о видео
     * - Ограниченное количество запросов API
     * 
     * @param string $videoId
     * @return array $videoData
     */
    static function getVideoData($videoId) {
        try {
            parse_str(@file_get_contents("https://youtube.com/get_video_info?video_id={$videoId}&html5=1&c=TVHTML5&cver=6.20180913"), $videoInfo);
            $videoData = json_decode($videoInfo['player_response'] ?? null, true);
            return $videoData;
        } catch (Throwable $t) {
            throw new Exception('Не удалось получить информацию о видео', 0, $t);
        }
    }
}

class WASD {
    static function getChannel($channel) {
        $channel['channelName'] = end(explode('/', $channel['url']));

        if (array_key_exists('url', $channel) && $channel['url']) {
            $apiURL = "https://wasd.tv/api/v2/broadcasts/public?channel_name={$channel['channelName']}";
        }
        if (array_key_exists('channelId', $channel) && $channel['channelId']) {
            $apiURL = "https://wasd.tv/api/v2/broadcasts/public?channel_id={$channel['channelId']}";
        }
        if ($apiInfo = @file_get_contents($apiURL)) {
            $apiInfo = json_decode($apiInfo, true)['result'];
            $channel['channelName'] = $apiInfo['channel']['channel_name'];
            $channel['isLive'] = $apiInfo['channel']['channel_is_live'];
            $channel['streamId'] = $apiInfo['media_container'] ? $apiInfo['media_container']['media_container_streams'][0]['stream_id'] : '';
            $channel['hlsUrl'] = $apiInfo['media_container'] ? $apiInfo['media_container']['media_container_streams'][0]['stream_media'][0]['media_meta']['media_url'] : '';
        } else {
            // Альтернативно
            $channel['isLive'] = WASD::isLive($channel);
            $channel['streamId'] = WASD::getStreamId($channel);
            $channel['hlsUrl'] = $channel['isLive'] ? WASD::getHlsUrl($channel) : '';
        }

        return [
            'channelName' => $channel['channelName'],
            'isLive' => $channel['isLive'],
            'streamId' => $channel['streamId'],
            'hlsUrl' => $channel['hlsUrl']
        ];
    }

    static function isLive($channel) {
        // Проверка через HLS
        $hlsUrl = WASD::getHlsUrl($channel);
        if ($hlsUrl) {
            $hls = @file_get_contents($hlsUrl);
        }

        /**
         * @var array $http_response_header materializes out of thin air
         */
        if (!isset($http_response_header) || !$http_response_header) {
            return false;
        }

        $status_line = $http_response_header[0];
        preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);
        $status = $match[1];

        return $status === 200;
    }

    /**
     * Получить hlsUrl
     *
     * @param array $channel
     * @return array $hls 
     */
    static function getHlsUrl($channel)
    {
        if (array_key_exists('user_id', $channel)) {
            return "https://cdn.wasd.tv/live/{$channel['user_id']}/index.m3u8";
        }
    }

    static function getStreamId($channel) {
        $channel['channelName'] = end(explode('/', $channel['url']));

        if (array_key_exists('url', $channel) && $channel['url']) {
            $apiURL = "https://wasd.tv/api/v2/broadcasts/public?channel_name={$channel['channelName']}";
        }
        if (array_key_exists('channelId', $channel) && $channel['channelId']) {
            $apiURL = "https://wasd.tv/api/v2/broadcasts/public?channel_id={$channel['channelId']}";
        }
        if ($apiInfo = @file_get_contents($apiURL)) {
            $apiInfo = json_decode($apiInfo, true)['result'];
            return $apiInfo['media_container'] ? $apiInfo['media_container']['media_container_streams'][0]['stream_id'] : $channel['channelName']; // @TODO
        } else {
            return $channel['channelName']; // @TODO
        }
    }
}