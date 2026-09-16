<?php

namespace App\Enums;

enum AppBackgroundMode: string
{
    case Auto = 'auto';
    case None = 'none';
    case Photo = 'photo';
    case Upload = 'upload';
}
