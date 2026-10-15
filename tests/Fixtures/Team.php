<?php

namespace StickySoftware\Bump\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Spatie\PrefixedIds\Models\Concerns\HasPrefixedId;

class Team extends Model
{
    use HasPrefixedId;

    protected $guarded = [];

    public function bumpLabel(): ?string
    {
        return $this->name;
    }
}
