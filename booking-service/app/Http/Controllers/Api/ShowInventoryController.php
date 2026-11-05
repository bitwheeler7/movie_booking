<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShowSeat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShowInventoryController extends Controller
{
    public function store(Request $request, string $showId)
    {
        $data = $request->validate([
            'seats' => 'required|array|min:1',
            'seats.*.seat_id' => 'required|integer|min:1',
            'seats.*.seat_number' => 'required|string|max:20',
            'seats.*.ticket_price' => 'required|numeric|min:0',
        ]);

        $seatIds = collect($data['seats'])->pluck('seat_id');

        if ($seatIds->unique()->count() !== $seatIds->count()) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate seat IDs are not allowed.',
            ], 422);
        }

        $created = DB::transaction(function () use ($data, $showId) {
            $count = 0;

            foreach ($data['seats'] as $seat) {
                $exists = ShowSeat::where('show_id', $showId)
                    ->where('seat_id', $seat['seat_id'])
                    ->exists();

                if (!$exists) {
                    ShowSeat::create([
                        'show_id' => $showId,
                        'seat_id' => $seat['seat_id'],
                        'seat_number' => $seat['seat_number'],
                        'ticket_price' => $seat['ticket_price'],
                        'status' => 'AVAILABLE',
                    ]);

                    $count++;
                }
            }

            return $count;
        });

        return response()->json([
            'success' => true,
            'message' => 'Show inventory initialized.',
            'created_seats' => $created,
        ], 201);
    }
}