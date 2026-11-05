<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Seat;
use Illuminate\Http\Request;

class SeatController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'screen_id' => 'required|exists:screens,id',
        ]);

        return response()->json(
            Seat::where('screen_id', $request->screen_id)
                ->where('is_active', true)
                ->orderBy('row_label')
                ->orderBy('seat_number')
                ->paginate(100)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'screen_id' => 'required|exists:screens,id',
            'seat_number' => 'required|string|max:20',
            'seat_type' => 'required|in:REGULAR,PREMIUM,RECLINER',
            'row_label' => 'nullable|string|max:10',
            'base_price' => 'required|numeric|min:0',
        ]);

        return response()->json(Seat::create($data), 201);
    }

    public function show(string $id)
    {
        return response()->json(Seat::findOrFail($id));
    }

    public function update(Request $request, string $id)
    {
        $seat = Seat::findOrFail($id);

        $seat->update($request->validate([
            'seat_type' => 'sometimes|required|in:REGULAR,PREMIUM,RECLINER',
            'row_label' => 'nullable|string|max:10',
            'base_price' => 'sometimes|required|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]));

        return response()->json($seat->fresh());
    }

    public function destroy(string $id)
    {
        $seat = Seat::findOrFail($id);
        $seat->update(['is_active' => false]);

        return response()->json(['message' => 'Seat deactivated.']);
    }
}