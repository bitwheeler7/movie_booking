<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Show;
use Illuminate\Http\Request;

class ShowController extends Controller
{
    public function index(Request $request)
    {
        $query = Show::with([
            'movie',
            'screen.theatre.city',
        ])->where('status', 'SCHEDULED')
          ->where('start_time', '>', now());

        if ($request->filled('movie_id')) {
            $query->where('movie_id', $request->movie_id);
        }

        if ($request->filled('screen_id')) {
            $query->where('screen_id', $request->screen_id);
        }

        if ($request->filled('date')) {
            $request->validate(['date' => 'date']);
            $query->whereDate('start_time', $request->date);
        }

        return response()->json($query->orderBy('start_time')->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'movie_id' => 'required|exists:movies,id',
            'screen_id' => 'required|exists:screens,id',
            'start_time' => 'required|date|after:now',
            'end_time' => 'required|date|after:start_time',
            'ticket_price' => 'required|numeric|min:0',
        ]);

        $screen = \App\Models\Screen::findOrFail($data['screen_id']);

        $overlap = Show::where('screen_id', $screen->id)
            ->where('status', 'SCHEDULED')
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'This screen already has a show during that time.',
            ], 422);
        }

        return response()->json(Show::create($data), 201);
    }

    public function show(string $id)
    {
        return response()->json(
            Show::with(['movie', 'screen.theatre.city'])->findOrFail($id)
        );
    }

    public function update(Request $request, string $id)
    {
        $show = Show::findOrFail($id);

        $data = $request->validate([
            'start_time' => 'sometimes|required|date|after:now',
            'end_time' => 'sometimes|required|date',
            'ticket_price' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|required|in:SCHEDULED,CANCELLED,COMPLETED',
        ]);

        $start = $data['start_time'] ?? $show->start_time;
        $end = $data['end_time'] ?? $show->end_time;

        if ($end <= $start) {
            return response()->json([
                'message' => 'End time must be after start time.',
            ], 422);
        }

        $overlap = Show::where('screen_id', $show->screen_id)
            ->where('id', '!=', $show->id)
            ->where('status', 'SCHEDULED')
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'This screen already has a show during that time.',
            ], 422);
        }

        $show->update($data);

        return response()->json($show->fresh());
    }

    public function destroy(string $id)
    {
        $show = Show::findOrFail($id);
        $show->update(['status' => 'CANCELLED']);

        return response()->json(['message' => 'Show cancelled.']);
    }
}