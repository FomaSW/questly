<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = ['chat_id', 'title', 'priority', 'is_done'];
}
