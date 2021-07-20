# StreamRecorder
Для работы необходим ffmpeg в папке вместе с index.php

Использование:
- php index.php --url "https://www.youtube.com/watch?v=videoId"

Параметры:
- -- url "site"

При записи:
- Создаётся папка channels, куда происходит запись видеофайла.
- Создаётся запись в ffmpeg.pid, содержащую videoId/streamId, чтобы запись не запускалась повторно.
