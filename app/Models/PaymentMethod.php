<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $dates = ['deleted_at'];

    protected $fillable = ['name', 'is_active', 'is_waiver'];

    protected $casts = [
        'is_waiver' => 'boolean',
    ];

    // The wastage waiver method only applies to purchase payments,
    // so it is hidden everywhere unless a query asks for it with withWaiver()
    protected static function booted()
    {
        static::addGlobalScope('without_waiver', function (Builder $builder) {
            $builder->where($builder->getModel()->getTable().'.is_waiver', 0);
        });
    }

    public function scopeWithWaiver($query)
    {
        return $query->withoutGlobalScope('without_waiver');
    }
}
