<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\TestPushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use NotificationChannels\WebPush\WebPushChannel;

class PushNotificationController extends Controller
{
    public function __construct(private readonly WebPushChannel $webPush) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:1024'],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', Rule::in(['aes128gcm', 'aesgcm'])],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['contentEncoding'] ?? 'aes128gcm',
        );

        return response()->json(['message' => 'This device is subscribed.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:1024'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->deletePushSubscription($validated['endpoint']);

        return response()->json(['message' => 'This device is unsubscribed.']);
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:200'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! $user->pushSubscriptions()->exists()) {
            return response()->json([
                'message' => 'Enable notifications on a device before sending a test push.',
            ], 422);
        }

        $reports = $this->webPush->send(
            $user,
            new TestPushNotification($validated['title'], $validated['body']),
        );

        $failedReport = collect($reports)->first(fn ($report) => ! $report->isSuccess());

        if ($failedReport) {
            return response()->json([
                'message' => 'The push service rejected the notification: '.$failedReport->getReason(),
            ], 502);
        }

        return response()->json([
            'message' => 'The push service accepted the notification. Lock your phone or leave the app and watch for it.',
            'subscriptions' => $user->pushSubscriptions()->count(),
        ]);
    }
}
