# Sync Import Agent (Bitrix)

Автоматическая синхронизация ZIP-файла (например, `import.xml.zip`) с удалённого сервера по SSH и запуск импорта через CLI Bitrix. Проект обезличен: все IP, домены и реальные пути заменены на примеры/плейсхолдеры.

---

## Что делает агент

1. Подключается к удалённому серверу по **SSH-ключу**.
2. Проверяет, что удалённый файл существует и валиден (размер, mtime).
3. Скачивает свежую версию в `upload/import_sync/latest_export.zip`.
4. Сохраняет метаданные (`import.meta.json`).
5. Запускает импортер Bitrix `bitrix_cml_zip_cli.php` с корректной `--base` и `--zipfile`.
6. Пишет логи через `AddMessage2Log()` и в `upload/import_sync/cli.log`.
7. Защищается от параллельных запусков через lock-файл.

---

## Требования

* PHP 8.x с расширением **ssh2** (или альтернатива: phpseclib 3 — см. ниже).
* Bitrix (коробка), доступ к админке/серверу.
* Доступ по SSH к удалённому серверу (пользователь с правом чтения целевого файла).
* Возможность создавать файлы в `upload/`.

---

## Дерево/пути (пример)

> Замените на ваши реальные пути/домены.
> Для примеров используется `/var/www/project/public_html`.

```
/var/www/project/public_html/
│
├─ bitrix/
├─ local/
│  └─ php_interface/
│     └─ includes/
│        └─ agents.php      # функция Sync_ImportFile_Agent()
│
└─ upload/
   └─ import_sync/
      ├─ latest_export.zip
      ├─ import.meta.json
      ├─ cli.log
      └─ .sync_import.lock
```

---

## Установка

### 1) SSH-ключ для подключения к удалённому серверу

```bash
ssh-keygen -t rsa -b 4096 -f /home/bitrix/.ssh/import_sync_key
ssh-copy-id -i /home/bitrix/.ssh/import_sync_key.pub <remote_user>@<remote_host>
# проверка
ssh -i /home/bitrix/.ssh/import_sync_key <remote_user>@<remote_host> "ls -l /path/to/exports/"
```

Рекомендуемые права:

```bash
chmod 700 /home/bitrix/.ssh
chmod 600 /home/bitrix/.ssh/import_sync_key
chmod 644 /home/bitrix/.ssh/import_sync_key.pub
```

> **Примечание по безопасности:** храните ключ в недоступном для всех месте. При необходимости вынесите в `/etc/ssh/sync_keys` и дайте доступ группе.

---

### 2) Конфигурация Bitrix (`.settings_extra.php`)

`/var/www/project/public_html/bitrix/.settings_extra.php`:

```php
<?php
return [
    'sync_import' => [
        'value' => [
            'remoteHost' => '<remote_host>',                    // пример: sftp.example.net
            'remoteUser' => '<remote_user>',                    // пример: deployer
            'remoteKey'  => '/home/bitrix/.ssh/import_sync_key',
            'remotePath' => '/path/to/exports/import.xml.zip',  // или конкретный архив в каталоге
        ],
    ],
];
```

> Если на удалённой стороне файлы имеют шаблон (`export_*.zip`), можно доработать агент для выбора последнего по дате (см. “Варианты расширения”).

---

### 3) Файлы агента

Разместите функцию в, например,
`/var/www/project/public_html/local/php_interface/includes/agents.php`.

Функция называется **`Sync_ImportFile_Agent()`** и *должна* возвращать строку с собственным именем для повторного запуска:

```php
return __FUNCTION__ . '();';
```

(Готовая версия функции предполагается уже в репозитории.)

---

### 4) Директория для синхронизации

```bash
mkdir -p /var/www/project/public_html/upload/import_sync
chown bitrix:bitrix /var/www/project/public_html/upload/import_sync
chmod 775 /var/www/project/public_html/upload/import_sync
```

---

### 5) Включить расширение ssh2 (если используете ssh2)

Проверьте в `phpinfo()` раздел **ssh2 support = enabled**.
Иначе установите через пакетный менеджер/меню BitrixVM и перезапустите httpd/nginx.

Альтернатива без расширения — **phpseclib 3**:

```bash
cd /var/www/project/public_html/local
composer require phpseclib/phpseclib:^3.0
```

(Тогда используйте реализацию через `phpseclib\Net\SFTP` и `PublicKeyLoader` — в репозитории можно держать обе версии.)

---

## Регистрация агента

### Вариант A — через админку (рекомендуется)

Админка → **Настройки → Инструменты → Список агентов** → “Добавить”:

| Поле                    | Значение                                       |
| ----------------------- | ---------------------------------------------- |
| Имя функции             | `Sync_ImportFile_Agent();`                     |
| Модуль                  | `main`                                         |
| Периодический           | `Нет`                                          |
| Интервал (сек)          | `86400`                                        |
| Дата первой проверки    | (пусто)                                        |
| Активен                 | `Да`                                           |
| Дата следующего запуска | `дд.мм.гггг чч:мм:сс` (например, завтра 19:00) |
| Сортировка              | `100`                                          |

