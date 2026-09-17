<?php

use App\Models\PageVisit;
use App\Models\Relationship;
use App\Services\DailyPromptScheduler;
use App\Services\PromptReminderScheduler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('prompts:schedule {--force : Ignore the local delivery hour}', function (DailyPromptScheduler $scheduler) {
    $scheduled = 0;

    Relationship::query()->each(function (Relationship $relationship) use ($scheduler, &$scheduled): void {
        if ($scheduler->scheduleFor($relationship, force: (bool) $this->option('force'))) {
            $scheduled++;
        }
    });

    $this->info("Scheduled {$scheduled} daily prompt round(s).");
})->purpose('Schedule the next daily prompt for eligible couples');

Artisan::command('prompts:remind', function (PromptReminderScheduler $scheduler) {
    $notified = 0;

    Relationship::query()->each(function (Relationship $relationship) use ($scheduler, &$notified): void {
        $notified += $scheduler->remind($relationship);
    });

    $this->info("Queued prompt reminders for {$notified} user(s).");
})->purpose('Remind users about unanswered prompts after 9 PM in their relationship timezone');

Artisan::command('homelab:check', function () {
    $disk = (string) config('filesystems.media_disk', 'homelab_cloud');
    $probe = '.health/'.Str::uuid().'.txt';

    try {
        Storage::disk($disk)->put($probe, now()->toIso8601String());

        if (! Storage::disk($disk)->exists($probe)) {
            throw new RuntimeException('The probe file was not visible after writing it.');
        }

        Storage::disk($disk)->delete($probe);
    } catch (Throwable $exception) {
        $this->error("Homelab media storage is unavailable: {$exception->getMessage()}");

        return 1;
    }

    $this->info("Homelab media storage is writable ({$disk}).");

    return 0;
})->purpose('Verify that homelab media storage is mounted and writable');

Artisan::command('activity:prune', function () {
    $deleted = PageVisit::query()
        ->where('visited_at', '<', now()->subDays(90))
        ->delete();

    $this->info("Deleted {$deleted} page visit(s) older than 90 days.");
})->purpose('Remove expired page visit history');

Schedule::command('prompts:schedule')->everyMinute()->withoutOverlapping();
Schedule::command('prompts:remind')->everyMinute()->withoutOverlapping();
Schedule::command('activity:prune')->dailyAt('03:15')->withoutOverlapping();
