<?php

namespace App\Services;

use App\Exceptions\WaiverException;
use App\Models\PaymentMethod;
use App\Models\PaymentPurchase;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\WastageWaiverTransaction as Waiver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Per-supplier wastage waiver ledger.
 *
 * A received purchase earns a waiver for its supplier when it becomes fully paid.
 * The waiver can pay purchases of the same supplier and expires at the end of the month.
 * Entries are append-only: a correction is always a new reversal entry.
 *
 * Every method that writes must be called inside the caller's DB transaction,
 * so a WaiverException rolls back the whole action.
 */
class WastageWaiverService
{
    const EPSILON = 0.005;

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    public function currentPeriod(): string
    {
        return Carbon::now()->format('Y-m');
    }

    public function rate(): float
    {
        $settings = Setting::first();

        return (float) ($settings->wastage_waiver_rate ?? 5);
    }

    public function waiverMethodId(): ?int
    {
        $id = PaymentMethod::withWaiver()->where('is_waiver', 1)->whereNull('deleted_at')->value('id');

        return $id ? (int) $id : null;
    }

    public function isWaiverMethod($payment_method_id): bool
    {
        return $payment_method_id && (int) $payment_method_id === $this->waiverMethodId();
    }

    /** Spendable waiver of a supplier in a period (the current month by default). */
    public function balance(int $provider_id, ?string $period = null): float
    {
        return round((float) Waiver::where('provider_id', $provider_id)
            ->where('period', $period ?: $this->currentPeriod())
            ->sum('amount'), 2);
    }

    /** Earned waiver of a supplier in a period that has not been used, whether or not it has expired since. */
    public function unspent(int $provider_id, string $period): float
    {
        return round((float) Waiver::where('provider_id', $provider_id)
            ->where('period', $period)
            ->where('type', '!=', Waiver::TYPE_EXPIRE)
            ->sum('amount'), 2);
    }

    public function activeEarn(int $purchase_id): ?Waiver
    {
        return $this->activeEntry(Waiver::TYPE_EARN, 'purchase_id', $purchase_id);
    }

    public function activeRedeem(int $payment_purchase_id): ?Waiver
    {
        return $this->activeEntry(Waiver::TYPE_REDEEM, 'payment_purchase_id', $payment_purchase_id);
    }

