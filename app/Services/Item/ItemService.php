<?php

namespace App\Services\Item;

use App\Repository\ItemRepository;
use Illuminate\Support\Facades\Log;

class ItemService
{
    public function __construct(private readonly ItemRepository $itemRepository)
    {
    }

    /**
     * {items, pagination {page, per_page, total, last_page}} for the filters in the query string
     */
    public function getItems($user, $filters)
    {
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? config('items.per_page'));

        $result = $this->itemRepository->getItemsForUser($user->id, $filters, $page, $perPage);

        return [
            'items' => $result['items'],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
            ],
        ];
    }

    public function getItem($user, $id)
    {
        $item = $this->itemRepository->getItemForUser($id, $user->id);

        if (!$item) {
            throw new \Exception('We couldn\'t find this item. It may have been deleted.', 404);
        }

        return $item;
    }

    public function createItem($user, $data)
    {
        $item = $this->itemRepository->createItem([
            'user_id' => $user->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? null,
        ]);

        Log::info('Item created', ['user_id' => $user->id, 'item_id' => $item->id]);

        return $item;
    }

    public function updateItem($user, $data)
    {
        return $this->itemRepository->updateItem($this->getItem($user, $data['id']), $data);
    }

    public function deleteItem($user, $id)
    {
        $this->itemRepository->deleteItem($this->getItem($user, $id));

        Log::info('Item deleted', ['user_id' => $user->id, 'item_id' => $id]);
    }
}
