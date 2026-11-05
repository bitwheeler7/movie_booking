<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return $this->notificationsFor($request)
            ->latest()
            ->paginate($this->perPage($request));
    }

    public function unread(Request $request)
    {
        return $this->notificationsFor($request)
            ->whereNull('read_at')
            ->latest()
            ->paginate($this->perPage($request));
    }

    public function show(Request $request, string $id)
    {
        return response()->json([
            'success' => true,
            'data' => $this->notificationsFor($request)->findOrFail($id),
        ]);
    }

    public function markAsRead(Request $request, string $id)
    {
        $notification = $this->notificationsFor($request)->findOrFail($id);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return response()->json([
            'success' => true,
            'data' => $notification->fresh(),
        ]);
    }

    public function markAllAsRead(Request $request)
    {
        $updated = $this->notificationsFor($request)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'updated' => $updated,
        ]);
    }

    public function deliveryStatus(Request $request, string $id)
    {
        $notification = $this->notificationsFor($request)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $notification->id,
                'event_id' => $notification->event_id,
                'delivery_status' => $notification->delivery_status,
                'delivered_at' => $notification->created_at,
            ],
        ]);
    }

    private function notificationsFor(Request $request)
    {
        return UserNotification::where(
            'user_id',
            $request->attributes->get('authenticated_user_id')
        );
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, $request->integer('per_page', 15)));
    }
}
