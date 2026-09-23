<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\OrderPaymentProof;
use App\Models\PurchaseOrder;
use App\Models\ShippingOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class OrderFinanceFlowTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
    }

    public function test_checkout_persists_cash_and_cod_terms_and_owner_confirms_server_amount(): void
    {
        [$owner, $store] = $this->signInStore('manual-payments@example.test');

        $cash = $this->checkout($store, 'cash-cart', 'cash', 'pickup', 25);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $cash->id,
            'method' => 'cash',
            'terms' => 'prepaid',
            'status' => 'pending',
            'amount_due' => 25,
        ]);
        $this->withHeaders(['X-Cart-Token' => 'cash-cart', 'Idempotency-Key' => 'checkout-cash-cart'])
            ->postJson("/api/v1/stores/{$store->serial}/checkout", $this->checkoutPayload('bank_transfer', 'pickup'))
            ->assertConflict();
        $this->withHeader('Idempotency-Key', 'cash-confirm')
            ->putJson("/api/v1/orders/{$cash->id}/confirm-payment", ['reference' => 'CASH-REGISTER-1'])
            ->assertOk()
            ->assertJsonPath('data.status', '3');
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $cash->id,
            'status' => 'paid',
            'amount_paid' => 25,
            'paid_by' => $owner->id,
            'cash_reference' => 'CASH-REGISTER-1',
        ]);

        $cod = $this->checkout($store, 'cod-cart', 'cash_on_delivery', 'shipping', 30);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $cod->id,
            'method' => 'cash_on_delivery',
            'terms' => 'cod',
            'amount_due' => 40,
        ]);
        $this->withHeader('Idempotency-Key', 'cod-confirm')
            ->putJson("/api/v1/orders/{$cod->id}/confirm-payment")
            ->assertOk();
        $this->assertSame('paid', $cod->payment()->value('status'));

        $this->cart($store, 'bad-cod', 10);
        $this->withHeaders(['X-Cart-Token' => 'bad-cod', 'Idempotency-Key' => 'bad-cod'])
            ->postJson("/api/v1/stores/{$store->serial}/checkout", $this->checkoutPayload('cash_on_delivery', 'pickup'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $mp = $this->checkout($store, 'manual-mp', 'mercado_pago', 'pickup', 15);
        $this->withHeader('Idempotency-Key', 'manual-mp-confirm')
            ->putJson("/api/v1/orders/{$mp->id}/confirm-payment")
            ->assertUnprocessable();
        $this->assertSame('pending', $mp->payment()->value('status'));

        $this->cart($store, 'missing-method', 10);
        $missingMethod = $this->checkoutPayload('cash', 'pickup');
        unset($missingMethod['payment_method']);
        $this->withHeaders(['X-Cart-Token' => 'missing-method', 'Idempotency-Key' => 'missing-method'])
            ->postJson("/api/v1/stores/{$store->serial}/checkout", $missingMethod)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_transfer_proof_is_private_append_only_and_does_not_pay_until_owner_confirms(): void
    {
        [, $store] = $this->signInStore('transfer-owner@example.test');
        Storage::fake('payment-proofs');
        $order = $this->checkout($store, 'transfer-cart', 'bank_transfer', 'pickup', 45);

        $proof = $this->png('../invoice.php.jpg');
        $response = $this->withHeaders([
            'X-Cart-Token' => 'transfer-cart',
            'Idempotency-Key' => 'proof-one',
        ])->post("/api/v1/stores/{$store->serial}/orders/{$order->order}/transfer-proof", [
            'reference' => 'BANK-REF-1',
            'proof' => $proof,
        ])->assertCreated()
            ->assertJsonMissingPath('data.path')
            ->assertJsonPath('data.status', 'proof_submitted');

        $proofId = $response->json('data.proof_id');
        $stored = OrderPaymentProof::findOrFail($proofId);
        Storage::disk('payment-proofs')->assertExists($stored->path);
        $this->assertStringNotContainsString('php', $stored->path);
        $this->assertSame('proof_submitted', $order->payment()->value('status'));
        $this->assertNotNull($order->payment()->value('frozen_at'));
        $this->assertFalse($order->refresh()->isPaid());

        $this->withHeaders([
            'X-Cart-Token' => 'transfer-cart',
            'Idempotency-Key' => 'proof-one',
        ])->post("/api/v1/stores/{$store->serial}/orders/{$order->order}/transfer-proof", [
            'reference' => 'DIFFERENT',
            'proof' => $this->png('other.png'),
        ])->assertConflict();
        $this->assertDatabaseCount('order_payment_proofs', 1);

        $this->withHeader('Idempotency-Key', 'transfer-confirm')
            ->putJson("/api/v1/orders/{$order->id}/confirm-payment", ['proof_id' => $proofId])
            ->assertOk();
        $this->assertTrue($order->refresh()->isPaid());

        $this->withHeaders([
            'X-Cart-Token' => 'transfer-cart',
            'Idempotency-Key' => 'executable-proof',
        ])->post("/api/v1/stores/{$store->serial}/orders/{$order->order}/transfer-proof", [
            'reference' => 'BAD',
            'proof' => UploadedFile::fake()->create('shell.php', 1, 'application/x-php'),
        ])->assertUnprocessable();
    }

    public function test_transfer_proofs_are_capped_at_three_files_per_payment(): void
    {
        [, $store] = $this->signInStore('proof-cap@example.test');
        Storage::fake('payment-proofs');
        $order = $this->checkout($store, 'proof-cap-cart', 'bank_transfer', 'pickup', 12);

        foreach (range(1, 3) as $number) {
            $this->withHeaders([
                'X-Cart-Token' => 'proof-cap-cart',
                'Idempotency-Key' => "proof-cap-{$number}",
            ])->post("/api/v1/stores/{$store->serial}/orders/{$order->order}/transfer-proof", [
                'reference' => "REF-{$number}",
                'proof' => $this->png("proof-{$number}.png"),
            ])->assertCreated();
        }

        $this->withHeaders([
            'X-Cart-Token' => 'proof-cap-cart',
            'Idempotency-Key' => 'proof-cap-4',
        ])->post("/api/v1/stores/{$store->serial}/orders/{$order->order}/transfer-proof", [
            'reference' => 'REF-4',
            'proof' => $this->png('proof-4.png'),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('order_payment_proofs', 3);
    }

    public function test_departed_paid_cancellation_delays_stock_and_refund_and_return_are_independent(): void
    {
        [, $store] = $this->signInStore('refund-return@example.test');
        Http::fake();
        $order = $this->checkout($store, 'return-cart', 'cash', 'shipping', 50, 2, 2);
        $this->withHeader('Idempotency-Key', 'pay-before-cancel')
            ->putJson("/api/v1/orders/{$order->id}/confirm-payment")
            ->assertOk();
        ShippingOrder::create([
            'tienda' => $store->id,
            'ordenCompra' => $order->id,
            'fechaIn' => now(),
            'status' => '1',
            'assignment_mode' => 'direct',
            'departure_state' => 'departed',
            'departed_at' => now(),
        ]);

        $this->withHeader('Idempotency-Key', 'cancel-departed')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.restocked', false)
            ->assertJsonPath('data.return_pending', true);
        $this->assertDatabaseHas('order_payments', ['order_id' => $order->id, 'status' => 'refund_pending']);
        $this->assertDatabaseHas('order_returns', ['order_id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseHas('stock', ['idProd' => $order->cartItems()->value('product'), 'stock' => 0]);

        $this->withHeader('Idempotency-Key', 'receive-return')
            ->postJson("/api/v1/orders/{$order->id}/return", ['restockable' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'received_restocked')
            ->assertJsonPath('data.restocked', true);
        $this->assertSame('refund_pending', $order->payment()->value('status'));
        $this->assertDatabaseHas('stock', ['idProd' => $order->cartItems()->value('product'), 'stock' => 2]);

        $paid = (float) $order->payment()->value('amount_paid');
        $this->withHeader('Idempotency-Key', 'refund-without-external-reference')
            ->postJson("/api/v1/orders/{$order->id}/refund")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference');
        $this->withHeader('Idempotency-Key', 'full-refund')
            ->postJson("/api/v1/orders/{$order->id}/refund", ['reference' => 'CASH-REFUND-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded');
        $payment = $order->payment()->firstOrFail();
        $this->assertSame($paid, (float) $payment->amount_refunded);
        $this->assertLessThanOrEqual((float) $payment->amount_paid, (float) $payment->amount_refunded);
        Http::assertNothingSent();
    }

    public function test_non_restockable_full_return_records_receipt_without_stock_or_refund_mutation(): void
    {
        [, $store] = $this->signInStore('non-restockable@example.test');
        $order = $this->checkout($store, 'damaged-return', 'cash', 'shipping', 20, 1, 1);
        ShippingOrder::create([
            'tienda' => $store->id,
            'ordenCompra' => $order->id,
            'fechaIn' => now(),
            'status' => '4',
            'assignment_mode' => 'direct',
            'departure_state' => 'departed',
            'departed_at' => now(),
        ]);

        $this->withHeader('Idempotency-Key', 'cancel-damaged')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.return_pending', true);
        $this->withHeader('Idempotency-Key', 'receive-damaged')
            ->postJson("/api/v1/orders/{$order->id}/return", ['restockable' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'received_not_restockable')
            ->assertJsonPath('data.restocked', false);

        $this->assertDatabaseHas('stock', ['idProd' => $order->cartItems()->value('product'), 'stock' => 0]);
        $this->assertDatabaseHas('order_payments', ['order_id' => $order->id, 'status' => 'pending', 'amount_refunded' => 0]);
    }

    public function test_amount_snapshot_includes_discount_shipping_and_extras_then_freezes(): void
    {
        [, $store] = $this->signInStore('snapshot-owner@example.test');
        $order = $this->checkout($store, 'snapshot-cart', 'cash', 'shipping', 50);
        $order->update(['total' => 45, 'loyalty_discount' => 5]);

        $this->withHeader('Idempotency-Key', 'extra-seven')
            ->postJson("/api/v1/orders/{$order->id}/extra-charge", ['precio' => 7, 'tipo' => 'service'])
            ->assertOk()
            ->assertJsonPath('data.amount_due', '62.00');
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'products_amount' => 50,
            'discount_amount' => 5,
            'shipping_amount' => 10,
            'extra_amount' => 7,
            'amount_due' => 62,
        ]);

        $this->withHeader('Idempotency-Key', 'freeze-payment')
            ->putJson("/api/v1/orders/{$order->id}/confirm-payment")
            ->assertOk();
        $this->assertNotNull($order->payment()->value('frozen_at'));
        $this->withHeader('Idempotency-Key', 'extra-after-freeze')
            ->postJson("/api/v1/orders/{$order->id}/extra-charge", ['precio' => 1, 'tipo' => 'late'])
            ->assertConflict();
        $this->assertDatabaseCount('gastosextras', 1);
    }

    private function checkout($store, string $token, string $method, string $delivery, float $price, int $quantity = 1, int $stock = 5): PurchaseOrder
    {
        $this->cart($store, $token, $price, $quantity, $stock);
        $response = $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => 'checkout-'.$token])
            ->postJson("/api/v1/stores/{$store->serial}/checkout", $this->checkoutPayload($method, $delivery))
            ->assertCreated();

        return PurchaseOrder::findOrFail($response->json('data.id'));
    }

    private function cart($store, string $token, float $price, int $quantity = 1, int $stock = 5): Cart
    {
        $product = $this->createProduct($store->createdby, ['number' => (string) $price], $stock);

        return Cart::create([
            'product' => $product->id,
            'price' => $price * $quantity,
            'dom' => $store->createdby,
            'user' => $token,
            'variation' => $store->serial,
            'cant' => $quantity,
            'status' => 0,
        ]);
    }

    private function checkoutPayload(string $method, string $delivery): array
    {
        return [
            'nombre' => 'Cliente',
            'telefono' => '5551112222',
            'tipo_envio' => $delivery,
            'payment_method' => $method,
            'direccion' => $delivery === 'pickup' ? null : 'Direccion local',
        ];
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }
}
