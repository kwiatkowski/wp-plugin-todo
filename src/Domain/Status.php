<?php

declare(strict_types=1);

namespace MKTodo\Domain;

/** Kanban column of a task; `done` is always `status === Done`. */
enum Status: string
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Done = 'done';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
