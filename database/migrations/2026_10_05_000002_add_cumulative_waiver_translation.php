<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddCumulativeWaiverTranslation extends Migration
{
    public function up()
    {
        $translations = [
            'CumulativeWaiver' => 'Cumulative Waiver',
        ];

        $locales = DB::table('translations')->distinct()->pluck('locale');

        foreach ($locales as $locale) {
            foreach ($translations as $key => $value) {
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
        DB::table('translations')->where('key', 'CumulativeWaiver')->delete();
    }
}
