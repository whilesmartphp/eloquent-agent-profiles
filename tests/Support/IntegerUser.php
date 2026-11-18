<?php

namespace Tests\Support;

use Illuminate\Foundation\Auth\User;

class IntegerUser extends User
{
    protected $table = 'integer_users';

    protected $guarded = [];
}
