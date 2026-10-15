<?php

namespace StickySoftware\Bump\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    protected $guarded = [];

    public function bumpHandle(): string
    {
        return 'shop_'.$this->slug;
    }
}
