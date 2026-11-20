<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
    }

    private function createItem(User $user, array $attributes = []): Item
    {
        return Item::create(array_merge(['user_id' => $user->id, 'name' => 'Item', 'status' => Item::STATUS_ACTIVE], $attributes));
    }

    public function test_routes_require_a_token()
    {
        $this->getJson('/api/items/list')->assertStatus(401);
        $this->postJson('/api/items/create', ['name' => 'Item'])->assertStatus(401);
    }

    public function test_create_get_update_delete()
    {
        $headers = $this->authHeaders($this->user);

        $id = $this->postJson('/api/items/create', ['name' => 'First', 'description' => 'Text'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', Item::STATUS_ACTIVE)
            ->json('data.id');

        $this->getJson('/api/items/get?id=' . $id, $headers)->assertOk()->assertJsonPath('data.name', 'First');

        $this->postJson('/api/items/update', ['id' => $id, 'name' => 'Renamed', 'status' => Item::STATUS_ARCHIVED], $headers)
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.status', Item::STATUS_ARCHIVED);

        $this->postJson('/api/items/delete', ['id' => $id], $headers)->assertOk();
        $this->assertSame(0, Item::count());
    }

    public function test_create_validates_its_input()
    {
        $this->postJson('/api/items/create', ['name' => '', 'status' => 'other'], $this->authHeaders($this->user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'status']);
    }

    public function test_another_users_item_is_not_found_and_stays_untouched()
    {
        $item = $this->createItem($this->createUser(['email' => 'other@example.com']));
        $headers = $this->authHeaders($this->user);

        $this->getJson('/api/items/get?id=' . $item->id, $headers)->assertStatus(404)->assertJsonStructure(['error']);
        $this->postJson('/api/items/update', ['id' => $item->id, 'name' => 'Stolen'], $headers)->assertStatus(404);
        $this->postJson('/api/items/delete', ['id' => $item->id], $headers)->assertStatus(404);

        $this->assertSame('Item', $item->fresh()->name);
    }

    public function test_list_only_has_own_items_with_pagination()
    {
        foreach (range(1, 6) as $number) {
            $this->createItem($this->user, ['name' => 'Item ' . $number]);
        }
        $this->createItem($this->createUser(['email' => 'other@example.com']), ['name' => 'Foreign']);

        $this->getJson('/api/items/list?per_page=5&page=2', $this->authHeaders($this->user))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Item 1')
            ->assertJsonPath('data.pagination', ['page' => 2, 'per_page' => 5, 'total' => 6, 'last_page' => 2]);
    }

    public function test_list_filters_sorts_and_rejects_unknown_page_sizes()
    {
        $this->createItem($this->user, ['name' => 'Banana']);
        $this->createItem($this->user, ['name' => 'Apple']);
        $this->createItem($this->user, ['name' => 'Cherry', 'status' => Item::STATUS_ARCHIVED]);
        $headers = $this->authHeaders($this->user);

        $this->getJson('/api/items/list?sort=name&status=active', $headers)
            ->assertJsonPath('data.items.0.name', 'Apple')
            ->assertJsonCount(2, 'data.items');
        $this->getJson('/api/items/list?q=err', $headers)->assertJsonPath('data.items.0.name', 'Cherry');
        // A literal % is searched for, not used as a wildcard
        $this->getJson('/api/items/list?q=' . urlencode('%'), $headers)->assertJsonCount(0, 'data.items');
        $this->getJson('/api/items/list?per_page=7', $headers)->assertStatus(422);
    }
}
