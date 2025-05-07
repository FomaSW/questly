<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = ['chat_id', 'username', 'first_name', 'last_name'];
}
