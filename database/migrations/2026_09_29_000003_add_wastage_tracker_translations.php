<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddWastageTrackerTranslations extends Migration
{
    public function up()
    {
        $translations = [
            'WastageTracker' => 'Wastage Tracker',
            'ReturnType' => 'Return Type',
            'ForDamagedReturn' => 'For Damaged Return',
            'ForWastageReturn' => 'For Wastage Return',
            'WaiverAmount' => 'Waiver Amount',
            'WaiverRate' => 'Waiver Rate',
            'TotalWastage' => 'Total Wastage',
            'NotApplicable' => 'N/A',
            'FilterMode' => 'Filter Mode',
            'Monthly' => 'Monthly',
            'CustomRange' => 'Custom Range',
            'Month' => 'Month',
            'Year' => 'Year',
            'From' => 'From',
            'To' => 'To',
            'January' => 'January',
            'February' => 'February',
            'March' => 'March',
            'April' => 'April',
            'May' => 'May',
            'June' => 'June',
            'July' => 'July',
            'August' => 'August',
            'September' => 'September',
            'October' => 'October',
            'November' => 'November',
            'December' => 'December',
            'WastageWaiverRate' => 'Wastage Waiver Rate',
            'WastageWaiverRateHint' => 'Percentage of wastage amount waived by factory monthly (e.g., 5 for 5%)',
        ];

        // Get all existing locales
        $locales = DB::table('translations')->distinct()->pluck('locale');

        foreach ($locales as $locale) {
            foreach ($translations as $key => $value) {
                // Only insert if key doesn't already exist for this locale
                $exists = DB::table('translations')
                    ->where('locale', $locale)
                    ->where('key', $key)
                    ->exists();

                if (!$exists) {
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
        $keys = [
            'WastageTracker', 'ReturnType', 'ForDamagedReturn', 'ForWastageReturn',
            'WaiverAmount', 'WaiverRate', 'TotalWastage', 'NotApplicable',
            'FilterMode', 'Monthly', 'CustomRange', 'Month', 'Year', 'From', 'To',
            'January', 'February', 'March', 'April', 'May', 'June', 'July',
            'August', 'September', 'October', 'November', 'December',
            'WastageWaiverRate', 'WastageWaiverRateHint',
        ];

        DB::table('translations')->whereIn('key', $keys)->delete();
    }
}
