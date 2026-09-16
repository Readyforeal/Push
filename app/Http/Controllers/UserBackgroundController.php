<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserBackgroundController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->background_image_path, 404);
        $disk = $user->background_image_disk ?: 'local';
        abort_unless(Storage::disk($disk)->exists($user->background_image_path), 404);

        return Storage::disk($disk)->response(
            $user->background_image_path,
            basename($user->background_image_path),
            [
                'Cache-Control' => 'private, max-age=3600',
                'Content-Type' => $user->background_image_mime_type ?? 'application/octet-stream',
            ],
        );
    }
}