    public function purchaseHasActiveWaiver(int $purchase_id): bool
    {
        if ($this->activeEarn($purchase_id)) {
            return true;
        }

        return Waiver::from('wastage_waiver_transactions as w')
            ->where('w.purchase_id', $purchase_id)
            ->where('w.type', Waiver::TYPE_REDEEM)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('wastage_waiver_transactions as r')
                    ->whereColumn('r.reversal_of_id', 'w.id');
            })
            ->exists();
    }

    /** Earned, reversed, used, expired and closing balance of a period, for one supplier or all. */
    public function summary(string $period, ?int $provider_id = null): array
    {
        $totals = Waiver::where('period', $period)
            ->when($provider_id, fn ($q) => $q->where('provider_id', $provider_id))
            ->selectRaw('type, COALESCE(SUM(amount), 0) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $earned = (float) ($totals[Waiver::TYPE_EARN] ?? 0);
        $reversed = (float) ($totals[Waiver::TYPE_EARN_REVERSAL] ?? 0);
        $used = (float) ($totals[Waiver::TYPE_REDEEM] ?? 0) + (float) ($totals[Waiver::TYPE_REDEEM_REVERSAL] ?? 0);
        $expired = (float) ($totals[Waiver::TYPE_EXPIRE] ?? 0);

        return [
            'earned' => round($earned + $reversed, 2),
            'used' => round(-$used, 2),
            'expired' => round(-$expired, 2),
            'balance' => round($earned + $reversed + $used + $expired, 2),
        ];
    }

    // ------------------------------------------------------------------
    // Earning
    // ------------------------------------------------------------------

    /**
     * Bring the waiver earned by a purchase in line with its current state.
     * Call after anything that changes the purchase's payments, total, status or deletion.
     */
    public function syncPurchase(int $purchase_id): void
    {
        $purchase = Purchase::find($purchase_id);
        if (! $purchase || ! $purchase->provider_id) {
            return;
        }

        $this->lockProvider($purchase->provider_id);

        $active = $this->activeEarn($purchase->id);
        $rate = $active ? (float) $active->rate : $this->rate();
        $base = $this->earnBase($purchase);
        $amount = $this->shouldEarn($purchase) ? round($base * $rate / 100, 2) : 0.0;

        if (! $active) {
            if ($amount > 0) {
                $date = $this->earnDate($purchase);
                $this->post($purchase->provider_id, $date->format('Y-m'), $date, Waiver::TYPE_EARN, $amount, [
                    'rate' => $rate,
                    'base_amount' => $base,
                    'purchase_id' => $purchase->id,
                ]);
            }

            return;
        }

        if (abs($amount - $active->amount) < self::EPSILON) {
            return;
        }

        // The purchase is no longer paid, or its earning base changed
        $this->assertUnspent($purchase, $active, $active->amount - $amount);

        $this->post($active->provider_id, $active->period, Carbon::now(), Waiver::TYPE_EARN_REVERSAL, -$active->amount, [
            'purchase_id' => $purchase->id,
            'reversal_of_id' => $active->id,
        ]);

        if ($amount > 0) {
            // Stays in the month it was first earned in, so an edit never moves waiver between months
            $this->post($active->provider_id, $active->period, Carbon::parse($active->date), Waiver::TYPE_EARN, $amount, [
                'rate' => $rate,
                'base_amount' => $base,
                'purchase_id' => $purchase->id,
            ]);
        }
    }

    /** Refuse an action that would remove waiver its supplier has already used. */
    protected function assertUnspent(Purchase $purchase, Waiver $earn, float $needed): void
    {
        if ($needed <= self::EPSILON) {
            return;
        }

        $unspent = $this->unspent($earn->provider_id, $earn->period);
        if ($unspent + self::EPSILON >= $needed) {
            return;
        }

        $supplier = Provider::where('id', $earn->provider_id)->value('name') ?: '#'.$earn->provider_id;
        $used = min($needed, $needed - max($unspent, 0));

        throw new WaiverException(sprintf(
            'This action is not allowed because the waiver balance of %s would become negative. '
            .'Purchase %s earned a waiver of %s in %s, and %s of it has already been used. '
            .'Delete the waiver payments made with it first.',
            $supplier,
            $purchase->Ref,
            number_format($earn->amount, 2),
            Carbon::createFromFormat('Y-m-d', $earn->period.'-01')->format('F Y'),
            number_format($used, 2)
        ));
    }

    protected function shouldEarn(Purchase $purchase): bool
    {
        return is_null($purchase->deleted_at)
            && $purchase->statut === 'received'
            && $purchase->payment_statut === 'paid';
    }

    /** The part of the purchase paid by waiver does not earn new waiver. */
    protected function earnBase(Purchase $purchase): float
    {
        $waiver_method_id = $this->waiverMethodId();
        $paid_by_waiver = $waiver_method_id
            ? (float) PaymentPurchase::where('purchase_id', $purchase->id)
                ->where('payment_method_id', $waiver_method_id)
                ->sum('montant')
            : 0.0;

        return round(max(0, (float) $purchase->GrandTotal - $paid_by_waiver), 2);
    }

    /** Date of the payment that completed the purchase; never in the future. */
    protected function earnDate(Purchase $purchase): Carbon
    {
        $last_payment_date = PaymentPurchase::where('purchase_id', $purchase->id)->max('date');
        $date = $last_payment_date ? Carbon::parse($last_payment_date) : Carbon::now();

        return $date->greaterThan(Carbon::now()) ? Carbon::now() : $date;
    }

    // ------------------------------------------------------------------
    // Paying with waiver
    // ------------------------------------------------------------------

    /**
     * Record a purchase payment made with the waiver method.
     *
     * @param  float  $due_before  what the purchase still owed before this payment
     */
    public function redeem(PaymentPurchase $payment, float $due_before): void
    {
        $purchase = Purchase::findOrFail($payment->purchase_id);
        $amount = round((float) $payment->montant, 2);

        $this->lockProvider($purchase->provider_id);

        if ($amount > $due_before + self::EPSILON) {
            throw new WaiverException(sprintf(
                'A waiver payment cannot be greater than the amount due on the purchase (%s).',
                number_format(max($due_before, 0), 2)
            ));
        }

        $balance = $this->balance($purchase->provider_id);
        if ($amount > $balance + self::EPSILON) {
            $supplier = Provider::where('id', $purchase->provider_id)->value('name') ?: '#'.$purchase->provider_id;

            throw new WaiverException(sprintf(
                'Not enough waiver balance for %s. Available this month: %s, requested: %s.',
                $supplier,
                number_format(max($balance, 0), 2),
                number_format($amount, 2)
            ));
        }

        // Waiver is always spent from the current month, whatever date the payment carries
        $this->post($purchase->provider_id, $this->currentPeriod(), Carbon::now(), Waiver::TYPE_REDEEM, -$amount, [
            'purchase_id' => $purchase->id,
            'payment_purchase_id' => $payment->id,
        ]);
    }

    /** Give back the waiver used by a payment that is being edited or deleted. */
    public function reverseRedeem(PaymentPurchase $payment): void
    {
        $redeem = $this->activeRedeem($payment->id);
        if (! $redeem) {
            return;
        }

        if ($redeem->period !== $this->currentPeriod()) {
            throw new WaiverException(sprintf(
                'Payment %s was made with waiver in %s. That month is closed and its waiver has expired, '
                .'so this payment can no longer be changed or deleted.',
                $payment->Ref,
                Carbon::createFromFormat('Y-m-d', $redeem->period.'-01')->format('F Y')
            ));
        }

        $this->lockProvider($redeem->provider_id);

        $this->post($redeem->provider_id, $redeem->period, Carbon::now(), Waiver::TYPE_REDEEM_REVERSAL, -$redeem->amount, [
            'purchase_id' => $redeem->purchase_id,
            'payment_purchase_id' => $payment->id,
            'reversal_of_id' => $redeem->id,
        ]);
    }

    // ------------------------------------------------------------------
    // Guards used by controllers
    // ------------------------------------------------------------------

    public function assertSupplierUnchanged(Purchase $purchase, $new_provider_id): void
    {
        if ((int) $purchase->provider_id !== (int) $new_provider_id && $this->purchaseHasActiveWaiver($purchase->id)) {
            throw new WaiverException(sprintf(
                'The supplier of purchase %s cannot be changed because it has earned or used waiver. '
                .'Delete its payments first.',
                $purchase->Ref
            ));
        }
    }

    public function assertProviderDeletable(int $provider_id): void
    {
        $balance = $this->balance($provider_id);
        if (abs($balance) >= self::EPSILON) {
            $supplier = Provider::where('id', $provider_id)->value('name') ?: '#'.$provider_id;

            throw new WaiverException(sprintf(
                'Supplier %s cannot be deleted while it has a waiver balance of %s this month.',
                $supplier,
                number_format($balance, 2)
            ));
        }
    }

    // ------------------------------------------------------------------
    // Month close
    // ------------------------------------------------------------------

    /**
     * Expire whatever is left in closed months, one entry per supplier and month.
     * Safe to run any number of times.
     *
     * @return int number of expiry entries written
     */
    public function closePastPeriods(?string $only_period = null): int
    {
        $current = $this->currentPeriod();

        $open = Waiver::where('period', '<', $current)
            ->when($only_period, fn ($q) => $q->where('period', $only_period))
            ->selectRaw('provider_id, period, SUM(amount) as balance')
            ->groupBy('provider_id', 'period')
            ->havingRaw('ABS(SUM(amount)) >= '.self::EPSILON)
            ->get();

        foreach ($open as $row) {
            $this->settle((int) $row->provider_id, $row->period);
        }

        return $open->count();
    }

    /** Bring a closed month back to zero after an entry was posted into it. */
    protected function settle(int $provider_id, string $period): void
    {
        if ($period >= $this->currentPeriod()) {
            return;
        }

        $balance = $this->balance($provider_id, $period);
        if (abs($balance) < self::EPSILON) {
            return;
        }

        Waiver::create([
            'provider_id' => $provider_id,
            'period' => $period,
            'date' => Carbon::createFromFormat('Y-m-d', $period.'-01')->endOfMonth()->toDateString(),
            'type' => Waiver::TYPE_EXPIRE,
            'amount' => -$balance,
            'note' => 'Month closed',
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    protected function post(int $provider_id, string $period, Carbon $date, string $type, float $amount, array $extra = []): Waiver
    {
        $entry = Waiver::create(array_merge([
            'provider_id' => $provider_id,
            'period' => $period,
            'date' => $date->toDateString(),
            'type' => $type,
            'amount' => round($amount, 2),
            'user_id' => Auth::id(),
        ], $extra));

        // Waiver posted into a closed month has already expired
        $this->settle($provider_id, $period);

        return $entry;
    }

    protected function activeEntry(string $type, string $column, int $id): ?Waiver
    {
        return Waiver::from('wastage_waiver_transactions as w')
            ->where('w.'.$column, $id)
            ->where('w.type', $type)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('wastage_waiver_transactions as r')
                    ->whereColumn('r.reversal_of_id', 'w.id');
            })
            ->orderByDesc('w.id')
            ->select('w.*')
            ->first();
    }

    /** Serialise waiver movements of one supplier for the rest of the transaction. */
    protected function lockProvider(int $provider_id): void
    {
        Provider::where('id', $provider_id)->lockForUpdate()->first(['id']);
    }
}
