<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApartadoConfig;
use App\Models\Appointment;
use App\Models\Barter;
use App\Models\BarterProduct;
use App\Models\Cart;
use App\Models\CartAddon;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\CouponProduct;
use App\Models\DeliveryEvidence;
use App\Models\DeliveryLink;
use App\Models\DeliveryLocation;
use App\Models\DeliveryPhoto;
use App\Models\DeliveryProfile;
use App\Models\DeliveryWallet;
use App\Models\DeviceToken;
use App\Models\ExtraCharge;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyTransaction;
use App\Models\MediaPhoto;
use App\Models\MediaVideo;
use App\Models\OrderAuditEvent;
use App\Models\OrderPayment;
use App\Models\OrderPaymentProof;
use App\Models\OrderProviderTransaction;
use App\Models\OrderReturn;
use App\Models\PaidModule;
use App\Models\PosOrder;
use App\Models\PosOrderDetail;
use App\Models\PosOrderDetailHistory;
use App\Models\PosOrderHistory;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductBarcode;
use App\Models\ProductImage;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\QrCode;
use App\Models\ShippingForm;
use App\Models\ShippingOrder;
use App\Models\SiteSetting;
use App\Models\Store;
use App\Models\StoreColor;
use App\Models\StoreExtra;
use App\Models\StoreFeature;
use App\Models\StorePassword;
use App\Models\StoreRating;
use App\Models\StoreTheme;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AdminController extends Controller
{
    private function requireSuperAdmin(Request $request)
    {
        if (! $request->user()?->hasRole('super-admin')) {
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
        if (! $u->hasRole('super-admin')) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $query = User::with('store')->with('roles');

        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('active', $request->status);
        }
        if ($request->has('search')) {
            $s = '%'.$request->search.'%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)->orWhere('id', 'like', $s);
            });
        }

        $users = $query->orderByDesc('id')->paginate($request->get('per_page', 20));

        $result = $users->through(fn ($u) => [
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
        if (! $request->user()->hasRole('super-admin')) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        $user = User::findOrFail($id);
        $user->active = $user->active ? 0 : 1;
        $user->save();

        return response()->json(['message' => $user->active ? 'Usuario activado.' : 'Usuario desactivado.', 'active' => $user->active]);
    }

    public function changePassword(Request $request, $id)
    {
        if (! $request->user()->hasRole('super-admin')) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        $request->validate(['password' => 'required|string|min:6']);
        $user = User::findOrFail($id);
        $user->keyvalue = Hash::make($request->password);
        $user->save();

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    public function destroy(Request $request, $id)
    {
        if (! $request->user()->hasRole('super-admin')) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        $user = User::findOrFail($id);
        $user->store()->delete();
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Usuario eliminado.']);
    }

    public function stats(Request $request)
    {
        if (! $request->user()->hasRole('super-admin')) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

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
        if (! $request->user()->hasRole('super-admin')) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $keepUsers = $request->input('keep_users', []);
        $keepStores = $request->input('keep_stores', []);

        DB::transaction(function () use ($keepUsers, $keepStores) {
            // Re-validar la proteccion financiera DENTRO de la transaccion y con
            // locks, inmediatamente antes del borrado: una fila financiera o de
            // auditoria inyectada entre el guard inicial y la limpieza aborta
            // TODA la operacion. abort() lanza una HttpException que revierte la
            // transaccion (sin borrados parciales ni filas infiltradas) y
            // responde 409.
            $protectedFinanceExists = OrderAuditEvent::query()->lockForUpdate()->exists()
                || OrderPaymentProof::query()->lockForUpdate()->exists()
                || OrderProviderTransaction::query()->lockForUpdate()->exists()
                || OrderReturn::query()->lockForUpdate()->exists()
                || OrderPayment::query()->lockForUpdate()->exists();
            if ($protectedFinanceExists) {
                abort(409, 'La limpieza se rechazo porque existen registros financieros o de auditoria protegidos.');
            }

            ChatMessage::query()->delete();
            ChatConversation::query()->delete();
            LoyaltyTransaction::query()->delete();
            LoyaltyPoint::query()->delete();
            LoyaltyConfig::query()->delete();
            DeliveryEvidence::query()->delete();
            DeliveryLocation::query()->delete();
            ShippingOrder::query()->delete();
            DeliveryLink::query()->delete();
            DeliveryPhoto::query()->delete();
            DeliveryProfile::query()->delete();
            DeliveryWallet::query()->delete();
            ExtraCharge::query()->delete();
            ShippingForm::query()->delete();
            VerificationCode::query()->delete();
            PurchaseOrder::query()->delete();
            PosOrderDetailHistory::query()->delete();
            PosOrderHistory::query()->delete();
            PosOrderDetail::query()->delete();
            PosOrder::query()->delete();
            CartAddon::query()->delete();
            Cart::query()->delete();
            Appointment::query()->delete();
            BarterProduct::query()->delete();
            Barter::query()->delete();
            Client::query()->delete();
            CouponProduct::query()->delete();
            Coupon::query()->delete();
            StoreRating::query()->delete();
            QrCode::query()->delete();
            ProductAddon::query()->delete();
            ProductBarcode::query()->delete();
            ProductImage::query()->delete();
            ProductStock::query()->delete();
            Product::query()->delete();

            $superAdminIds = User::role('super-admin')->pluck('id')->toArray();
            $superAdminEmails = User::role('super-admin')->pluck('name')->toArray();
            $protectedIds = array_merge($superAdminIds, $keepUsers);

            // Protect super-admin stores
            $superAdminStoreIds = Store::whereIn('createdby', $superAdminEmails)->pluck('id')->toArray();
            $allProtectedStores = array_merge($superAdminStoreIds, $keepStores);

            $storeQuery = Store::query();
            if (! empty($allProtectedStores)) {
                $storeQuery->whereNotIn('id', $allProtectedStores);
            }
            $storeQuery->delete();
            StoreExtra::query()->delete();
            StoreColor::query()->delete();
            StoreTheme::query()->delete();
            StorePassword::query()->delete();
            StoreFeature::query()->delete();

            User::whereNotIn('id', $protectedIds)->delete();

            PersonalAccessToken::query()->delete();
            MediaPhoto::query()->delete();
            MediaVideo::query()->delete();
            DeviceToken::query()->delete();
            PaidModule::query()->delete();
            ApartadoConfig::query()->delete();
        });

        $remaining = User::count();

        return response()->json(['message' => "Datos de prueba eliminados. Quedan $remaining usuarios.", 'data' => ['remaining_users' => $remaining]]);
    }
}
