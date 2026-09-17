<?php

namespace App\Enums;

enum PromptPhotoRequirement: string
{
    case None = 'none';
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Both = 'both';

    public function includesPrimary(): bool
    {
        return in_array($this, [self::Primary, self::Both], true);
    }

    public function includesSecondary(): bool
    {
        return in_array($this, [self::Secondary, self::Both], true);
    }
}
