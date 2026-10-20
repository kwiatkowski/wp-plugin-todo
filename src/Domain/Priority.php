<?php

declare(strict_types=1);

namespace MKTodo\Domain;

enum Priority: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
