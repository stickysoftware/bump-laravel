<?php

namespace StickySoftware\Bump\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $guarded = [];

    public function bumpLabel(): ?string
    {
        return $this->name;
    }
}
