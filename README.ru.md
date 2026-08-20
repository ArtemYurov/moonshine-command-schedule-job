# MoonShine Command Schedule Job

[![PHP](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11|12|13-red.svg)](https://laravel.com)
[![MoonShine](https://img.shields.io/badge/MoonShine-4.x-purple.svg)](https://moonshine-laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)

**[English version (README.md)](README.md)**

**Один класс = artisan-команда + планировщик + queue job + админка.**

Опишите один класс-сервис — пакет сам зарегистрирует artisan-команду и запись в планировщике, а управлять расписанием на лету можно из админ-панели MoonShine.

---

## Быстрый старт

```php
class SyncAccountData extends CommandScheduleJobService
{
    protected string $commandSignature = 'sync:account-data {--account-id=}';
    protected string $commandDescription = 'Sync account data from external API';
    protected ?string $scheduleFrequency = 'everyFiveMinutes';
    protected ?string $jobClass = SyncAccountDataJob::class;

    protected function handle(array $params = []): void
    {
        $account = Account::findOrFail($params['account-id']);
        $this->dispatchJob($account);
    }
}
```

```bash
php artisan sync:account-data --account-id=42
```


---

## Установка

```bash
composer require artemyurov/moonshine-command-schedule-job
php artisan migrate
```

Публикация конфига (необязательно):

```bash
php artisan vendor:publish --tag=command-schedule-job-config
```

---

## Возможности

- **Консоль + планировщик** — каждый сервис автоматически становится artisan-командой и записью в планировщике
- **Queue jobs** — задайте `$jobClass`, чтобы диспатчить Job в очередь со встроенной дедупликацией
- **Админка MoonShine** — включение планировщика, настройка частоты, время запусков, копирование команд
- **Генератор** — `php artisan make:command-schedule-job-service MyService`
- **Авто-обнаружение** — сканирует указанные директории на предмет классов-сервисов

---

## Регистрация сервисов

Сервисы собираются из трёх источников (объединяются, дубликаты отбрасываются):

### 1. Авто-обнаружение

Сканирует указанные директории на неабстрактные классы, наследующие `CommandScheduleJobService`:

```php
// config/command-schedule-job.php
'discovery' => [
    'paths' => ['app/Services/'],
    'namespaces' => ['App\\Services'],
],
```

### 2. Регистрация в конфиге

Явное перечисление классов-сервисов:

```php
// config/command-schedule-job.php
'services' => [
    \App\Services\MyCustomService::class,
],
```

### 3. Программная регистрация (для пакетов)

Регистрация сервисов из `ServiceProvider` пакета:

```php
use ArtemYurov\CommandScheduleJob\CommandScheduleJobServiceRegistry;

class MyPackageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(CommandScheduleJobServiceRegistry::class)
            ->register(MyPackageSyncService::class);
    }
}
```

Все три источника объединяются через `CommandScheduleJobServiceRegistry`.

---

## Свойства сервиса

| Свойство | Тип | По умолчанию | Описание |
|----------|-----|--------------|----------|
| `$commandSignature` | `string` | *обязательно* | Сигнатура artisan-команды |
| `$commandDescription` | `string` | *обязательно* | Описание команды |
| `$scheduleFrequency` | `?string` | `null` | Метод расписания (`daily`, `hourly`, `everyFiveMinutes` и т.д.) |
| `$scheduleFrequencyArgs` | `?array` | `null` | Аргументы метода частоты |
| `$scheduleConsoleArgs` | `?string` | `null` | Дополнительные консольные аргументы для запуска по расписанию |
| `$jobClass` | `?string` | `null` | Класс Job для диспатча (`null` = синхронное выполнение) |
| `$schedulable` | `bool` | `true` | Флаг возможности, только в коде. При `false` сервис никогда не попадает в планировщик (нет записи в cron, поля расписания скрыты в админке) — но `php artisan <command>` работает |
| `$shouldBeUniqueJob` | `bool` | `false` | Dispatch-guard: перед диспатчем отбросить или перехватить уже активный Job с совпадающими тегами |
| `$shouldBeUniqueJobExpiresAfter` | `?int` | `null` | Насколько старым может быть активный Job, прежде чем dispatch-guard перестанет её учитывать — секунды от `queued_at` (`null` = значение из конфига) |
| `$withoutOverlappingJob` | `bool` | `false` | Middleware уровня исполнения: сериализовать запуск, наткнувшийся на активного соседа (release/retry) |
| `$withoutOverlappingJobReleaseAfter` | `?int` | `null` | Задержка релиза у overlap-middleware, секунды (`null` = значение из конфига) |
| `$withoutOverlappingJobDontRelease` | `bool` | `false` | Поведение при наложении, только в коде: `false` = сериализация (release/retry), `true` = отбросить наложившийся запуск вместо ожидания |
| `$withoutOverlappingJobExpiresAfter` | `?int` | `null` | Насколько старым может быть запуск, прежде чем overlap-middleware спишет его — секунды от `started_at`; порог протухания для JobLog-варианта, TTL лока для нативного. Поднимается до `timeout + 60s`, если Job его переживёт (`null` = значение из конфига) |

### Принцип хранения

```
БД = вкл/выкл + когда          код = как + как долго
```

- **БД (правится в админке):** расписание (`schedule_enabled`, `frequency`, `frequency_args`, `schedule_console_args`) плюс dispatch-guard (`should_be_unique_job`).
- **Код (разработчик, структурное):** тайминги, защита от наложения целиком (`$withoutOverlappingJob` и его ручки), выбор своей или нативной middleware и `$schedulable`. Колонок в БД у них не будет.

Терпит ли задача параллельный запуск — свойство самой задачи, а не режим работы, поэтому `$withoutOverlappingJob` живёт только в коде; см. примечание в разделе «Конфигурация».

### Контракт защиты от наложения

В режиме сериализации Job, наткнувшийся на активного соседа, **возвращается в очередь** и повторяется, а это расходует попытку. Значит ей нужен запас: `public function retryUntil(): \DateTime` (предпочтительно — заодно ограничивает ожидание) либо `public int $tries > 1`, иначе первый же релиз израсходует единственную попытку и запуск потеряется.

Обе настройки — задержка релиза и срок списания — доезжают до той middleware, которая выбрана: JobLog-варианта, когда установлен `moonshine-db-joblog` и Job — `Loggable`, иначе нативного. Смысл срока при этом разный:

- **JobLog-вариант** — занятость означает живой процесс, поэтому срок работает лишь порогом протухания для строк в `PROCESSING`: он не даёт потерянной служебной записи заклинить тег навсегда.
- **Нативный вариант** — срок и ЕСТЬ TTL лока, поэтому переживший его Job потерял бы лок на середине.

В обоих случаях Job, чей собственный `timeout` переживает настроенное значение, автоматически поднимает срок до `timeout + 60s` с предупреждением, называющим Job, — так что настроенное значение должно покрывать только Job без собственного `timeout`.

Dispatch-guard игнорирует записи в `PROCESSING`, чей воркер уже не существует, — иначе крах заставлял бы его пропускать тики всё окно поиска.

---

## Постановка набора

`dispatchJob(...$args)` ставит один Job; `batch()` собирает набор в нативный `PendingBatch` с теми же флагами на каждом Job. Передаются **items, а не Job** — класс всегда `$jobClass` (обязан использовать `Batchable`), item — его первый аргумент конструктора, `$args` идут следом.

```php
$batch = LoadPaymentsService::batch($accounts, $dispatchSync, [$dryRun])   // снаружи
    ?->name('load payments')
    ->allowFailures()
    ->dispatch();

Service::make()->setSomething(...)->newBatch($items, $args);   // когда сперва нужны сеттеры
$this->newBatch($items, $args);                                // внутри собственного handle() сервиса
```

`null` означает, что делать больше нечего: sync уже выполнил Job инлайн, вход был пуст либо guard отсеял все item'ы. В sync батч не создаётся, поэтому такие Job не несут `batch_id`, а навешенные по цепочке коллбэки не сработают.

У `$dispatchSync` нет дефолта, потому что статик поднимает *новый* сервис. Дальше три отличия от собранного вручную `Bus::batch`: занятый тег выбрасывает **только этот item**, а не весь набор (и не терминирует держащий тег Job); очередь проставлена заранее из `$queue` первого Job, потому что батч идёт мимо диспетчера; ждать — забота вызывающего, нативный батч тоже не умеет.

---

## Конфигурация

```php
// config/command-schedule-job.php
return [
    'services' => [],
    'discovery' => [
        'paths' => ['app/Services/'],
        'namespaces' => ['App\\Services'],
    ],
    'table' => 'command_schedule_jobs',
    // How old an active job may be before the dispatch guard ignores it (from queued_at).
    'default_should_be_unique_job_expires_after' => 3 * 60 * 60,   // 3 hours
    // Overlap middleware release delay (seconds) in serialize mode.
    'default_without_overlapping_job_release_after' => 10,      // 10 seconds
    // How old a run may be before it is written off as broken (from started_at):
    // staleness cap for the JobLog variant, lock TTL for the native one. Raised
    // automatically when a job's own timeout would outlast it.
    'default_without_overlapping_job_expires_after' => 60 * 60,  // 1 hour
];
```

> **Удалено в 2.1.0.** Колонки `without_overlapping_job` больше нет, `$withoutOverlappingJob`
> читается только из кода. Остальные ручки механизма и так жили там, поэтому в БД оставался
> только выключатель — а `findOrCreateForService()` не пересеивал существующую строку, из-за
> чего свойство в коде могло молча расходиться с продом. Строка с выключенным тумблером
> получит ту защиту, которую объявляет её код.

> **Переименовано в 2.0.1.** `default_should_be_unique_job_expires_at` и
> `default_without_overlapping_job_expires_at` стали `…_expires_after`, вместе с
> соответствующими свойствами сервиса `$…ExpiresAt`, геттерами и сеттерами. Оба всегда хранили
> длительность в секундах, так что суффикс `_at` вводил в заблуждение. Если конфиг был
> опубликован — переименуйте ключи: непереименованный молча уедет на дефолты пакета.

---

## Необязательные зависимости

| Пакет | Что даёт |
|-------|----------|
| `laravel/horizon` | Поиск и управление Job в очереди |
| `artemyurov/moonshine-db-joblog` | Точное завершение Job по PID и JobLog-вариант overlap-middleware |
| `ext-posix` | Завершение процессов по PID и отбрасывание протухших записей в dispatch-guard |

Базовая функциональность работает и без них. С ними дедупликация и завершение Job работают точнее.

---

## Требования

- PHP 8.2+
- Laravel 11, 12 или 13
- MoonShine 4.x

## Лицензия

MIT
