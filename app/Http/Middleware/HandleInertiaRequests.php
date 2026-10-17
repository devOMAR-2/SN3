<?php

namespace App\Http\Middleware;

use App\Domain\Notifications\Inbox;
use App\Http\Pages\Workspace;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\Support\Locale;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Resolved lazily so the value reflects SetLocale regardless of middleware order.
            'locale' => fn (): string => $this->locale()->value,
            'dir' => fn (): string => $this->locale()->direction(),
            // FND-004: the signed-in user's own profile (never other users' data or secrets).
            'auth' => function () use ($request): array {
                $user = $request->user();

                return ['user' => $user instanceof User ? MeResource::make($user) : null];
            },
            'flash' => fn (): array => ['status' => is_string($status = $request->session()->get('status')) ? $status : null],
            'features' => fn (): array => ['registration' => (bool) config('sn3.registration.enabled')],
            // CORE-006: the signed-in user's teams for the team switcher (AccessibleTeams only).
            'workspace' => function () use ($request): ?array {
                $user = $request->user();

                return $user instanceof User ? Workspace::switcher($user) : null;
            },
            // CORE-009: the unread count for the Inbox link, through the inbox's read-time access rules.
            'inbox' => function () use ($request): ?array {
                $user = $request->user();

                return $user instanceof User ? ['unread_count' => app(Inbox::class)->unreadCount($user)] : null;
            },
        ];
    }

    private function locale(): Locale
    {
        return Locale::fromValue(app()->getLocale()) ?? Locale::DEFAULT;
    }
}
