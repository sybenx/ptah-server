<?php

namespace App\Models;

use App\Traits\HasOwningTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Swarm extends Model
{
    use HasFactory;
    use HasOwningTeam;

    protected $fillable = [
        'name'
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }
}