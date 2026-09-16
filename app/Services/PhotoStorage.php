<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use RuntimeException;

class PhotoStorage
{
    /**
     * @return array{path: string, mime_type: string, size: int}
     */
    public function store(UploadedFile $upload, string $directory, string $disk): array
    {
        if (! $this->requiresConversion($upload)) {
            if (@getimagesize($upload->getRealPath()) === false) {
                throw new RuntimeException('This file could not be read as an image.');
            }

            $path = $upload->store($directory, $disk);

            if (! is_string($path)) {
                throw new RuntimeException('The photo could not be stored.');
            }

            return [
                'path' => $path,
                'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
                'size' => $upload->getSize(),
            ];
        }

        return $this->convertToJpeg($upload, $directory, $disk);
    }

    private function requiresConversion(UploadedFile $upload): bool
    {
        return in_array(strtolower($upload->getClientOriginalExtension()), [
            'dng',
            'heic',
            'heif',
            'tif',
            'tiff',
        ], true);
    }

    /**
     * @return array{path: string, mime_type: string, size: int}
     */
    private function convertToJpeg(UploadedFile $upload, string $directory, string $disk): array
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('RAW photo conversion is not available on this server.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'push-photo-');

        if (! is_string($temporaryPath)) {
            throw new RuntimeException('A temporary photo could not be created.');
        }

        $source = new Imagick;
        $photo = null;

        try {
            $source->readImage($upload->getRealPath());
            $source->setIteratorIndex(0);
            $photo = clone $source->getImage();

            if (method_exists($photo, 'autoOrientImage')) {
                call_user_func([$photo, 'autoOrientImage']);
            } elseif (method_exists($photo, 'autoOrient')) {
                $photo->autoOrient();
            } elseif (method_exists($photo, 'autoOrientate')) {
                $photo->autoOrientate();
            }

            if ($photo->getImageWidth() > 4096 || $photo->getImageHeight() > 4096) {
                $photo->thumbnailImage(4096, 4096, true);
            }

            $photo->setImageBackgroundColor('white');
            $photo->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $photo->setImageFormat('jpeg');
            $photo->setImageCompression(Imagick::COMPRESSION_JPEG);
            $photo->setImageCompressionQuality(92);
            $photo->stripImage();
            $photo->writeImage($temporaryPath);

            $path = trim($directory, '/').'/'.Str::uuid().'.jpg';
            $stream = fopen($temporaryPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException('The converted photo could not be read.');
            }

            try {
                Storage::disk($disk)->writeStream($path, $stream);
            } finally {
                fclose($stream);
            }

            return [
                'path' => $path,
                'mime_type' => 'image/jpeg',
                'size' => filesize($temporaryPath) ?: 0,
            ];
        } catch (\ImagickException $exception) {
            throw new RuntimeException('This RAW photo could not be converted.', previous: $exception);
        } finally {
            $photo?->clear();
            $photo?->destroy();
            $source->clear();
            $source->destroy();
            @unlink($temporaryPath);
        }
    }
}