> Формат даты **строго** `d.m.Y H:i:s` (например, `13.11.2025 19:00:00`).

### Вариант B — программно

```php
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
CModule::IncludeModule('main');

CAgent::AddAgent(
    'Sync_ImportFile_Agent();',        // функция
    'main',                            // модуль
    'N',                               // не периодический
    86400,                             // раз в сутки
    '',                                // date check
    'Y',                               // активен
    date('d.m.Y H:i:s', strtotime('+1 day 19:00')), // первый запуск
    100
);
```

---

## Режим выполнения агентов

Рекомендуется режим **через cron**:

* Админка → **Настройки продукта → Автоматизация → Агенты** → “Агенты выполняются через cron”.
* В crontab пользователя веб-сайта (обычно `bitrix`) должна быть строка:

```bash
*/5 * * * * /usr/bin/php -f /var/www/project/public_html/bitrix/modules/main/tools/cron_events.php > /dev/null 2>&1
```

Проверка:

```bash
sudo -u bitrix crontab -l
```

Ручной запуск:

```bash
sudo -u bitrix php -f /var/www/project/public_html/bitrix/modules/main/tools/cron_events.php
```

---

## После загрузки — что выполнять

После успешного скачивания архива агент может не только запускать внешний CLI-скрипт через `exec`, но и вызвать любую функцию, статический метод класса или выполнить PHP-код. Это даёт гибкость: можно запускать встроенный импорт, вызывать воркеры, триггерить хуки и т.п.

Ниже — безопасные и практичные варианты выполнения:

### 1) Вызов внешнего CLI (уже есть)

Один из безопасных и простых вариантов — запуск CLI-скрипта и логирование вывода:

```php
$phpPath    = '/usr/bin/php';
$cliScript  = $documentRoot . '/bitrix_cml_zip_cli.php';
$logFile    = $localDir . 'cli.log';

$cmd = sprintf(
    '%s %s --base=%s --zipfile=%s >> %s 2>&1',
    escapeshellcmd($phpPath),
    escapeshellarg($cliScript),
    escapeshellarg('https://example.com'), // обезличено
    escapeshellarg($localFile),
    escapeshellarg($logFile)
);

exec($cmd, $output, $code);
AddMessage2Log("Exec code: {$code}");
AddMessage2Log("Exec output: " . implode("\n", $output));
```

**Плюсы:** изолированность, можно смотреть stdout/stderr в лог-файле.
**Минусы:** работа через shell — следи за `escapeshellarg()` и правами.

---

### 2) Вызов локальной функции/метода PHP (без shell)

Если импорт реализован в виде функции/метода в кодовой базе — вызывай его напрямую (быстрее и безопаснее):

```php
// пример: функция из другого файла
require_once $documentRoot . '/local/php_interface/import_handlers.php';

// вызываем функцию импорта, передаём путь к файлу
try {
    $result = my_local_import_function($localFile);
    AddMessage2Log("Local import finished: " . var_export($result, true));
} catch (\Throwable $e) {
    AddMessage2Log("Local import error: " . $e->getMessage());
}
```

Или статический метод класса:

```php
try {
    \VProger\Core\Importer::runFromZip($localFile);
    AddMessage2Log("Importer::runFromZip completed");
} catch (\Throwable $e) {
    AddMessage2Log("Importer error: " . $e->getMessage());
}
```

**Плюсы:** безопасно, исключения ловятся внутри PHP, нет лишнего shell-уровня.
**Минусы:** код выполняется в том же процессе, что и агент — контролируй таймауты/память.

---

### 3) Выполнение небольшого PHP-скрипта через CLI (`php -r` или отдельный файл)

Если нужно изолировать выполнение (другой пользователь, другое окружение), положи файл-обёртку и запусти его через `php`:

```php
// build safe command
$wrapper = $documentRoot . '/local/tools/import_wrapper.php';
$cmd = sprintf('%s %s %s >> %s 2>&1',
    escapeshellcmd('/usr/bin/php'),
    escapeshellarg($wrapper),
    escapeshellarg($localFile),        // аргумент: путь к файлу
    escapeshellarg($localDir . 'cli.log')
);

exec($cmd, $output, $code);
AddMessage2Log("Wrapper exec code: {$code}");
```

`import_wrapper.php` — простой CLI-скрипт, который подключает минимальный bootstrap и вызывает нужную функцию. Такой подход позволяет запускать от имени другого системного пользователя через `sudo -u` в crontab, если нужно.

**Плюсы:** изоляция, можно запускать под другим пользователем/с правами.
**Минусы:** нужно обеспечить безопасный wrapper и валидацию входных аргументов.

---

