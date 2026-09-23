<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_TABLES = [
        'order_provider_transactions',
        'order_payment_proofs',
        'order_returns',
        'order_audit_events',
        'order_payments',
    ];

    public function up(): void
    {
        $this->preflight();

        if (! Schema::hasColumn('ordencompra', 'delivery_type')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->string('delivery_type', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('ordenenvio', 'departure_state')) {
            Schema::table('ordenenvio', function (Blueprint $table) {
                $table->string('departure_state', 20)->nullable();
            });
        }
        if (! Schema::hasColumn('ordenenvio', 'departed_at')) {
            Schema::table('ordenenvio', function (Blueprint $table) {
                $table->timestamp('departed_at')->nullable();
            });
        }

        DB::table('ordenenvio')->whereNull('departure_state')->update([
            'departure_state' => DB::raw("CASE WHEN status = '0' THEN 'not_departed' WHEN status IN ('1','2','3') THEN 'departed' ELSE 'unknown' END"),
        ]);
        Schema::table('ordenenvio', function (Blueprint $table) {
            $table->string('departure_state', 20)->nullable(false)->default('not_departed')->change();
        });

        if (! Schema::hasTable('order_payments')) {
            Schema::create('order_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->unique();
                $table->unsignedBigInteger('store_id')->index();
                $table->string('method', 30);
                $table->string('terms', 10);
                $table->string('status', 30)->default('pending')->index();
                $table->string('currency', 3)->default('MXN');
                $table->decimal('products_amount', 14, 2)->default(0);
                $table->decimal('discount_amount', 14, 2)->default(0);
                $table->decimal('shipping_amount', 14, 2)->default(0);
                $table->decimal('extra_amount', 14, 2)->default(0);
                $table->decimal('amount_due', 14, 2)->default(0);
                $table->decimal('amount_paid', 14, 2)->default(0);
                $table->decimal('amount_refunded', 14, 2)->default(0);
                $table->timestamp('frozen_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('refund_requested_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->unsignedBigInteger('refunded_by')->nullable();
                $table->string('bank_reference', 190)->nullable();
                $table->string('cash_reference', 190)->nullable();
                $table->string('refund_reference', 190)->nullable();
                $table->string('provider', 30)->nullable();
                $table->string('provider_payment_id', 190)->nullable();
                $table->timestamps();
                $table->unique(['provider', 'provider_payment_id'], 'order_payment_provider_unique');
            });
        } else {
            $this->assertColumns('order_payments', ['order_id', 'store_id', 'method', 'terms', 'status', 'amount_due']);
        }

        if (! Schema::hasTable('order_provider_transactions')) {
            Schema::create('order_provider_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('payment_id')->index();
                $table->unsignedBigInteger('store_id')->index();
                $table->unsignedBigInteger('order_id')->index();
                $table->string('provider', 30);
                $table->string('provider_payment_id', 190);
                $table->string('preference_id', 190)->nullable();
                $table->decimal('amount', 14, 2);
                $table->string('currency', 3);
                $table->string('remote_status', 40);
                $table->timestamps();
                $table->unique(['provider', 'provider_payment_id'], 'provider_transaction_unique');
            });
        } else {
            $this->assertColumns('order_provider_transactions', ['payment_id', 'store_id', 'order_id', 'provider', 'provider_payment_id', 'amount']);
        }

        if (! Schema::hasTable('order_payment_proofs')) {
            Schema::create('order_payment_proofs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('payment_id')->index();
                $table->unsignedBigInteger('store_id')->index();
                $table->string('disk', 50);
                $table->string('path', 255)->unique();
                $table->string('mime', 100);
                $table->unsignedBigInteger('size');
                $table->string('sha256', 64);
                $table->string('reference', 190);
                $table->timestamps();
            });
        } else {
            $this->assertColumns('order_payment_proofs', ['payment_id', 'store_id', 'disk', 'path', 'sha256']);
        }

        if (! Schema::hasTable('order_returns')) {
            Schema::create('order_returns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->unique();
                $table->unsignedBigInteger('store_id')->index();
                $table->string('status', 40)->default('pending');
                $table->unsignedBigInteger('received_by')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->string('restock_key', 64)->nullable()->unique();
                $table->timestamps();
            });
        } else {
            $this->assertColumns('order_returns', ['order_id', 'store_id', 'status', 'restock_key']);
        }

        if (! Schema::hasTable('order_audit_events')) {
            Schema::create('order_audit_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('store_id')->index();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('actor_type', 30);
                $table->string('event_type', 60)->index();
                $table->string('event_key', 191)->unique();
                $table->string('request_hash', 64);
                $table->json('previous')->nullable();
                $table->json('next')->nullable();
                $table->unsignedSmallInteger('response_status');
                $table->json('response_data')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        } else {
            $this->assertColumns('order_audit_events', ['order_id', 'store_id', 'event_key', 'request_hash', 'response_data']);
        }

        $this->backfillPayments();

        if (! Schema::hasIndex('mercadopagocuentas', 'mercado_pago_merchant_unique')) {
            Schema::table('mercadopagocuentas', function (Blueprint $table) {
                $table->unique('merchantId', 'mercado_pago_merchant_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (self::NEW_TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing destructive rollback: {$table} contains financial or audit data.");
            }
        }

        foreach (self::NEW_TABLES as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasTable('ordenenvio') && Schema::hasColumn('ordenenvio', 'departure_state')) {
            Schema::table('ordenenvio', function (Blueprint $table) {
                $table->dropColumn(['departure_state', 'departed_at']);
            });
        }
        if (Schema::hasTable('ordencompra') && Schema::hasColumn('ordencompra', 'delivery_type')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->dropColumn('delivery_type');
            });
        }
        if (Schema::hasTable('mercadopagocuentas') && Schema::hasIndex('mercadopagocuentas', 'mercado_pago_merchant_unique')) {
            Schema::table('mercadopagocuentas', function (Blueprint $table) {
                $table->dropUnique('mercado_pago_merchant_unique');
            });
        }
    }

    private function preflight(): void
    {
        $required = ['ordencompra', 'ordenenvio', 'liks', 'cart', 'gastosextras', 'mercadopago', 'mercadopagocuentas'];
        $missing = array_values(array_filter($required, fn (string $table) => ! Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException('FASE 8A preflight: missing base tables: '.implode(', ', $missing).'.');
        }

        $this->rejectDuplicates('ordencompra', 'order', 'purchase-order reference');
        $this->rejectDuplicates('ordenenvio', 'ordenCompra', 'shipping order');
        $this->rejectDuplicates('mercadopagocuentas', 'merchantId', 'MercadoPago merchant', true);
        if (DB::table('mercadopago')->where('status', 1)->whereNotNull('payment_id')
            ->groupBy('payment_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('FASE 8A preflight: duplicate approved MercadoPago payment IDs.');
        }

        if (DB::table('ordencompra as o')->leftJoin('liks as s', 's.serial', '=', 'o.serial')->whereNull('s.id')->exists()) {
            throw new RuntimeException('FASE 8A preflight: purchase orders without a matching store cannot be backfilled.');
        }

        $orphans = [
            'mercadopago' => DB::table('mercadopago as mp')->leftJoin('ordencompra as o', 'o.order', '=', 'mp.orderP')->whereNull('o.id')->exists(),
            'cart' => DB::table('cart as c')->leftJoin('ordencompra as o', 'o.order', '=', 'c.orderC')->whereNotNull('c.orderC')->whereNull('o.id')->exists(),
            'gastosextras' => DB::table('gastosextras as e')->leftJoin('ordencompra as o', 'o.order', '=', 'e.orderP')->whereNull('o.id')->exists(),
        ];
        $badOrphans = array_keys(array_filter($orphans));
        if ($badOrphans !== []) {
            throw new RuntimeException('FASE 8A preflight: orphan rows found in '.implode(', ', $badOrphans).'.');
        }

        DB::table('ordencompra')->orderBy('id')->chunkById(500, function ($orders) {
            foreach ($orders as $order) {
                foreach (['total', 'totEnvio', 'loyalty_discount'] as $column) {
                    if (! $this->validMoney($order->{$column} ?? 0)) {
                        throw new RuntimeException("FASE 8A preflight: invalid monetary value at ordencompra.{$column}, id {$order->id}.");
                    }
                }
            }
        });
        DB::table('gastosextras')->orderBy('id')->chunkById(500, function ($extras) {
            foreach ($extras as $extra) {
                if (! $this->validMoney($extra->precio ?? null)) {
                    throw new RuntimeException("FASE 8A preflight: invalid monetary value at gastosextras.precio, id {$extra->id}.");
                }
            }
        });
        DB::table('cart')->orderBy('id')->chunkById(500, function ($items) {
            foreach ($items as $item) {
                if (! $this->validMoney($item->price ?? null)) {
                    throw new RuntimeException("FASE 8A preflight: invalid monetary value at cart.price, id {$item->id}.");
                }
            }
        });
    }

    private function rejectDuplicates(string $table, string $column, string $label, bool $ignoreBlank = false): void
    {
        $query = DB::table($table)->select($column)->whereNotNull($column);
        if ($ignoreBlank) {
            $query->where($column, '!=', '');
        }
        if ($query->groupBy($column)->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException("FASE 8A preflight: duplicate {$label} values in {$table}.{$column}.");
        }
    }

    private function validMoney(mixed $value): bool
    {
        try {
            $this->moneyToCents($value);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function moneyToCents(mixed $value): int
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,12}(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new RuntimeException('Monetary value must fit unsigned decimal(14,2).');
        }
        $fraction = str_pad($matches[1] ?? '', 2, '0');
        $cents = ((int) explode('.', $value, 2)[0] * 100) + (int) $fraction;
        if ($cents > 99999999999999) {
            throw new RuntimeException('Monetary value exceeds decimal(14,2).');
        }

        return $cents;
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function backfillPayments(): void
    {
        DB::table('ordencompra')->orderBy('id')->chunkById(200, function ($orders) {
            foreach ($orders as $order) {
                $paymentId = DB::table('order_payments')->where('order_id', $order->id)->value('id');

                $storeId = DB::table('liks')->where('serial', $order->serial)->value('id');
                $items = DB::table('cart')->where('orderC', $order->order)->orderBy('id')->get();
                $products = 0;
                foreach ($items as $item) {
                    $products += $this->moneyToCents($item->price);
                }
                $discount = $this->moneyToCents($order->loyalty_discount ?? 0);
                $shipping = $this->moneyToCents($order->totEnvio ?? 0);
                $extras = 0;
                foreach (DB::table('gastosextras')->where('orderP', $order->order)->orderBy('id')->get() as $extra) {
                    $extras += $this->moneyToCents($extra->precio);
                }
                $legacyTotal = $this->moneyToCents($order->total);
                if ($items->isEmpty()) {
                    $products = $legacyTotal + $discount;
                }
                if ($products - $discount !== $legacyTotal || $products - $discount + $shipping + $extras < 0) {
                    throw new RuntimeException("FASE 8A preflight: order {$order->id} has a non-representable monetary contradiction.");
                }

                $legacyMp = DB::table('mercadopago')->where('orderP', $order->order)->first();
                $paid = (string) ($order->order_state ?? '') === 'paid'
                    || $items->contains(fn ($item) => (string) $item->status === '3')
                    || (int) ($legacyMp->status ?? 0) === 1;
                $cancelled = (string) ($order->order_state ?? '') === 'cancelled' || $order->cancelled_at !== null;
                $due = $products - $discount + $shipping + $extras;
                if ($paymentId === null) {
                    $paymentId = DB::table('order_payments')->insertGetId([
                        'order_id' => $order->id,
                        'store_id' => $storeId,
                        'method' => 'legacy_unknown',
                        'terms' => 'prepaid',
                        'status' => $paid && $cancelled ? 'refund_pending' : ($paid ? 'paid' : 'pending'),
                        'currency' => 'MXN',
                        'products_amount' => $this->money($products),
                        'discount_amount' => $this->money($discount),
                        'shipping_amount' => $this->money($shipping),
                        'extra_amount' => $this->money($extras),
                        'amount_due' => $this->money($due),
                        'amount_paid' => $this->money($paid ? $due : 0),
                        'amount_refunded' => '0.00',
                        'frozen_at' => $paid ? now() : null,
                        'paid_at' => $paid ? now() : null,
                        'refund_requested_at' => $paid && $cancelled ? now() : null,
                        'provider' => ($legacyMp && (int) $legacyMp->status === 1) ? 'mercado_pago' : null,
                        'provider_payment_id' => ($legacyMp && $legacyMp->payment_id !== null) ? (string) $legacyMp->payment_id : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if ($legacyMp && (int) $legacyMp->status === 1 && $legacyMp->payment_id !== null) {
                    DB::table('order_provider_transactions')->insertOrIgnore([
                        'payment_id' => $paymentId,
                        'store_id' => $storeId,
                        'order_id' => $order->id,
                        'provider' => 'mercado_pago',
                        'provider_payment_id' => (string) $legacyMp->payment_id,
                        'preference_id' => $legacyMp->preference ?: null,
                        'amount' => $this->money($due),
                        'currency' => 'MXN',
                        'remote_status' => 'approved',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    private function assertColumns(string $table, array $columns): void
    {
        $missing = array_values(array_filter($columns, fn (string $column) => ! Schema::hasColumn($table, $column)));
        if ($missing !== []) {
            throw new RuntimeException("FASE 8A retry found incomplete table {$table}: missing ".implode(', ', $missing).'.');
        }
    }
};
