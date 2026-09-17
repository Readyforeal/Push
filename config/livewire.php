<?php

return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK', 'local'),
        'rules' => ['required', 'file', 'max:512000'],
        'directory' => 'livewire-tmp',
        'middleware' => 'throttle:60,1',
        'preview_mimes' => ['jpg', 'jpeg'],
        'max_upload_time' => 30,
        'cleanup' => true,
    ],
];
