<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $request->user()
                    ? [
                        ...$request->user()->only(['id', 'name', 'email', 'email_verified_at', 'site_theme']),
                        'is_admin' => $request->user()->isAdmin(),
                        'can_access_admin' => $request->user()->canAccessAdmin(),
                        'can_access_bitcraft' => $request->user()->canAccessBitcraft(),
                        'can_access_connected_realms' => $request->user()->canAccessConnectedRealms(),
                        'can_access_world_engine' => $request->user()->canAccessWorldEngine(),
                        'can_access_datacrypt' => $request->user()->canAccessDatacrypt(),
                    ]
                    : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'features' => [
                'bitcraft' => [
                    'live_companion' => (bool) config('services.bitcraft_live_companion.enabled', false),
                ],
            ],
        ];
    }
}
