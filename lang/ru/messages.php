<?php

return [

    // Console output
    'console' => [
        'starting_service' => 'Запуск сервиса :description...',
        'service_completed' => 'Выполнение сервиса завершено',
        'job_already_in_queue' => 'Job уже в очереди, теги:',
        'terminate_confirm' => 'Завершить активные job-ы?',
        'force_terminating' => 'Принудительное завершение активных job-ов',
        'process_killed' => 'Процесс PID :pid убит (:uuid)',
        'removed_from_queue' => 'Удалён из очереди: :uuid',
        'marked_interrupted' => 'Отмечен как прерванный: :uuid',
        'error_terminating' => 'Ошибка завершения job :uuid: :error',
        'horizon_terminate_confirm' => 'Будет вызван horizon:terminate для остановки обработки job-ов (затронет все воркеры). Продолжить?',
        'horizon_restart_cancelled' => 'Перезапуск Horizon отменён, некоторые job-ы могут продолжать работу',
        'restarting_horizon' => 'Перезапуск Horizon...',
        'job_not_found' => 'Job с UUID :uuid не найден в очереди :queue',
    ],

    // Table headers (dumpJobs)
    'table' => [
        'status' => 'Статус',
        'uuid' => 'UUID',
        'queue' => 'Очередь',
        'connection' => 'Подключение',
        'queued' => 'В очереди с',
        'started' => 'Начат',
    ],

    // MoonShine resource
    'resource' => [
        'title' => 'Управление Command Schedule Job',
        'service' => 'Сервис',
        'frequency' => 'Частота',
        'last_run' => 'Последний запуск',
        'next_run' => 'Следующий запуск',
        'scheduler' => 'Планировщик',
        'general' => 'Основное',
        'service_class' => 'Класс сервиса',
        'description' => 'Описание',
        'arguments' => 'Аргументы',
        'scheduler_enabled' => 'Планировщик включён',
        'without_overlapping_job' => 'Защита от дублирования задач',
        'console_args' => 'Аргументы консоли',
        'console_args_hint' => 'напр. --force --param=value',
        'scheduler_enabled_filter' => 'Планировщик включён',
        'command' => 'Команда',
    ],

    // Frequency descriptions
    'frequency' => [
        'everySecond' => 'Каждую секунду',
        'everyMinute' => 'Каждую минуту',
        'everyTwoMinutes' => 'Каждые 2 минуты',
        'everyThreeMinutes' => 'Каждые 3 минуты',
        'everyFourMinutes' => 'Каждые 4 минуты',
        'everyFiveMinutes' => 'Каждые 5 минут',
        'everyTenMinutes' => 'Каждые 10 минут',
        'everyFifteenMinutes' => 'Каждые 15 минут',
        'everyThirtyMinutes' => 'Каждые 30 минут',
        'hourly' => 'Каждый час',
        'everyTwoHours' => 'Каждые 2 часа',
        'everyThreeHours' => 'Каждые 3 часа',
        'everyFourHours' => 'Каждые 4 часа',
        'everySixHours' => 'Каждые 6 часов',
        'daily' => 'Ежедневно',
        'dailyAt' => 'Ежедневно в указанное время',
        'twiceDaily' => 'Дважды в день',
        'twiceDailyAt' => 'Дважды в день в указанное время',
        'weekly' => 'Еженедельно',
        'weeklyOn' => 'Еженедельно в указанный день',
        'monthly' => 'Ежемесячно',
        'monthlyOn' => 'Ежемесячно в указанную дату',
        'lastDayOfMonth' => 'Последний день месяца',
        'quarterly' => 'Ежеквартально',
        'yearly' => 'Ежегодно',
        'yearlyOn' => 'Ежегодно в указанную дату',
    ],

    // Validation
    'validation' => [
        'unknown_frequency' => 'Неизвестная частота: :frequency',
        'invalid_args_count' => 'Требуется аргументов: :expected, передано: :actual',
    ],

];
