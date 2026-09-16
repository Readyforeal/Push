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
        abort_unless(Storage::disk('local')->exists($user->background_image_path), 404);

        return Storage::disk('local')->response(
            $user->background_image_path,
            basename($user->background_image_path),
            [
                'Cache-Control' => 'private, max-age=3600',
                'Content-Type' => $user->background_image_mime_type ?? 'application/octet-stream',
            ],
        );
    }
}
