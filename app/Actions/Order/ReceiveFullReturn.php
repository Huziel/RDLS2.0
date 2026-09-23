<?php

namespace App\Actions\Order;

use App\Actions\Inventory\RestoreInventory;
use App\Models\Cart;
use App\Models\OrderPayment;
use App\Models\OrderReturn;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Services\OrderIdempotency;
use Illuminate\Support\Facades\DB;

class ReceiveFullReturn
{
    public function __construct(
        private readonly RestoreInventory $restoreInventory,
        private readonly OrderIdempotency $idempotency,
    ) {}

    public function __invoke(PurchaseOrder $order, int $storeId, int $actorId, string $key, bool $restockable): array
    {
        $hash = $this->idempotency->hash(['restockable' => $restockable]);
        $eventKey = $this->idempotency->eventKey($order, 'return', $key);

        return DB::transaction(function () use ($order, $storeId, $actorId, $restockable, $hash, $eventKey) {
            $fresh = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $fresh->shippingOrder()->lockForUpdate()->first();
            $payment = OrderPayment::where('order_id', $fresh->id)->lockForUpdate()->first();
            $return = OrderReturn::where('order_id', $fresh->id)->lockForUpdate()->first();
            if ($payment && (int) $payment->store_id !== $storeId) {
                return ['error' => 'La orden no tiene un retorno completo pendiente.', 'status_code' => 404];
            }
            $event = $this->idempotency->find($eventKey, $hash);
            if ($event) {
                return array_merge($event->response_data, ['idempotent' => true]);
            }
            if (! $fresh->isCancelled() || ! $return || $return->status !== 'pending' || (int) $return->store_id !== $storeId) {
                return ['error' => 'La orden no tiene un retorno completo pendiente.', 'status_code' => 409];
            }

            $items = Cart::where('orderC', $fresh->order)->orderBy('id')->lockForUpdate()->get();
            if ($restockable) {
                ProductStock::whereIn('idProd', $items->pluck('product')->filter()->unique()->sort()->values())
                    ->orderBy('idProd')->lockForUpdate()->get();
            }
            $restocked = false;
            $restockKey = null;
            if ($restockable) {
                $restockKey = hash('sha256', 'return-restock:'.$fresh->order);
                if ($fresh->restock_key === null && $return->restock_key === null) {
                    ($this->restoreInventory)($items->groupBy('product')->map(fn ($rows) => (float) $rows->sum('cant')));
                    $fresh->update(['restock_key' => $restockKey]);
                    $restocked = true;
                }
            }

            $status = $restockable ? 'received_restocked' : 'received_not_restockable';
            $return->update([
                'status' => $status,
                'received_by' => $actorId,
                'received_at' => now(),
                'restock_key' => $restockKey,
            ]);
            $response = ['order' => $fresh->order, 'status' => $status, 'restocked' => $restocked];
            $this->idempotency->record($fresh, $storeId, $actorId, 'store_owner', 'full_return_received', $eventKey, $hash, ['status' => 'pending'], ['status' => $status], 200, $response);

            return $response + ['idempotent' => false];
        });
    }
}
