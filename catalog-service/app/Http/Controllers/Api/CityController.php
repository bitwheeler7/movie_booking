<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CityController extends Controller
{
    public function index()
    {
        return response()->json(City::where('is_active', true)->paginate(10));
    }

    public function store(Request $request)
    {
        $state = $request->input('state');
        $state = is_string($state) ? $state : null;

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('cities', 'name')->where(
                    fn (Builder $query) => $state === null
                        ? $query->whereNull('state')
                        : $query->where('state', $state)
                ),
            ],
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

        $data = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        if (array_key_exists('name', $data) || array_key_exists('state', $data)) {
            $name = $data['name'] ?? $city->name;
            $state = array_key_exists('state', $data) ? $data['state'] : $city->state;

            $duplicate = City::where('name', $name)
                ->where('id', '!=', $city->id)
                ->when(
                    $state === null,
                    fn ($query) => $query->whereNull('state'),
                    fn ($query) => $query->where('state', $state)
                )
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'name' => 'The name has already been taken for this state.',
                ]);
            }
        }

        $city->update($data);

        return response()->json($city->fresh());
    }

    public function destroy(string $id)
    {
        $city = City::findOrFail($id);
        $city->update(['is_active' => false]);

        return response()->json(['message' => 'City deactivated.']);
    }
}
