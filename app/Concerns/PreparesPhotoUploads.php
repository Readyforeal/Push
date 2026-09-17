<?php

namespace App\Concerns;

use App\Services\PhotoStorage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

trait PreparesPhotoUploads
{
    /** @var array<string, bool> */
    public array $photoPreparationFailures = [];

    protected function preparePhotoUploads(string $property, PhotoStorage $photoStorage): void
    {
        $isMultiple = is_array($this->{$property});
        $uploads = $isMultiple ? $this->{$property} : [$this->{$property}];
        $prepared = [];
        $this->photoPreparationFailures[$property] = false;
        $this->resetValidation($property);

        foreach ($uploads as $upload) {
            if (! $upload instanceof TemporaryUploadedFile) {
                continue;
            }

            try {
                $prepared[] = $photoStorage->prepareForPreview($upload);
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
    }

    protected function photoPreparationFailed(string $property): bool
    {
        return $this->photoPreparationFailures[$property] ?? false;
    }
}
