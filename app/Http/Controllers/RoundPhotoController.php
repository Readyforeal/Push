<?php

namespace App\Http\Controllers;

use App\Models\RoundPhoto;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RoundPhotoController
{
    public function __invoke(RoundPhoto $roundPhoto): StreamedResponse
    {
        Gate::authorize('view', $roundPhoto);

        abort_unless(Storage::disk($roundPhoto->disk)->exists($roundPhoto->path), 404);

        return Storage::disk($roundPhoto->disk)->response(
            $roundPhoto->path,
            $roundPhoto->original_name,
            [
                'Cache-Control' => 'private, max-age=604800, immutable',
                'Content-Type' => $roundPhoto->mime_type ?? 'application/octet-stream',
            ],
        );
    }
}
