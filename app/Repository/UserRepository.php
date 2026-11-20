<?php

namespace App\Repository;

use App\Models\User;

class UserRepository
{

    public function getUserById($id)
    {
        return User::where('id', $id)->first();
    }

    public function findByEmail($email)
    {
        return User::where('email', $email)->first();
    }

    public function createUser($data)
    {
        $user = new User();
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = $data['password'];
        $user->save();

        return $user;
    }

}
