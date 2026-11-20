<?php

namespace App\Http\Requests;

/**
 * Endpoints without input of their own: gives the controller getAuthUser() (BaseFormRequest is abstract)
 */
class AuthenticatedRequest extends BaseFormRequest
{
    public function rules()
    {
        return [];
    }
}
