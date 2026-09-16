<?php

namespace App\Http\Controllers;

use App\Models\SharedMomentPhoto;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SharedMomentPhotoController
{
    public function __invoke(SharedMomentPhoto $sharedMomentPhoto): StreamedResponse
    {
        Gate::authorize('view', $sharedMomentPhoto);

        abort_unless(Storage::disk($sharedMomentPhoto->disk)->exists($sharedMomentPhoto->path), 404);

        return Storage::disk($sharedMomentPhoto->disk)->response(
            $sharedMomentPhoto->path,
            $sharedMomentPhoto->original_name,
            ['Content-Type' => $sharedMomentPhoto->mime_type ?? 'application/octet-stream'],
        );
    }
}
