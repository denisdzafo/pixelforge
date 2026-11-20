<?php

namespace App\Http\Controllers;

use App\Http\Requests\ItemIdRequest;
use App\Http\Requests\ItemListRequest;
use App\Http\Requests\ItemRequest;
use App\Services\Item\ItemService;

class ItemController extends Controller
{
    public function __construct(private readonly ItemService $itemService)
    {
    }

    public function getItems(ItemListRequest $request)
    {
        try {
            $result = $this->itemService->getItems($request->getAuthUser(), $request->all());
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return response()->json(['data' => $result]);
    }

    public function getItem(ItemIdRequest $request)
    {
        try {
            $item = $this->itemService->getItem($request->getAuthUser(), $request->get('id'));
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return response()->json(['data' => $item]);
    }

    public function createItem(ItemRequest $request)
    {
        try {
            $item = $this->itemService->createItem($request->getAuthUser(), $request->all());
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return response()->json(['data' => $item]);
    }

    public function updateItem(ItemRequest $request)
    {
        try {
            $item = $this->itemService->updateItem($request->getAuthUser(), $request->all());
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return response()->json(['data' => $item]);
    }

    public function deleteItem(ItemIdRequest $request)
    {
        try {
            $this->itemService->deleteItem($request->getAuthUser(), $request->get('id'));
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return response()->json(['data' => true]);
    }
}
