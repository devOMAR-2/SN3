<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\Inbox;
use App\Domain\Notifications\InboxPresenter;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Notifications\ListNotificationsRequest;
use App\Http\Requests\Notifications\MarkNotificationsReadRequest;
use App\Http\Responses\ApiResponse;
use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The inbox (API_SPEC.md §9; FR-COL-003, FR-COL-005, NOT-01; CORE-009, ADR-029 §3). Everything goes
 * through Inbox, which shows the caller only their own notifications about things they can see now:
 * the list, the unread count, "mark read" (`{notification}` is a 404 otherwise) and "mark all read".
 */
class NotificationController extends Controller
{
    public function index(ListNotificationsRequest $request, Inbox $inbox, InboxPresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return CursorPagination::respondMany(
            $request,
            $inbox->list($user, $request->unreadOnly()),
            // One query per kind of row for the whole page (InboxPresenter).
            /** @param list<InboxNotification> $notifications */
            fn (array $notifications): array => $presenter->present($notifications),
            scope: $request->unreadOnly() ? 'unread' : 'all',
        );
    }

    public function unreadCount(Inbox $inbox): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        return ApiResponse::data(['unread_count' => $inbox->unreadCount($user)]);
    }

    public function read(MarkNotificationsReadRequest $request, InboxNotification $notification, Inbox $inbox, InboxPresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $view = $presenter->one($inbox->markRead($user, $notification));

        if ($view === null) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::data($view);
    }

    public function readAll(MarkNotificationsReadRequest $request, Inbox $inbox): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::data(['marked_read' => $inbox->markAllRead($user)]);
    }
}
