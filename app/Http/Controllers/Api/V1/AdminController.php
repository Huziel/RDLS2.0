<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    private function requireSuperAdmin(Request $request)
    {
        if (!$request->user()?->hasRole('super-admin')) {
            abort(403, 'No autorizado.');
        }
    }

    public function siteSettings(Request $request)
    {
        $this->requireSuperAdmin($request);
        $settings = SiteSetting::getSettings();
        return response()->json(['data' => $settings]);
    }

    public function updateSiteSettings(Request $request)
    {
        $this->requireSuperAdmin($request);
        $settings = SiteSetting::getSettings();

        $data = $request->only([
            'site_name', 'site_logo', 'site_favicon',
            'landing_hero_title', 'landing_hero_text',
            'landing_features', 'marketplace_colors', 'login_colors',
            'landing_colors', 'landing_custom_html',
            'mail_mailer', 'mail_host', 'mail_port', 'mail_username',
            'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name',
        ]);

        $settings->update($data);
        return response()->json(['data' => $settings->fresh(), 'message' => 'Configuracion guardada.']);
    }

    public function publicSiteSettings()
    {
        $settings = SiteSetting::getSettings();
        return response()->json(['data' => [
            'site_name' => $settings->site_name,
            'site_logo' => $settings->site_logo,
            'site_favicon' => $settings->site_favicon,
            'landing_hero_title' => $settings->landing_hero_title,
            'landing_hero_text' => $settings->landing_hero_text,
            'landing_features' => $settings->landing_features,
            'marketplace_colors' => $settings->marketplace_colors,
            'login_colors' => $settings->login_colors,
            'landing_colors' => $settings->landing_colors,
            'landing_custom_html' => $settings->landing_custom_html,
        ]]);
    }

    public function users(Request $request)
    {
        $u = $request->user();
        if (!$u->hasRole('super-admin')) return response()->json(['message' => 'No autorizado.'], 403);

        $query = User::with('store')->with('roles');

        if ($request->has('type') && $request->type !== 'all') $query->where('type', $request->type);
        if ($request->has('status') && $request->status !== 'all') $query->where('active', $request->status);
        if ($request->has('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) { $q->where('name', 'like', $s)->orWhere('id', 'like', $s); });
        }

        $users = $query->orderByDesc('id')->paginate($request->get('per_page', 20));

        $result = $users->through(fn($u) => [
            'id' => $u->id, 'name' => $u->name, 'type' => $u->type,
            'active' => (int) $u->active,
            'roles' => $u->getRoleNames(),
            'store_phone' => $u->store->phone ?? null,
            'store_serial' => $u->store->serial ?? null,
            'created_at' => $u->created_at ?? null,
        ]);

        return response()->json($result);
    }

    public function toggleActive(Request $request, $id)
    {
        if (!$request->user()->hasRole('super-admin')) return response()->json(['message' => 'No autorizado.'], 403);
        $user = User::findOrFail($id);
        $user->active = $user->active ? 0 : 1;
        $user->save();
        return response()->json(['message' => $user->active ? 'Usuario activado.' : 'Usuario desactivado.', 'active' => $user->active]);
    }

    public function changePassword(Request $request, $id)
    {
        if (!$request->user()->hasRole('super-admin')) return response()->json(['message' => 'No autorizado.'], 403);
        $request->validate(['password' => 'required|string|min:6']);
        $user = User::findOrFail($id);
        $user->keyvalue = Hash::make($request->password);
        $user->save();
        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->hasRole('super-admin')) return response()->json(['message' => 'No autorizado.'], 403);
        $user = User::findOrFail($id);
        $user->store()->delete();
        $user->tokens()->delete();
        $user->delete();
        return response()->json(['message' => 'Usuario eliminado.']);
    }

    public function stats(Request $request)
    {
        if (!$request->user()->hasRole('super-admin')) return response()->json(['message' => 'No autorizado.'], 403);
        return response()->json(['data' => [
            'total' => User::count(),
            'owners' => User::where('type', 1)->count(),
            'delivers' => User::where('type', 3)->count(),
            'customers' => User::where('type', 4)->count(),
            'active' => User::where('active', 1)->count(),
            'inactive' => User::where('active', 0)->count(),
        ]]);
    }

    public function cleanData(Request $request)
    {
        if (!$request->user()->hasRole('super-admin')) return response()->json(['message' => 'No autorizado.'], 403);

        $keepUsers = $request->input('keep_users', []);
        $keepStores = $request->input('keep_stores', []);

        \Illuminate\Support\Facades\DB::transaction(function () use ($keepUsers, $keepStores) {
            \App\Models\ChatMessage::query()->delete();
            \App\Models\ChatConversation::query()->delete();
            \App\Models\LoyaltyTransaction::query()->delete();
            \App\Models\LoyaltyPoint::query()->delete();
            \App\Models\LoyaltyConfig::query()->delete();
            \App\Models\DeliveryEvidence::query()->delete();
            \App\Models\DeliveryLocation::query()->delete();
            \App\Models\ShippingOrder::query()->delete();
            \App\Models\DeliveryLink::query()->delete();
            \App\Models\DeliveryPhoto::query()->delete();
            \App\Models\DeliveryProfile::query()->delete();
            \App\Models\DeliveryWallet::query()->delete();
            \App\Models\ExtraCharge::query()->delete();
            \App\Models\ShippingForm::query()->delete();
            \App\Models\VerificationCode::query()->delete();
            \App\Models\PurchaseOrder::query()->delete();
            \App\Models\PosOrderDetailHistory::query()->delete();
            \App\Models\PosOrderHistory::query()->delete();
            \App\Models\PosOrderDetail::query()->delete();
            \App\Models\PosOrder::query()->delete();
            \App\Models\CartAddon::query()->delete();
            \App\Models\Cart::query()->delete();
            \App\Models\Appointment::query()->delete();
            \App\Models\BarterProduct::query()->delete();
            \App\Models\Barter::query()->delete();
            \App\Models\Client::query()->delete();
            \App\Models\CouponProduct::query()->delete();
            \App\Models\Coupon::query()->delete();
            \App\Models\StoreRating::query()->delete();
            \App\Models\QrCode::query()->delete();
            \App\Models\ProductAddon::query()->delete();
            \App\Models\ProductBarcode::query()->delete();
            \App\Models\ProductImage::query()->delete();
            \App\Models\ProductStock::query()->delete();
            \App\Models\Product::query()->delete();

            $storeQuery = \App\Models\Store::query();
            if (!empty($keepStores)) $storeQuery->whereNotIn('id', $keepStores);
            $storeQuery->delete();
            \App\Models\StoreExtra::query()->delete();
            \App\Models\StoreColor::query()->delete();
            \App\Models\StoreTheme::query()->delete();
            \App\Models\StorePassword::query()->delete();
            \App\Models\StoreFeature::query()->delete();

            $superAdminIds = User::role('super-admin')->pluck('id')->toArray();
            $protectedIds = array_merge($superAdminIds, $keepUsers);
            User::whereNotIn('id', $protectedIds)->delete();

            \Laravel\Sanctum\PersonalAccessToken::query()->delete();
            \App\Models\MediaPhoto::query()->delete();
            \App\Models\MediaVideo::query()->delete();
            \App\Models\DeviceToken::query()->delete();
            \App\Models\PaidModule::query()->delete();
            \App\Models\ApartadoConfig::query()->delete();
        });

        $remaining = User::count();
        return response()->json(['message' => "Datos de prueba eliminados. Quedan $remaining usuarios.", 'data' => ['remaining_users' => $remaining]]);
    }
}
