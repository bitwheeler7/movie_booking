<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function index()
    {
        return response()->json(City::where('is_active', true)->paginate(10));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
        ]);

        return response()->json(City::create($data), 201);
    }

    public function show(string $id)
    {
        return response()->json(City::with('theatres')->findOrFail($id));
    }

    public function update(Request $request, string $id)
    {
        $city = City::findOrFail($id);

        $city->update($request->validate([
            'name' => 'sometimes|required|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'is_active' => 'sometimes|boolean',
        ]));

        return response()->json($city->fresh());
    }

    public function destroy(string $id)
    {
        $city = City::findOrFail($id);
        $city->update(['is_active' => false]);

        return response()->json(['message' => 'City deactivated.']);
    }
}