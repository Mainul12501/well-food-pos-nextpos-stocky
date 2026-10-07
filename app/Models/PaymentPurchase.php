<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentPurchase extends Model
{
    use SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'purchase_id', 'date', 'montant', 'change', 'Ref', 'payment_method_id', 'user_id', 'notes', 'account_id',
    ];

    protected $casts = [
        'montant' => 'double',
        'change' => 'double',
        'purchase_id' => 'integer',
        'user_id' => 'integer',
        'account_id' => 'integer',
        'payment_method_id' => 'integer',
    ];

    // Payments that moved money; a payment made with the wastage waiver did not
    public function scopeCashOnly($query)
    {
        return $query->whereNotIn('payment_purchases.payment_method_id', function ($sub) {
            $sub->select('id')->from('payment_methods')->where('is_waiver', 1);
        });
    }

    public function payment_method()
    {
        return $this->belongsTo('App\Models\PaymentMethod')->withWaiver();
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function account()
    {
        return $this->belongsTo('App\Models\Account');
    }

    public function purchase()
    {
        return $this->belongsTo('App\Models\Purchase');
    }
}
