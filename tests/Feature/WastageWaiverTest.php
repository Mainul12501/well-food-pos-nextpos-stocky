<?php

namespace Tests\Feature;

use App\Exceptions\WaiverException;
use App\Models\PaymentMethod;
use App\Models\PaymentPurchase;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\WastageWaiverTransaction as Waiver;
use App\Services\WastageWaiverService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Rules of the per-supplier wastage waiver ledger.
 *
 * The full migration set does not run on sqlite, so the tables the service
 * touches are built here. The helpers repeat what the controllers do around
 * the service: one DB transaction per action, payment rows and purchase
 * totals written first, then the service calls.
 */
class WastageWaiverTest extends TestCase
{
    /** @var WastageWaiverService */
    protected $waiver;

    protected $cash_method_id;

    protected $waiver_method_id;

    protected $supplier_a;

    protected $supplier_b;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 9, 15, 12));

        $this->createTables();

        DB::table('settings')->insert(['wastage_waiver_rate' => 5]);
        $this->cash_method_id = DB::table('payment_methods')->insertGetId(['name' => 'Cash', 'is_waiver' => 0]);
        $this->waiver_method_id = DB::table('payment_methods')->insertGetId(['name' => 'Wastage Waiver', 'is_waiver' => 1]);
        $this->supplier_a = DB::table('providers')->insertGetId(['name' => 'Supplier A']);
        $this->supplier_b = DB::table('providers')->insertGetId(['name' => 'Supplier B']);

        $this->waiver = app(WastageWaiverService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Earning
    // ------------------------------------------------------------------

    public function test_paid_purchase_earns_once_at_the_settings_rate(): void
    {
        $purchase = $this->purchase($this->supplier_a, 1000);
        $this->pay($purchase, 1000);

        $earns = Waiver::where('type', Waiver::TYPE_EARN)->get();
        $this->assertCount(1, $earns);
        $this->assertEquals(50.0, $earns[0]->amount);
        $this->assertEquals(5.0, $earns[0]->rate);
        $this->assertEquals(1000.0, $earns[0]->base_amount);
        $this->assertSame('2026-09', $earns[0]->period);
        $this->assertEquals(50.0, $this->waiver->balance($this->supplier_a));
    }

    public function test_partial_payment_earns_nothing(): void
    {
        $purchase = $this->purchase($this->supplier_a, 1000);
        $this->pay($purchase, 400);

        $this->assertSame(0, Waiver::count());
        $this->assertEquals(0.0, $this->waiver->balance($this->supplier_a));
    }

    public function test_purchase_that_is_not_received_earns_nothing(): void
    {
        $purchase = $this->purchase($this->supplier_a, 1000, 'pending');
        $this->pay($purchase, 1000);

        $this->assertSame(0, Waiver::count());
    }

    public function test_deleting_the_payment_reverses_the_earn(): void
    {
        $purchase = $this->purchase($this->supplier_a, 1000);
        $this->pay($purchase, 600);
        $payment = $this->pay($purchase, 400);

        $this->deletePayment($payment);

        $this->assertSame(1, Waiver::where('type', Waiver::TYPE_EARN_REVERSAL)->count());
        $this->assertEquals(0.0, $this->waiver->balance($this->supplier_a));
        $this->assertNull($this->waiver->activeEarn($purchase->id));
    }

    public function test_paying_again_posts_a_new_earn_not_a_duplicate(): void
    {
        $purchase = $this->purchase($this->supplier_a, 1000);
        $this->pay($purchase, 600);
        $payment = $this->pay($purchase, 400);
        $this->deletePayment($payment);

        $this->pay($purchase, 400);

        $this->assertSame(2, Waiver::where('type', Waiver::TYPE_EARN)->count());
        $this->assertNotNull($this->waiver->activeEarn($purchase->id));
        $this->assertEquals(50.0, $this->waiver->balance($this->supplier_a));

        // Syncing an unchanged purchase writes nothing
        $entries = Waiver::count();
        DB::transaction(fn () => $this->waiver->syncPurchase($purchase->id));
        $this->assertSame($entries, Waiver::count());
    }

    public function test_part_paid_by_waiver_is_excluded_from_the_earn_base(): void
    {
        $this->pay($this->purchase($this->supplier_a, 1000), 1000);

        $purchase = $this->purchase($this->supplier_a, 1000);
        $this->pay($purchase, 50, $this->waiver_method_id);
        $this->pay($purchase, 950);

        $earn = $this->waiver->activeEarn($purchase->id);
        $this->assertEquals(950.0, $earn->base_amount);
        $this->assertEquals(47.5, $earn->amount);
        $this->assertEquals(47.5, $this->waiver->balance($this->supplier_a));
    }

    public function test_raising_the_purchase_total_takes_the_earn_back(): void
    {
        $purchase = $this->purchase($this->supplier_a, 1000);
        $this->pay($purchase, 1000);

        DB::transaction(function () use ($purchase) {
            $purchase->update(['GrandTotal' => 1200, 'payment_statut' => 'partial']);
            $this->waiver->syncPurchase($purchase->id);
        });

        $this->assertEquals(0.0, $this->waiver->balance($this->supplier_a));
    }

    // ------------------------------------------------------------------
    // Paying with waiver
    // ------------------------------------------------------------------

    public function test_redeem_above_the_balance_is_rejected(): void
    {
        $this->pay($this->purchase($this->supplier_a, 1000), 1000);
        $purchase = $this->purchase($this->supplier_a, 500);

        try {
            $this->pay($purchase, 60, $this->waiver_method_id);
            $this->fail('A waiver payment above the balance was accepted.');
        } catch (WaiverException $e) {
            $this->assertStringContainsString('Not enough waiver balance', $e->getMessage());
        }

        $this->assertSame(0, PaymentPurchase::where('purchase_id', $purchase->id)->count());
        $this->assertEquals(0.0, $purchase->fresh()->paid_amount);
        $this->assertEquals(50.0, $this->waiver->balance($this->supplier_a));
    }

    public function test_redeem_above_the_purchase_due_is_rejected(): void
    {
        $this->pay($this->purchase($this->supplier_a, 1000), 1000);
        $purchase = $this->purchase($this->supplier_a, 30);

        $this->expectException(WaiverException::class);
        $this->pay($purchase, 40, $this->waiver_method_id);
    }

    public function test_waiver_of_one_supplier_cannot_pay_another_suppliers_purchase(): void
    {
        $this->pay($this->purchase($this->supplier_a, 1000), 1000);
        $purchase = $this->purchase($this->supplier_b, 500);

        try {
            $this->pay($purchase, 20, $this->waiver_method_id);
            $this->fail('Waiver of supplier A paid a purchase of supplier B.');
        } catch (WaiverException $e) {
            $this->assertStringContainsString('Supplier B', $e->getMessage());
        }

        $this->assertEquals(50.0, $this->waiver->balance($this->supplier_a));
    }

    public function test_each_supplier_balance_reflects_only_its_own_entries(): void
    {
        $this->pay($this->purchase($this->supplier_a, 1000), 1000);
        $this->pay($this->purchase($this->supplier_b, 2000), 2000);

        $this->pay($this->purchase($this->supplier_a, 500), 20, $this->waiver_method_id);

        $this->assertEquals(30.0, $this->waiver->balance($this->supplier_a));
        $this->assertEquals(100.0, $this->waiver->balance($this->supplier_b));
    }

    public function test_deleting_a_waiver_payment_gives_the_waiver_back(): void
    {
        $this->pay($this->purchase($this->supplier_a, 1000), 1000);
        $payment = $this->pay($this->purchase($this->supplier_a, 500), 20, $this->waiver_method_id);

        $this->deletePayment($payment);

        $this->assertSame(1, Waiver::where('type', Waiver::TYPE_REDEEM_REVERSAL)->count());
        $this->assertEquals(50.0, $this->waiver->balance($this->supplier_a));
    }

    public function test_waiver_payment_of_a_closed_month_cannot_be_deleted(): void
    {
        $this->pay($this->purchase($this->supplier_a, 1000), 1000);
        $payment = $this->pay($this->purchase($this->supplier_a, 500), 20, $this->waiver_method_id);

        Carbon::setTestNow(Carbon::create(2026, 10, 2, 9));

        try {
            $this->deletePayment($payment);
            $this->fail('A waiver payment of a closed month was deleted.');
        } catch (WaiverException $e) {
            $this->assertStringContainsString('September 2026', $e->getMessage());
        }

        $this->assertNotNull(PaymentPurchase::find($payment->id));
    }

    // ------------------------------------------------------------------
    // Deleting purchases and suppliers
    // ------------------------------------------------------------------

    public function test_deleting_a_paid_purchase_with_unspent_waiver_reverses_the_earn(): void
    {
        $purchase = $this->purchase($this->supplier_a, 1000);
        $this->pay($purchase, 1000);

        $this->deletePurchases([$purchase]);

        $this->assertNotNull($purchase->fresh()->deleted_at);
        $this->assertSame(1, Waiver::where('type', Waiver::TYPE_EARN_REVERSAL)->count());
        $this->assertEquals(0.0, $this->waiver->balance($this->supplier_a));
    }

    public function test_deleting_a_paid_purchase_with_spent_waiver_is_refused_and_changes_nothing(): void
    {
        $purchase = $this->purchase($this->supplier_a, 1000);
        $this->pay($purchase, 1000);
        $this->pay($this->purchase($this->supplier_a, 40), 40, $this->waiver_method_id);

        $entries = Waiver::count();

        try {
            $this->deletePurchases([$purchase]);
            $this->fail('A purchase whose waiver is already used was deleted.');
        } catch (WaiverException $e) {
            $this->assertStringContainsString('Supplier A', $e->getMessage());
            $this->assertStringContainsString($purchase->Ref, $e->getMessage());
            $this->assertStringContainsString('September 2026', $e->getMessage());
        }

        $this->assertNull($purchase->fresh()->deleted_at);
        $this->assertSame(1, PaymentPurchase::where('purchase_id', $purchase->id)->count());
        $this->assertSame($entries, Waiver::count());
        $this->assertEquals(10.0, $this->waiver->balance($this->supplier_a));
    }

    public function test_bulk_delete_with_one_refused_purchase_rejects_the_whole_batch(): void
    {
        $spent = $this->purchase($this->supplier_a, 1000);
        $this->pay($spent, 1000);
        $this->pay($this->purchase($this->supplier_a, 40), 40, $this->waiver_method_id);

        $other = $this->purchase($this->supplier_b, 2000);
        $this->pay($other, 2000);

        try {
            $this->deletePurchases([$other, $spent]);
            $this->fail('A batch containing a refused purchase was deleted.');
        } catch (WaiverException $e) {
            // expected
        }

        $this->assertNull($other->fresh()->deleted_at);
        $this->assertNull($spent->fresh()->deleted_at);
        $this->assertEquals(100.0, $this->waiver->balance($this->supplier_b));
    }

    public function test_deleting_a_purchase_of_a_closed_month_leaves_both_months_right(): void
    {
        $purchase = $this->purchase($this->supplier_a, 1000);
        $this->pay($purchase, 1000);

        Carbon::setTestNow(Carbon::create(2026, 10, 2, 9));
        $this->waiver->closePastPeriods();
        $this->pay($this->purchase($this->supplier_a, 400), 400);

        $this->deletePurchases([$purchase]);

        $this->assertNotNull($purchase->fresh()->deleted_at);
        $this->assertEquals(0.0, $this->waiver->balance($this->supplier_a, '2026-09'));
        $this->assertEquals(20.0, $this->waiver->balance($this->supplier_a));
    }

    public function test_supplier_of_a_purchase_with_an_active_earn_cannot_be_changed(): void
    {
        $purchase = $this->purchase($this->supplier_a, 1000);
        $this->pay($purchase, 1000);

        // Same supplier is fine
        $this->waiver->assertSupplierUnchanged($purchase, $this->supplier_a);

        $this->expectException(WaiverException::class);
        $this->waiver->assertSupplierUnchanged($purchase, $this->supplier_b);
    }

    public function test_supplier_with_a_waiver_balance_cannot_be_deleted(): void
    {
        $this->pay($this->purchase($this->supplier_a, 1000), 1000);

        $this->waiver->assertProviderDeletable($this->supplier_b);

        $this->expectException(WaiverException::class);
        $this->waiver->assertProviderDeletable($this->supplier_a);
    }

    // ------------------------------------------------------------------
    // Month close and history
    // ------------------------------------------------------------------

    public function test_new_month_starts_at_zero_and_closing_is_idempotent(): void
    {
        $this->pay($this->purchase($this->supplier_a, 1000), 1000);

        Carbon::setTestNow(Carbon::create(2026, 10, 1, 0, 10));

        // The reset does not depend on the close having run
        $this->assertEquals(0.0, $this->waiver->balance($this->supplier_a));

        $this->assertSame(1, $this->waiver->closePastPeriods());
        $this->assertSame(0, $this->waiver->closePastPeriods());

        $expires = Waiver::where('type', Waiver::TYPE_EXPIRE)->get();
        $this->assertCount(1, $expires);
        $this->assertEquals(-50.0, $expires[0]->amount);
        $this->assertSame('2026-09', $expires[0]->period);
        $this->assertEquals(0.0, $this->waiver->balance($this->supplier_a, '2026-09'));
    }

    public function test_close_writes_one_expire_entry_per_supplier_with_a_balance(): void
    {
        $this->pay($this->purchase($this->supplier_a, 1000), 1000);
        $this->pay($this->purchase($this->supplier_b, 2000), 2000);
        $supplier_c = DB::table('providers')->insertGetId(['name' => 'Supplier C']);
        $this->pay($this->purchase($supplier_c, 300), 100);

        Carbon::setTestNow(Carbon::create(2026, 10, 1, 0, 10));
        $this->artisan('waiver:close-period', ['period' => '2026-09'])->assertExitCode(0);

        $this->assertSame(2, Waiver::where('type', Waiver::TYPE_EXPIRE)->count());
        $this->assertSame(1, Waiver::where('type', Waiver::TYPE_EXPIRE)->where('provider_id', $this->supplier_a)->count());
        $this->assertSame(1, Waiver::where('type', Waiver::TYPE_EXPIRE)->where('provider_id', $this->supplier_b)->count());
    }

    public function test_past_period_summary_is_kept(): void
    {
        $this->pay($this->purchase($this->supplier_a, 1000), 1000);
        $this->pay($this->purchase($this->supplier_a, 500), 20, $this->waiver_method_id);
        $this->pay($this->purchase($this->supplier_b, 2000), 2000);

        Carbon::setTestNow(Carbon::create(2026, 10, 1, 0, 10));
        $this->waiver->closePastPeriods();

        $this->assertSame(
            ['earned' => 50.0, 'used' => 20.0, 'expired' => 30.0, 'balance' => 0.0],
            $this->waiver->summary('2026-09', $this->supplier_a)
        );
        $this->assertSame(
            ['earned' => 150.0, 'used' => 20.0, 'expired' => 130.0, 'balance' => 0.0],
            $this->waiver->summary('2026-09')
        );
        $this->assertSame(
            ['earned' => 0.0, 'used' => 0.0, 'expired' => 0.0, 'balance' => 0.0],
            $this->waiver->summary('2026-10')
        );
    }

    public function test_payment_back_dated_into_a_closed_month_earns_nothing_spendable(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 2, 9));

        $this->pay($this->purchase($this->supplier_a, 1000), 1000, null, '2026-09-20');

        $this->assertEquals(0.0, $this->waiver->balance($this->supplier_a));
        $this->assertEquals(0.0, $this->waiver->balance($this->supplier_a, '2026-09'));
        $this->assertSame(1, Waiver::where('type', Waiver::TYPE_EARN)->where('period', '2026-09')->count());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function purchase(int $provider_id, float $total, string $statut = 'received'): Purchase
    {
        static $number = 0;
        $number++;

        return Purchase::create([
            'date' => Carbon::now()->toDateString(),
            'Ref' => 'PR_'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            'provider_id' => $provider_id,
            'GrandTotal' => $total,
            'paid_amount' => 0,
            'statut' => $statut,
            'payment_statut' => 'unpaid',
        ]);
    }

    protected function pay(Purchase $purchase, float $amount, ?int $method_id = null, ?string $date = null): PaymentPurchase
    {
        $method_id = $method_id ?: $this->cash_method_id;

        return DB::transaction(function () use ($purchase, $amount, $method_id, $date) {
            $purchase = Purchase::findOrFail($purchase->id);
            $due_before = $purchase->GrandTotal - $purchase->paid_amount;

            $payment = PaymentPurchase::create([
                'purchase_id' => $purchase->id,
                'Ref' => 'INV/PR_'.uniqid(),
                'date' => $date ?: Carbon::now()->toDateString(),
                'payment_method_id' => $method_id,
                'montant' => $amount,
                'change' => 0,
            ]);

            $this->savePaidAmount($purchase, $purchase->paid_amount + $amount);

            if ($this->waiver->isWaiverMethod($method_id)) {
                $this->waiver->redeem($payment, $due_before);
            }
            $this->waiver->syncPurchase($purchase->id);

            return $payment;
        });
    }

    protected function deletePayment(PaymentPurchase $payment): void
    {
        DB::transaction(function () use ($payment) {
            $purchase = Purchase::findOrFail($payment->purchase_id);

            $payment->delete();
            $this->savePaidAmount($purchase, $purchase->paid_amount - $payment->montant);

            $this->waiver->reverseRedeem($payment);
            $this->waiver->syncPurchase($purchase->id);
        });
    }

    protected function deletePurchases(array $purchases): void
    {
        DB::transaction(function () use ($purchases) {
            foreach ($purchases as $purchase) {
                foreach (PaymentPurchase::where('purchase_id', $purchase->id)->get() as $payment) {
                    $this->waiver->reverseRedeem($payment);
                    $payment->delete();
                }

                Purchase::whereId($purchase->id)->update(['deleted_at' => Carbon::now()]);
                $this->waiver->syncPurchase($purchase->id);
            }
        });
    }

    protected function savePaidAmount(Purchase $purchase, float $paid): void
    {
        $due = $purchase->GrandTotal - $paid;

        $purchase->update([
            'paid_amount' => $paid,
            'payment_statut' => $due <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ]);
    }

    protected function createTables(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('wastage_waiver_rate', 5, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_waiver')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('providers', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->date('date');
            $table->string('Ref');
            $table->integer('provider_id');
            $table->integer('warehouse_id')->nullable();
            $table->double('GrandTotal')->default(0);
            $table->double('paid_amount')->default(0);
            $table->string('statut');
            $table->string('payment_statut');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_purchases', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('purchase_id');
            $table->integer('user_id')->nullable();
            $table->integer('account_id')->nullable();
            $table->integer('payment_method_id')->nullable();
            $table->date('date');
            $table->string('Ref');
            $table->double('montant');
            $table->double('change')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        require_once database_path('migrations/2026_10_07_000001_create_wastage_waiver_transactions_table.php');
        (new \CreateWastageWaiverTransactionsTable)->up();
    }
}
