<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Movie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogWriteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
    }

    public function test_movie_can_be_created_and_updated_through_api(): void
    {
        $this->postJson('/api/movies', [
            'title' => 'Example Movie',
            'poster_url' => 'https://example.test/poster.jpg',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Example Movie');

        $movie = Movie::query()->firstOrFail();

        $this->putJson("/api/movies/{$movie->id}", [
            'title' => 'Updated Movie',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Movie');
    }

    public function test_city_can_be_created_and_updated_through_api(): void
    {
        $this->postJson('/api/cities', [
            'name' => 'Example City',
            'state' => 'Example State',
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Example City');

        $city = City::query()->firstOrFail();

        $this->putJson("/api/cities/{$city->id}", [
            'name' => 'Updated City',
            'country' => 'Example Country',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Updated City')
            ->assertJsonPath('country', 'Example Country')
            ->assertJsonPath('is_active', false);
    }

    public function test_duplicate_city_name_and_state_returns_validation_error(): void
    {
        City::create([
            'name' => 'Existing City',
            'state' => 'Existing State',
        ]);

        $this->postJson('/api/cities', [
            'name' => 'Existing City',
            'state' => 'Existing State',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_api_validation_errors_are_json_even_for_wildcard_accept_header(): void
    {
        $this->call('POST', '/api/movies', [], [], [], [
            'HTTP_ACCEPT' => '*/*',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]))
            ->assertUnprocessable()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonValidationErrors('title');
    }

    public function test_city_update_cannot_duplicate_another_city_in_the_same_state(): void
    {
        City::create(['name' => 'Existing City', 'state' => 'Example State']);
        $city = City::create(['name' => 'Another City', 'state' => 'Example State']);

        $this->putJson("/api/cities/{$city->id}", [
            'name' => 'Existing City',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_city_state_only_update_cannot_create_a_duplicate(): void
    {
        City::create(['name' => 'Existing City', 'state' => 'New State']);
        $city = City::create(['name' => 'Existing City', 'state' => 'Old State']);

        $this->putJson("/api/cities/{$city->id}", [
            'state' => 'New State',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }
}
