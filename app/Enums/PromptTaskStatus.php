<?php

namespace App\Enums;

enum PromptTaskStatus: string
{
    case Locked = 'locked';
    case Active = 'active';
    case Submitted = 'submitted';
}
