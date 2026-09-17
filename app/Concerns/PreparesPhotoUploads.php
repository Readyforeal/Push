<?php

namespace App\Concerns;

use App\Services\PhotoStorage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

trait PreparesPhotoUploads
{
    /** @var array<string, bool> */
    public array $photoPreparationFailures = [];

    /** @var array<string, array<int, string>> */
    public array $photoPreviewUrls = [];

    protected function preparePhotoUploads(string $property, PhotoStorage $photoStorage): void
    {
        $isMultiple = is_array($this->{$property});
        $uploads = $isMultiple ? $this->{$property} : [$this->{$property}];
        $prepared = [];
        $previewUrls = [];
        $this->photoPreparationFailures[$property] = false;
        $this->photoPreviewUrls[$property] = [];
        $this->resetValidation($property);

        foreach ($uploads as $upload) {
            if (! $upload instanceof TemporaryUploadedFile) {
                continue;
            }

            try {
                $preparedUpload = $photoStorage->prepareForPreview($upload);
                $prepared[] = $preparedUpload;
                $previewUrls[] = $photoStorage->temporaryThumbnailUrl($preparedUpload);
            } catch (\Throwable $exception) {
                report($exception);
                $this->photoPreparationFailures[$property] = true;
                $this->addError(
                    $property,
                    __('“:name” could not be prepared as a JPEG. Choose it again or try another copy.', [
                        'name' => $upload->getClientOriginalName(),
                    ]),
                );
            }
        }

        $this->{$property} = $isMultiple ? $prepared : ($prepared[0] ?? null);
        $this->photoPreviewUrls[$property] = $previewUrls;
    }

    protected function photoPreparationFailed(string $property): bool
    {
        return $this->photoPreparationFailures[$property] ?? false;
    }

    protected function clearPhotoPreviews(string $property): void
    {
        $this->photoPreviewUrls[$property] = [];
    }
}
