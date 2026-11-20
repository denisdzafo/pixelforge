<?php

namespace App\Http\Requests;

/**
 * Filters of the items list. All optional; the defaults are the page's defaults.
 */
class ItemListRequest extends BaseFormRequest
{
    public function rules()
    {
        return [
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,archived',
            'sort' => 'nullable|in:newest,oldest,name',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:' . implode(',', config('items.per_page_options')),
        ];
    }
}
