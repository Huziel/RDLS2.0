<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PosOrderHistory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Store;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function overview(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $totalRevenue = (float) PurchaseOrder::where('serial', $store->serial)->sum('total')
            + (float) PosOrderHistory::where('creator', $store->createdby)->sum('total');

        $orderCount = PurchaseOrder::where('serial', $store->serial)->count()
            + PosOrderHistory::where('creator', $store->createdby)->count();

        $avgTicket = $orderCount > 0 ? round($totalRevenue / $orderCount, 2) : 0;

        $productCount = Product::byStore($store->createdby)->count();
        $activeProducts = Product::byStore($store->createdby)->active()->count();

        return response()->json(['data' => [
            'total_revenue' => $totalRevenue,
            'total_orders' => $orderCount,
            'avg_ticket' => $avgTicket,
            'products' => $productCount,
            'active_products' => $activeProducts,
        ]]);
    }

    public function salesByDay(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();
        $days = $request->get('days', 30);

        $online = PurchaseOrder::selectRaw('date, SUM(total) as total')
            ->where('serial', $store->serial)
            ->where('date', '>=', now()->subDays($days)->format('Y-m-d'))
            ->groupBy('date')->orderBy('date')->get();

        $pos = PosOrderHistory::selectRaw('DATE(fecha) as date, SUM(total) as total')
            ->where('creator', $store->createdby)
            ->where('fecha', '>=', now()->subDays($days)->format('Y-m-d'))
            ->groupBy('fecha')->orderBy('fecha')->get();

        $labels = []; $dataOnline = []; $dataPos = [];
        for ($i = $days; $i >= 0; $i--) {
            $d = now()->subDays($i)->format('Y-m-d');
            $labels[] = now()->subDays($i)->format('d/m');
            $dataOnline[] = (float) ($online->firstWhere('date', $d)?->total ?? 0);
            $dataPos[] = (float) ($pos->firstWhere('date', $d)?->total ?? 0);
        }

        return response()->json(['data' => compact('labels', 'dataOnline', 'dataPos')]);
    }

    public function topProducts(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $top = \DB::table('data as p')
            ->join('cart as c', 'c.product', '=', 'p.id')
            ->where('p.session', $store->createdby)
            ->whereNotNull('c.orderC')
            ->selectRaw('p.keyy as name, SUM(c.cant) as qty, SUM(c.price) as revenue')
            ->groupBy('p.id', 'p.keyy')
            ->orderByDesc('revenue')
            ->limit(10)->get();

        return response()->json(['data' => $top]);
    }

    public function peakHours(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $hours = PosOrderHistory::selectRaw('HOUR(fecha) as hour, COUNT(*) as count, SUM(total) as revenue')
            ->where('creator', $store->createdby)
            ->groupBy(\DB::raw('HOUR(fecha)'))
            ->orderBy('hour')->get();

        $labels = []; $data = [];
        for ($h = 0; $h < 24; $h++) {
            $labels[] = sprintf('%02d:00', $h);
            $row = $hours->firstWhere('hour', $h);
            $data[] = $row ? (int) $row->count : 0;
        }

        return response()->json(['data' => compact('labels', 'data')]);
    }

    public function monthlyComparison(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $d = now()->subMonths($i);
            $months[] = ['label' => $d->format('M Y'), 'month' => $d->format('Y-m')];
        }

        $result = [];
        foreach ($months as $m) {
            [$y, $mo] = explode('-', $m['month']);
            $posRev = PosOrderHistory::where('creator', $store->createdby)
                ->whereYear('fecha', $y)->whereMonth('fecha', $mo)->sum('total');
            $onlRev = PurchaseOrder::where('serial', $store->serial)
                ->where('date', 'like', "$y-$mo%")->sum('total');
            $result[] = ['label' => $m['label'], 'pos' => (float) $posRev, 'online' => (float) $onlRev];
        }

        return response()->json(['data' => $result]);
    }
}
