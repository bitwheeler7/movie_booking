<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Theatre;
use Illuminate\Http\Request;

class TheatreController extends Controller
{
    public function index(Request $request)
    {
        $query = Theatre::with('city')->where('is_active', true);

        if ($request->filled('city_id')) {
            $query->where('city_id', $request->city_id);
        }

        return response()->json($query->paginate(10));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'city_id' => 'required|exists:cities,id',
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'pincode' => 'nullable|string|max:10',
        ]);

        return response()->json(Theatre::create($data), 201);
    }

    public function show(string $id)
    {
        return response()->json(
            Theatre::with(['city', 'screens'])->findOrFail($id)
        );
    }

    public function update(Request $request, string $id)
    {
        $theatre = Theatre::findOrFail($id);

        $theatre->update($request->validate([
            'city_id' => 'sometimes|required|exists:cities,id',
            'name' => 'sometimes|required|string|max:255',
            'address' => 'nullable|string',
            'pincode' => 'nullable|string|max:10',
            'is_active' => 'sometimes|boolean',
        ]));

        return response()->json($theatre->fresh());
    }

    public function destroy(string $id)
    {
        $theatre = Theatre::findOrFail($id);
        $theatre->update(['is_active' => false]);

        return response()->json(['message' => 'Theatre deactivated.']);
    }
}