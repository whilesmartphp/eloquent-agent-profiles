<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User;

class UuidUser extends User
{
    use HasUuids;

    protected $table = 'uuid_users';

    protected $guarded = [];
}
