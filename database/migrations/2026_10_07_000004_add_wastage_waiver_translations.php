<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddWastageWaiverTranslations extends Migration
{
    protected $translations = [
        'WastageWaiver' => 'Wastage Waiver',
        'WaiverAccount' => 'Waiver Account',
        'WaiverBalance' => 'Waiver Balance',
        'WaiverEarned' => 'Earned',
        'WaiverUsed' => 'Used',
        'WaiverExpired' => 'Expired',
        'WaiverReversed' => 'Reversed',
        'WaiverLedger' => 'Waiver Ledger',
        'WaiverBySupplier' => 'Waiver by Supplier',
        'WaiverAvailable' => 'Available waiver',
        'WaiverExceedsBalance' => 'Amount is greater than the available waiver balance',
        'WaiverTypeEarn' => 'Earned',
        'WaiverTypeEarnReversal' => 'Earn reversed',
        'WaiverTypeRedeem' => 'Used for payment',
        'WaiverTypeRedeemReversal' => 'Payment reversed',
        'WaiverTypeExpire' => 'Expired',
        'AllSuppliers' => 'All Suppliers',
        'BaseAmount' => 'Base Amount',
        'Rate' => 'Rate',
        'WastageWaiverRateHint' => 'Percentage of a paid purchase added to the supplier waiver balance (e.g., 5 for 5%)',
    ];

    public function up()
    {
        $locales = DB::table('translations')->distinct()->pluck('locale');

        foreach ($locales as $locale) {
            foreach ($this->translations as $key => $value) {
                $query = DB::table('translations')->where('locale', $locale)->where('key', $key);

                if ($key === 'WastageWaiverRateHint') {
                    // The rate now applies to paid purchases, not to wastage returns
                    $query->update(['value' => $value]);

                    continue;
                }

                if (! $query->exists()) {
                    DB::table('translations')->insert([
                        'locale' => $locale,
                        'key' => $key,
                        'value' => $value,
                    ]);
                }
            }
        }
    }

    public function down()
    {
        $keys = array_diff(array_keys($this->translations), ['WastageWaiverRateHint']);

        DB::table('translations')->whereIn('key', $keys)->delete();
    }
}
