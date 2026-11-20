<?php

namespace App\Http\Requests;

class ItemRequest extends BaseFormRequest
{
    public function rules()
    {
        return [
            'id' => 'nullable|integer', // For updates
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'status' => 'nullable|in:active,archived',
        ];
    }
}
