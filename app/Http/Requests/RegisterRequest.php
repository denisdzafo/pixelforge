<?php

namespace App\Http\Requests;

class RegisterRequest extends BaseFormRequest
{
    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|max:255|confirmed',
        ];
    }
}
