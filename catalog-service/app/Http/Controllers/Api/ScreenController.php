<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Screen;
use Illuminate\Http\Request;

class ScreenController extends Controller
{
    public function index(Request $request)
    {
        $query = Screen::with('theatre')->where('is_active', true);

        if ($request->filled('theatre_id')) {
            $query->where('theatre_id', $request->theatre_id);
        }

        return response()->json($query->paginate(10));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'theatre_id' => 'required|exists:theatres,id',
            'name' => 'required|string|max:100',
        ]);

        return response()->json(Screen::create($data), 201);
    }

    public function show(string $id)
    {
        return response()->json(
            Screen::with(['theatre', 'seats'])->findOrFail($id)
        );
    }

    public function update(Request $request, string $id)
    {
        $screen = Screen::findOrFail($id);

        $screen->update($request->validate([
            'name' => 'sometimes|required|string|max:100',
            'is_active' => 'sometimes|boolean',
        ]));

        return response()->json($screen->fresh());
    }

    public function destroy(string $id)
    {
        $screen = Screen::findOrFail($id);
        $screen->update(['is_active' => false]);

        return response()->json(['message' => 'Screen deactivated.']);
    }
}