### 4) Запуск произвольного кода через `proc_open()` (для сложной коммуникации)

Если нужно читать/писать stdin/stdout процесса — используй `proc_open()` с явным описанием потоков. Это даёт больше контроля, но сложнее в эксплуатации. Пример здесь опущён для краткости — использовать только если действительно нужно.

---

## Рекомендации по безопасности при выполнении кода

1. **Никогда не формируй команду с пользовательским вводом** без строгой валидации. Всегда использовать `escapeshellcmd()`/`escapeshellarg()` при shell-вызовах.
2. **Предпочти локальный вызов PHP-функции/метода** (вариант 2) — он безопаснее и проще для отладки.
3. **Используй wrapper-скрипт**, если нужно выполнение в отдельном процессе/пользователе. Wrapper должен:

   * иметь фиксированный набор аргументов;
   * валидировать путь к файлу (реальный `realpath()` и проверка, что файл внутри ожидаемой директории);
   * логировать и возвращать коды ошибок.
4. **Логируй вывод и код возврата** (`$output`, `$code`) — это помогает быстро понять, что пошло не так.
5. **Ограничь права**: если используешь ключи, положи их в защищённую папку и выставь права (owner/group) — не делай ключ общедоступным.
6. **Не запускай непроверенный чужой код** — если wrapper загружает внешний плагин, проверяй подпись/хэши.
7. **Обрабатывай исключения** и всегда удаляй lock-файл в `finally` (как у тебя уже реализовано).

---

## Пример: выбор между exec и локальным вызовом в агенте

```php
// после успешного скачивания:
$useLocal = true;

if ($useLocal) {
    // надёжный локальный вызов
    try {
        \VProger\Core\Importer::runFromZip($localFile);
        AddMessage2Log("Local import OK");
    } catch (\Throwable $e) {
        AddMessage2Log("Local import failed: ".$e->getMessage());
    }
} else {
    // запуск wrapper'а в отдельном процессе
    $wrapper = $documentRoot . '/local/tools/import_wrapper.php';
    $cmd = sprintf('%s %s %s >> %s 2>&1',
        escapeshellcmd('/usr/bin/php'),
        escapeshellarg($wrapper),
        escapeshellarg($localFile),
        escapeshellarg($localDir . 'cli.log')
    );
    exec($cmd, $output, $code);
    AddMessage2Log("Wrapper exit {$code}: ".implode("\n", $output));
}
```

---

## Логи и отладка

* `AddMessage2Log()` → смотрите в системном логе Bitrix (если настроен `LOG_FILENAME` в `dbconn.php`)
* Лог CLI-импорта: `/upload/import_sync/cli.log`
* Также у функции пишется финальное время выполнения и статусы.

Полезно добавить в `dbconn.php`:

```php
define('LOG_FILENAME', $_SERVER['DOCUMENT_ROOT'].'/bitrix/php_interface/debug.log');
```

И смотреть:

```bash
tail -n 200 /var/www/project/public_html/bitrix/php_interface/debug.log
```

---

## Безопасность

* Используйте отдельного пользователя на удалённом сервере (например, `deployer`) с доступом **только на чтение** к каталогу экспорта.
* В `authorized_keys` можно ограничить ключ: `from="IP_сайта",no-agent-forwarding,no-port-forwarding,no-pty` и/или `command="cat /path/to/file.zip"`.
* Не храните пароли в коде. Все параметры — через `.settings_extra.php`.
* Блокируйте параллельные запуски (в функции уже есть `.sync_import.lock`).
* Проверяйте размер файла (встроено) и разумность путей.

---

## Типичные проблемы

* **Нет ssh2 в Apache**, но есть в CLI → установите/подключите модуль в конфиге Apache-PHP; проверьте через `phpinfo()`.
* **Неверный путь к `prolog_before.php` при регистрациях из CLI** → используйте абсолютные пути сайта.
* **Неверный формат даты при `CAgent::AddAgent`** → только `d.m.Y H:i:s`.
* **Нет прав на приватный ключ у процесса веб-сервера** → храните ключ там, где у веб-пользователя есть права *чтения* (желательно через группу, не 0644 всем).

---

## Варианты расширения

* Скачивать **последний по дате** архив из каталога экспорта вместо жёсткого имени файла.
* Предварительно **очищать** локальную папку (только внутри неё) безопасной функцией.
* Резервировать предыдущую копию (`.bak`) N дней.
* Оповещения в Telegram/Slack при ошибках и успешной загрузке.
* Версия без `ssh2` — через **phpseclib** (SFTP), чтобы не зависеть от системного расширения.

---

## Лицензия

MIT / BSD-2 — на ваш выбор. Проект-скелет без привязки к конкретной инфраструктуре.

---

## Отказ от ответственности

Все домены, IP-адреса и пути в репозитории — примеры. Перед деплоем замените плейсхолдеры на значения вашего окружения и убедитесь в соответствии политикам безопасности вашей компании.
