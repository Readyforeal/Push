<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class PhotoStorage
{
    /**
     * Convert a browser-incompatible upload while it is still in Livewire's
     * local temporary storage so the component can show a real JPEG preview.
     */
    public function prepareForPreview(TemporaryUploadedFile $upload): TemporaryUploadedFile
    {
        if (! $this->requiresConversion($upload)) {
            $this->assertReadableImage($upload);

            return $upload;
        }

        $this->assertFastConversionFormat($upload);

        $temporaryJpeg = $this->makeJpeg($upload);
        $disk = FileUploadConfiguration::disk();
        $storage = Storage::disk($disk);
        $filename = Str::random(40).'.jpg';
        $path = FileUploadConfiguration::path($filename);
        $metadataPath = $path.'.json';
        $stream = fopen($temporaryJpeg, 'rb');

        if ($stream === false) {
            @unlink($temporaryJpeg);

            throw new RuntimeException('The prepared photo could not be read.');
        }

        try {
            if (! $storage->writeStream($path, $stream)) {
                throw new RuntimeException('The prepared JPEG could not be written.');
            }

            if (! $storage->put($metadataPath, json_encode([
                'name' => $upload->getClientOriginalName(),
                'type' => 'image/jpeg',
                'size' => filesize($temporaryJpeg) ?: 0,
                'hash' => $filename,
            ], JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('The prepared JPEG metadata could not be written.');
            }
        } catch (\Throwable $exception) {
            $storage->delete([$path, $metadataPath]);

            throw new RuntimeException('The prepared photo could not be saved.', previous: $exception);
        } finally {
            fclose($stream);
            @unlink($temporaryJpeg);
        }

        $sourceFilename = $upload->getFilename();
        $upload->delete();
        $storage->delete(FileUploadConfiguration::path($sourceFilename.'.json'));

        return TemporaryUploadedFile::createFromLivewire($filename);
    }

    /**
     * @return array{path: string, mime_type: string, size: int}
     */
    public function store(UploadedFile $upload, string $directory, string $disk): array
    {
        if (! $this->requiresConversion($upload)) {
            $this->assertReadableImage($upload);

            $path = $upload->store($directory, $disk);

            if (! is_string($path)) {
                throw new RuntimeException('The photo could not be stored.');
            }

            return [
                'path' => $path,
                'mime_type' => 'image/jpeg',
                'size' => $upload->getSize(),
            ];
        }

        $this->assertFastConversionFormat($upload);

        return $this->convertAndStore($upload, $directory, $disk);
    }

    private function requiresConversion(UploadedFile $upload): bool
    {
        return $upload->getMimeType() !== 'image/jpeg';
    }

    private function assertReadableImage(UploadedFile $upload): void
    {
        if (@getimagesize($upload->getRealPath()) === false) {
            throw new RuntimeException('This file could not be read as an image.');
        }
    }

    private function assertFastConversionFormat(UploadedFile $upload): void
    {
        if (! in_array(strtolower((string) $upload->getMimeType()), [
            'image/png',
            'image/gif',
            'image/webp',
        ], true)) {
            throw new RuntimeException('Choose this image from Photo Library so your device can prepare a compatible JPEG.');
        }
    }

    /**
     * @return array{path: string, mime_type: string, size: int}
     */
    private function convertAndStore(UploadedFile $upload, string $directory, string $disk): array
    {
        $temporaryPath = $this->makeJpeg($upload);
        $path = trim($directory, '/').'/'.Str::uuid().'.jpg';
        $stream = fopen($temporaryPath, 'rb');

        if ($stream === false) {
            @unlink($temporaryPath);

            throw new RuntimeException('The converted photo could not be read.');
        }

        try {
            if (! Storage::disk($disk)->writeStream($path, $stream)) {
                throw new RuntimeException('The converted photo could not be stored.');
            }

            return [
                'path' => $path,
                'mime_type' => 'image/jpeg',
                'size' => filesize($temporaryPath) ?: 0,
            ];
        } finally {
            fclose($stream);
            @unlink($temporaryPath);
        }
    }

    private function makeJpeg(UploadedFile $upload): string
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('Photo conversion is not available on this server.');
        }

        $temporaryDirectory = storage_path('app/private/photo-tmp');
        File::ensureDirectoryExists($temporaryDirectory);
        $temporaryPath = tempnam($temporaryDirectory, 'push-photo-');

        if (! is_string($temporaryPath)) {
            throw new RuntimeException('A temporary photo could not be created.');
        }

        $source = new Imagick;
        $photo = null;

        try {
            $source->setOption('dng:use-camera-wb', 'true');
            $this->readFirstImage($source, $upload);
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
            $photo->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            $photo->setImageFormat('jpeg');
            $photo->setImageCompression(Imagick::COMPRESSION_JPEG);
            $photo->setImageCompressionQuality(90);
            $photo->stripImage();
            $photo->writeImage($temporaryPath);

            return $temporaryPath;
        } catch (\ImagickException $exception) {
            @unlink($temporaryPath);

            throw new RuntimeException('This photo could not be converted to JPEG.', previous: $exception);
        } finally {
            $photo?->clear();
            $photo?->destroy();
            $source->clear();
            $source->destroy();
        }
    }

    private function readFirstImage(Imagick $source, UploadedFile $upload): void
    {
        $path = $upload->getRealPath();
        $extension = strtolower($upload->getClientOriginalExtension());
        $mimeType = strtolower((string) $upload->getMimeType());
        $decoder = match ($mimeType) {
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            'image/gif' => 'GIF',
            'image/webp' => 'WEBP',
            'image/heic', 'image/heif' => 'HEIC',
            'image/tiff', 'image/x-tiff' => 'TIFF',
            default => null,
        };
        $compatibleExtensions = match ($mimeType) {
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'image/heic', 'image/heif' => ['heic', 'heif'],
            'image/tiff', 'image/x-tiff' => ['tif', 'tiff', 'dng'],
            default => [],
        };

        if ($decoder !== null && ! in_array($extension, $compatibleExtensions, true)) {
            $source->readImage("{$decoder}:{$path}[0]");

            return;
        }

        try {
            $source->readImage($path.'[0]');
        } catch (\ImagickException $exception) {
            $fallbackDecoder = $decoder ?? ($extension === 'dng' ? 'TIFF' : null);

            if ($fallbackDecoder === null) {
                throw $exception;
            }

            $source->clear();
            $source->setOption('dng:use-camera-wb', 'true');
            $source->readImage("{$fallbackDecoder}:{$path}[0]");
        }
    }
}
