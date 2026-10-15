<?php

namespace StickySoftware\Bump\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\PrefixedIds\Models\Concerns\HasPrefixedId;

class User extends Model
{
    use HasPrefixedId;
    use SoftDeletes;

    protected $guarded = [];
}
