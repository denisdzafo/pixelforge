<?php

namespace App\Http\Requests;

class ItemIdRequest extends BaseFormRequest
{
    public function rules()
    {
        return [
            'id' => 'required|integer',
        ];
    }
}
