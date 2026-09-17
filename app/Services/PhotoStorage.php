<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ImageManagerInterface;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class PhotoStorage
{
    private readonly ImageManagerInterface $images;

    public function __construct()
    {
        $this->images = ImageManager::usingDriver(
            ImagickDriver::class,
            autoOrientation: true,
            decodeAnimation: false,
            backgroundColor: 'ffffff',
            strip: true,
        );
    }

    /**
     * Normalize every upload once while it is still in local temporary storage.
     * The resulting JPEG is both the submission payload and the final stored file.
     */
    public function prepareForPreview(TemporaryUploadedFile $upload): TemporaryUploadedFile
    {
        $this->assertFastConversionFormat($upload);

        $temporaryJpeg = $this->makeJpeg($upload);
        $prepared = $this->stageTemporaryJpeg($temporaryJpeg, $upload->getClientOriginalName());
        $sourceFilename = $upload->getFilename();
        $upload->delete();
        Storage::disk(FileUploadConfiguration::disk())->delete(FileUploadConfiguration::path($sourceFilename.'.json'));

        return $prepared;
    }

    private function stageTemporaryJpeg(string $temporaryJpeg, string $originalName): TemporaryUploadedFile
    {
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
                'name' => $originalName,
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
        if (! $this->isRawUpload($upload) && ! in_array(strtolower((string) $upload->getMimeType()), [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/heic',
            'image/heif',
            'image/tiff',
            'image/x-tiff',
        ], true)) {
            throw new RuntimeException('This image format could not be prepared as a JPEG.');
        }
    }

    private function isRawUpload(UploadedFile $upload): bool
    {
        return in_array(strtolower($upload->getClientOriginalExtension()), ['dng', 'raw'], true)
            || in_array(strtolower((string) $upload->getMimeType()), ['image/x-adobe-dng', 'image/dng'], true);
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

        $photo = null;
        $embeddedRawPreview = null;

        try {
            if ($this->isRawUpload($upload)) {
                try {
                    $photo = $this->decodeRawWithImageMagick($upload);
                } catch (\Throwable) {
                    $embeddedRawPreview = $this->extractRawPreview($upload, $temporaryDirectory);
                    $photo = $this->images->decodePath($embeddedRawPreview);
                }
            } else {
                $photo = $this->images->decodePath($upload->getRealPath());
            }

            $maxDimension = $this->maximumDimension();

            $photo
                ->removeAnimation()
                ->setBackgroundColor('ffffff')
                ->fillTransparentAreas()
                ->scaleDown(width: $maxDimension, height: $maxDimension)
                ->removeProfile()
                ->encode(new JpegEncoder(
                    quality: $this->jpegQuality(),
                    progressive: true,
                    strip: true,
                ))
                ->save($temporaryPath);

            return $temporaryPath;
        } catch (\Throwable $exception) {
            @unlink($temporaryPath);

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('This photo could not be converted to JPEG.', previous: $exception);
        } finally {
            unset($photo);
            if ($embeddedRawPreview) {
                @unlink($embeddedRawPreview);
            }
        }
    }

    private function decodeRawWithImageMagick(UploadedFile $upload): ImageInterface
    {
        $source = new Imagick;

        try {
            $source->setOption('dng:use-camera-wb', 'true');
            $source->readImage('DNG:'.$upload->getRealPath().'[0]');
            $source->setIteratorIndex(0);

            return $this->images->decode($source);
        } catch (\Throwable $exception) {
            $source->clear();
            $source->destroy();

            throw $exception;
        }
    }

    private function extractRawPreview(UploadedFile $upload, string $temporaryDirectory): string
    {
        $configuredBinary = (string) config('services.photo.exiftool_binary', 'exiftool');
        $binary = str_contains($configuredBinary, DIRECTORY_SEPARATOR)
            ? $configuredBinary
            : (new ExecutableFinder)->find($configuredBinary);

        if (! $binary || ! is_executable($binary)) {
            throw new RuntimeException('RAW preview extraction is not installed on this server.');
        }

        $previewPath = tempnam($temporaryDirectory, 'push-raw-preview-');

        if (! is_string($previewPath)) {
            throw new RuntimeException('A temporary RAW preview could not be created.');
        }

        $bestPixels = 0;

        try {
            foreach (['-PreviewImage', '-JpgFromRaw', '-OtherImage', '-ThumbnailImage'] as $tag) {
                $process = new Process([$binary, '-b', $tag, $upload->getRealPath()]);
                $process->setTimeout(30);
                $process->run();
                $contents = $process->isSuccessful() ? $process->getOutput() : '';
                $dimensions = $contents !== '' ? @getimagesizefromstring($contents) : false;

                if ($dimensions === false) {
                    continue;
                }

                $pixels = $dimensions[0] * $dimensions[1];

                if ($pixels > $bestPixels) {
                    if (file_put_contents($previewPath, $contents, LOCK_EX) === false) {
                        throw new RuntimeException('The extracted RAW preview could not be written.');
                    }

                    $bestPixels = $pixels;
                }

                if ($dimensions[0] >= 3000 || $dimensions[1] >= 3000) {
                    break;
                }
            }

            if ($bestPixels === 0 || filesize($previewPath) === 0) {
                throw new RuntimeException('This RAW photo does not contain a usable JPEG preview.');
            }

            return $previewPath;
        } catch (\Throwable $exception) {
            @unlink($previewPath);

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('The RAW photo preview could not be extracted.', previous: $exception);
        }
    }

    private function maximumDimension(): int
    {
        return max(640, min(8192, (int) config('services.photo.max_dimension', 2560)));
    }

    private function jpegQuality(): int
    {
        return max(50, min(95, (int) config('services.photo.jpeg_quality', 82)));
    }
}
