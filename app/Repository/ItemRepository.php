<?php

namespace App\Repository;

use App\Models\Item;

class ItemRepository
{

    /**
     * One page of the user's items for the filters: {items, total}. Every query is limited to the owner.
     */
    public function getItemsForUser($userId, $filters, $page, $perPage)
    {
        $query = Item::where('user_id', $userId);

        if (!empty($filters['q'])) {
            $query->where('name', 'like', '%' . addcslashes($filters['q'], '\\%_') . '%');
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $total = $query->count();

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            default => $query->orderByDesc('id'),
        };

        return [
            'items' => $query->forPage($page, $perPage)->get(),
            'total' => $total,
        ];
    }

    public function getItemForUser($id, $userId)
    {
        return Item::where('id', $id)->where('user_id', $userId)->first();
    }

    public function createItem($data)
    {
        $item = new Item();
        $item->user_id = $data['user_id'];
        $item->name = $data['name'];
        $item->description = $data['description'] ?? null;
        $item->status = $data['status'] ?? Item::STATUS_ACTIVE;
        $item->save();

        return $item;
    }

    public function updateItem($item, $data)
    {
        $item->name = $data['name'];
        $item->description = $data['description'] ?? null;
        $item->status = $data['status'] ?? $item->status;
        $item->save();

        return $item;
    }

    public function deleteItem($item)
    {
        $item->delete();
    }

}
