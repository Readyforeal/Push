<?php

namespace App\Enums;

enum PromptRoundKind: string
{
    case UniqueQuestions = 'unique_questions';
    case SharedQuestion = 'shared_question';
    case PhotoPicker = 'photo_picker';
    case PhotoRequest = 'photo_request';
}
