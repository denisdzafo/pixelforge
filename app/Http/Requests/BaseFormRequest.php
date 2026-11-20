<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseFormRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * The current user, resolved by the jwtAuth middleware. (Not getUser(): Symfony's Request already has that method,
     * it returns the HTTP basic auth name.)
     */
    public function getAuthUser()
    {
        return $this->attributes->get('userModel');
    }

    /**
     * The verified claims of the access token, set by the jwtAuth middleware
     */
    public function getAccessTokenClaims()
    {
        return $this->attributes->get('accessTokenClaims');
    }
}
