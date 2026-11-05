<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use Illuminate\Http\Request;

class MovieController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Movie::where('is_active', true)
                ->latest()
                ->paginate(10),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'language' => 'nullable|string|max:50',
            'genre' => 'nullable|string|max:100',
            'duration_minutes' => 'nullable|integer|min:1',
            'release_date' => 'nullable|date',
            'certificate' => 'nullable|string|max:20',
            'poster_url' => 'nullable|url|max:255',
        ]);

        $movie = Movie::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Movie created successfully.',
            'data' => $movie,
        ], 201);
    }

    public function show(string $id)
    {
        $movie = Movie::where('is_active', true)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $movie,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $movie = Movie::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'language' => 'nullable|string|max:50',
            'genre' => 'nullable|string|max:100',
            'duration_minutes' => 'nullable|integer|min:1',
            'release_date' => 'nullable|date',
            'certificate' => 'nullable|string|max:20',
            'poster_url' => 'nullable|url|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        $movie->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Movie updated successfully.',
            'data' => $movie->fresh(),
        ]);
    }

    public function destroy(string $id)
    {
        $movie = Movie::findOrFail($id);

        // Keep records referenced by shows deactivate instead of deleting.
        $movie->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Movie deactivated successfully.',
        ]);
    }
}
