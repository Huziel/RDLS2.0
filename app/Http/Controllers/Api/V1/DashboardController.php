<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\PosOrder;
use App\Models\PosOrderHistory;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\Store;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();
        $today = now()->format('Y-m-d');

        // Counts
        $productCount = Product::byStore($store->createdby)->count();
        $activeProducts = Product::byStore($store->createdby)->active()->count();

        // Online orders
        $onlineCount = PurchaseOrder::where('serial', $store->serial)->count();
        $onlineRevenue = PurchaseOrder::where('serial', $store->serial)->sum('total');
        $todayOnline = PurchaseOrder::where('serial', $store->serial)->where('date', $today)->count();

        // POS
        $posCount = PosOrderHistory::where('creator', $store->createdby)->count();
        $posRevenue = PosOrderHistory::where('creator', $store->createdby)->sum('total');
        $todayPos = PosOrderHistory::where('creator', $store->createdby)
            ->whereDate('fecha', $today)->count();

        // Active POS orders (not paid yet)
        $activePos = PosOrder::where('creator', $store->createdby)
            ->whereIn('estado', ['0', '1'])->count();

        // Low stock
        $lowStock = ProductStock::whereIn('idProd',
            Product::byStore($store->createdby)->pluck('id')
        )->where('stock', '<', 5)->where('stock', '>', 0)->count();

        // Appointments today
        $todayAppts = Appointment::where('idLog', $user->id)
            ->whereDate('feachaApartada', $today)->where('activo', 1)->count();

        // Revenue today
        $todayRevenue = PurchaseOrder::where('serial', $store->serial)->where('date', $today)->sum('total')
            + PosOrderHistory::where('creator', $store->createdby)->whereDate('fecha', $today)->sum('total');

        // Recent activity
        $recentOnline = PurchaseOrder::where('serial', $store->serial)
            ->orderByDesc('id')->limit(5)->get(['id', 'order', 'nombre', 'total', 'date']);
        $recentPos = PosOrderHistory::where('creator', $store->createdby)
            ->orderByDesc('id')->limit(5)->get(['id', 'noOrder as order', 'nombre', 'total', 'fecha as date']);

        return response()->json(['data' => [
            'products' => $productCount,
            'active_products' => $activeProducts,
            'online_orders' => $onlineCount,
            'online_revenue' => (float) $onlineRevenue,
            'today_online' => $todayOnline,
            'pos_orders' => $posCount,
            'pos_revenue' => (float) $posRevenue,
            'today_pos' => $todayPos,
            'active_pos' => $activePos,
            'low_stock' => $lowStock,
            'today_appointments' => $todayAppts,
            'today_revenue' => (float) $todayRevenue,
            'recent_online' => $recentOnline,
            'recent_pos' => $recentPos,
        ]]);
    }
}
