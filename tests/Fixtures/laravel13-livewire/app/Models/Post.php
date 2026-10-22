<?php

namespace App\Models;

use App\Observers\PostObserver;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(PostObserver::class)]
#[Appends(['excerpt'])]
class Post extends Model
{
    protected $fillable = ['title', 'body'];

    protected function excerpt(): Attribute
    {
        return Attribute::get(fn () => substr((string) $this->body, 0, 80));
    }

    // The accessor style before Laravel 9, still common in older code.
    public function getShoutedTitleAttribute(): string
    {
        return strtoupper((string) $this->title);
    }

    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }

    #[Scope]
    protected function draft(Builder $query): void
    {
        $query->whereNull('published_at');
    }
}
