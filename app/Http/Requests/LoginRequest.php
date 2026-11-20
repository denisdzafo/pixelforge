<?php

namespace App\Http\Requests;

class LoginRequest extends BaseFormRequest
{
    public function rules()
    {
        return [
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|max:255',
        ];
    }
}
