<?php

use Bitrix\Main\Config\Configuration;

/**
 * Агент для автоматического забора файла с внешнего сервера по SSH.
 *
 * — Проверяет наличие нового файла.
 * — Загружает его при изменении.
 * — Сохраняет метаданные (mtime, size, дата).
 * — Защита от параллельного запуска.
 * — Запускает импорт через CLI Bitrix.
 * — Логирует действия и время выполнения.
 *
 * @return string Имя функции для повторного запуска
 */
function Sync_ImportFile_Agent(): string
{
    $start = microtime(true);
    $agentName = __FUNCTION__;

    try {
        // Читаем настройки из .settings_extra.php
        $settings = Configuration::getInstance()->get('sync_import');
        $remoteHost = $settings['remoteHost'];
        $remoteUser = $settings['remoteUser'];
        $remoteKey  = $settings['remoteKey'];
        $remotePath = $settings['remotePath'];

        $localDir  = $_SERVER['DOCUMENT_ROOT'] . '/upload/import_sync/';
        $localFile = $localDir . 'latest_export.zip';
        $localMeta = $localDir . 'import.meta.json';
        $lockFile  = $localDir . '.sync_import.lock';

        // Защита от параллельного запуска
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 600) {
            AddMessage2Log("{$agentName}: уже выполняется, пропускаем запуск.");
            return $agentName . '();';
        }
        file_put_contents($lockFile, date('Y-m-d H:i:s'));

        // Создаём директорию при необходимости
        if (!is_dir($localDir)) {
            $oldUmask = umask(0);
            mkdir($localDir, 0775, true);
            umask($oldUmask);
        }

        // Подключение к удалённому серверу
        $connection = ssh2_connect($remoteHost, 22);
        if (!$connection) {
            throw new \RuntimeException("Не удалось подключиться к {$remoteHost}");
        }

        // Авторизация по ключу
        if (!ssh2_auth_pubkey_file($connection, $remoteUser, $remoteKey . '.pub', $remoteKey)) {
            throw new \RuntimeException("Ошибка SSH-авторизации для {$remoteUser}@{$remoteHost}");
        }

        // Проверка удалённого файла
        $sftp = ssh2_sftp($connection);
        $remoteStat = stat("ssh2.sftp://{$sftp}{$remotePath}");
        if (!$remoteStat) {
            throw new \RuntimeException("Не найден удалённый файл {$remotePath}");
        }

        $remoteMTime = $remoteStat['mtime'];
        $remoteSize  = $remoteStat['size'] ?? 0;

        if ($remoteSize < 1024) {
            throw new \RuntimeException("Файл слишком мал ({$remoteSize} байт) — возможно ошибка экспорта.");
        }

        // Проверяем метаданные
        $localMTime = 0;
        if (file_exists($localMeta)) {
            $meta = json_decode(file_get_contents($localMeta), true);
            $localMTime = (int)($meta['mtime'] ?? 0);
        }

        // Если удалённый файл новее — обновляем
        if ($remoteMTime > $localMTime) {
            if (file_exists($localFile)) {
                rename($localFile, $localDir . 'backup_' . date('Ymd_His') . '.zip');
            }

            $remoteStream = fopen("ssh2.sftp://{$sftp}{$remotePath}", 'r');
            $localStream  = fopen($localFile, 'w');

            if (!$remoteStream || !$localStream) {
                throw new \RuntimeException('Ошибка открытия потоков при копировании.');
            }

            stream_copy_to_stream($remoteStream, $localStream);
            fclose($remoteStream);
            fclose($localStream);

            file_put_contents(
                $localMeta,
                json_encode([
                    'mtime' => $remoteMTime,
                    'size'  => $remoteSize,
                    'date'  => date('Y-m-d H:i:s'),
                ], JSON_PRETTY_PRINT)
            );

            AddMessage2Log("{$agentName}: Файл обновлён ({$remoteSize} байт).");

            // Запускаем CLI-импорт
            $phpPath = '/usr/bin/php';
            $documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
            $cliScript = $documentRoot . '/bitrix_cml_zip_cli.php';
            $logFile = $localDir . 'cli.log';

            $cmd = sprintf(
                '%s %s --base="%s" --zipfile="%s" >> %s 2>&1',
                escapeshellcmd($phpPath),
                escapeshellarg($cliScript),
                'https://example.com',    // обезличено
                $localFile,
                escapeshellarg($logFile)
            );

            exec($cmd, $output, $code);

            AddMessage2Log("{$agentName}: exec() код {$code}");
            AddMessage2Log("{$agentName}: exec() вывод: " . implode("\n", $output));
        } else {
            AddMessage2Log("{$agentName}: файл не изменился — обновление не требуется.");
        }
    } catch (\Throwable $e) {
        AddMessage2Log("{$agentName}: Ошибка — " . $e->getMessage());
    } finally {
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        $duration = round(microtime(true) - $start, 3);
        AddMessage2Log("{$agentName}: Выполнено за {$duration} сек.");
    }

    return $agentName . '();';
}
