<?php

namespace App\Models;

use App\Traits\HasUUIDSettings;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    use HasUUIDSettings;
}
