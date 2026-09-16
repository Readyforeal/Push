<?php

namespace App\Enums;

enum PromptTaskKind: string
{
    case Question = 'question';
    case PhotoUpload = 'photo_upload';
    case PhotoPick = 'photo_pick';
}
