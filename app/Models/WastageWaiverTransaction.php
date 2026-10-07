<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WastageWaiverTransaction extends Model
{
    const TYPE_EARN = 'earn';

    const TYPE_EARN_REVERSAL = 'earn_reversal';

    const TYPE_REDEEM = 'redeem';

    const TYPE_REDEEM_REVERSAL = 'redeem_reversal';

    const TYPE_EXPIRE = 'expire';

    protected $fillable = [
        'provider_id', 'period', 'date', 'type', 'amount', 'rate', 'base_amount',
        'purchase_id', 'payment_purchase_id', 'reversal_of_id', 'user_id', 'note',
    ];

    protected $casts = [
        'amount' => 'double',
        'rate' => 'double',
        'base_amount' => 'double',
        'provider_id' => 'integer',
        'purchase_id' => 'integer',
        'payment_purchase_id' => 'integer',
        'reversal_of_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function provider()
    {
        return $this->belongsTo('App\Models\Provider');
    }

    public function purchase()
    {
        return $this->belongsTo('App\Models\Purchase');
    }

    public function payment()
    {
        return $this->belongsTo('App\Models\PaymentPurchase', 'payment_purchase_id')->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }
}
