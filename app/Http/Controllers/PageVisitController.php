<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

class PageVisitController extends Controller
{
    /** @var list<string> */
    private const EXCLUDED_ROUTES = [
        'background.show',
        'round-photos.show',
        'moment-photos.show',
    ];

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'route' => ['required', 'string', 'max:120'],
        ]);

        $route = Route::getRoutes()->getByName($validated['route']);

        if (! $route || ! in_array('GET', $route->methods(), true) || in_array($validated['route'], self::EXCLUDED_ROUTES, true)) {
            return response()->noContent();
        }

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $user->pageVisits()->create([
            'route_name' => $validated['route'],
            'route_uri' => $route->uri(),
            'visited_at' => now(),
        ]);

        return response()->noContent();
    }
}
