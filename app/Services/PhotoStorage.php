<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class PhotoStorage
{
    public function __construct(private readonly ImageManager $images) {}

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

    public function temporaryThumbnailUrl(TemporaryUploadedFile $upload): string
    {
        $temporaryDirectory = storage_path('app/private/photo-tmp');
        File::ensureDirectoryExists($temporaryDirectory);
        $temporaryThumbnail = tempnam($temporaryDirectory, 'push-thumbnail-');

        if (! is_string($temporaryThumbnail)) {
            throw new RuntimeException('A temporary thumbnail could not be created.');
        }

        try {
            $thumbnail = $this->images
                ->fromUpload($upload)
                ->usingImagick()
                ->orient()
                ->scale(
                    width: $this->thumbnailDimension(),
                    height: $this->thumbnailDimension(),
                )
                ->toJpeg()
                ->quality($this->thumbnailQuality())
                ->toBytes();

            if (file_put_contents($temporaryThumbnail, $thumbnail, LOCK_EX) === false) {
                throw new RuntimeException('The thumbnail could not be written.');
            }

            $url = $this->stageTemporaryJpeg(
                $temporaryThumbnail,
                pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME).'-preview.jpg',
            )->temporaryUrl();

            return $this->sameOriginUrl($url);
        } catch (\Throwable $exception) {
            @unlink($temporaryThumbnail);

            throw new RuntimeException('The photo thumbnail could not be created.', previous: $exception);
        }
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

        $source = new Imagick;
        $photo = null;
        $embeddedRawPreview = null;

        try {
            if ($this->isRawUpload($upload)) {
                $embeddedRawPreview = $this->extractRawPreview($upload, $temporaryDirectory);
                $source->readImage("JPEG:{$embeddedRawPreview}[0]");
            } else {
                $this->readFirstImage($source, $upload);
            }

            $source->setIteratorIndex(0);
            $photo = clone $source->getImage();

            if (method_exists($photo, 'autoOrientImage')) {
                call_user_func([$photo, 'autoOrientImage']);
            } elseif (method_exists($photo, 'autoOrient')) {
                $photo->autoOrient();
            } elseif (method_exists($photo, 'autoOrientate')) {
                $photo->autoOrientate();
            }

            $maxDimension = $this->maximumDimension();

            if ($photo->getImageWidth() > $maxDimension || $photo->getImageHeight() > $maxDimension) {
                $photo->thumbnailImage($maxDimension, $maxDimension, true);
            }

            $photo->setImageBackgroundColor('white');
            $photo->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $photo->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            $photo->setImageDepth(8);
            $photo->setImageFormat('jpeg');
            $photo->setImageCompression(Imagick::COMPRESSION_JPEG);
            $photo->setImageCompressionQuality($this->jpegQuality());
            $photo->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            $photo->setOption('jpeg:optimize-coding', 'true');
            $photo->setOption('jpeg:sampling-factor', '2x2');
            $photo->stripImage();
            $photo->setImagePage(0, 0, 0, 0);
            $photo->writeImage($temporaryPath);

            return $temporaryPath;
        } catch (\Throwable $exception) {
            @unlink($temporaryPath);

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('This photo could not be converted to JPEG.', previous: $exception);
        } finally {
            $photo?->clear();
            $photo?->destroy();
            $source->clear();
            $source->destroy();
            if ($embeddedRawPreview) {
                @unlink($embeddedRawPreview);
            }
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

    private function maximumDimension(): int
    {
        return max(640, min(8192, (int) config('services.photo.max_dimension', 2560)));
    }

    private function jpegQuality(): int
    {
        return max(50, min(95, (int) config('services.photo.jpeg_quality', 82)));
    }

    private function thumbnailDimension(): int
    {
        return max(160, min(1280, (int) config('services.photo.thumbnail_dimension', 480)));
    }

    private function thumbnailQuality(): int
    {
        return max(40, min(90, (int) config('services.photo.thumbnail_quality', 68)));
    }

    private function sameOriginUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The thumbnail preview URL could not be created.');
        }

        $query = parse_url($url, PHP_URL_QUERY);

        return $path.(is_string($query) && $query !== '' ? "?{$query}" : '');
    }
}
