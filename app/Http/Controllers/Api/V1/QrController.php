<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\QrCode;
use App\Models\Store;
use Illuminate\Http\Request;

class QrController extends Controller
{
    public function generate(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        $request->validate([
            'type' => 'required|in:store,product,menu,catalog,promo',
            'target_id' => 'nullable|integer',
            'label' => 'nullable|string',
        ]);

        $baseUrl = rtrim(config('app.url'), '/');
        $type = $request->type;
        $targetId = $request->target_id;

        $url = match($type) {
            'store' => "$baseUrl/store/{$store->serial}",
            'product' => "$baseUrl/store/{$store->serial}/product/{$targetId}",
            'menu' => "$baseUrl/book/{$store->serial}",
            'catalog' => "$baseUrl/store/{$store->serial}",
            'promo' => $request->input('custom_url', "$baseUrl/store/{$store->serial}"),
            default => "$baseUrl/store/{$store->serial}",
        };

        $qrDir = public_path('qrcodes');
        if (!is_dir($qrDir)) mkdir($qrDir, 0755, true);
        $filename = 'qr_' . $store->id . '_' . $type . ($targetId ? '_' . $targetId : '') . '.png';

        try {
            if (class_exists('\Endroid\QrCode\Builder\Builder')) {
                $result = \Endroid\QrCode\Builder\Builder::create()
                    ->writer(new \Endroid\QrCode\Writer\PngWriter())
                    ->data($url)
                    ->size(300)
                    ->margin(10)
                    ->build();
                $result->saveToFile("$qrDir/$filename");
            } else {
                // Fallback using simple-qr-code or built-in
                $this->generateBasicQr($url, "$qrDir/$filename");
            }
        } catch (\Throwable $e) {
            $this->generateBasicQr($url, "$qrDir/$filename");
        }

        $qr = QrCode::firstOrCreate(
            ['store_id' => $store->id, 'type' => $type, 'target_id' => $targetId],
            ['target_url' => $url, 'label' => $request->label, 'image_path' => "qrcodes/$filename"]
        );

        return response()->json([
            'data' => [
                'id' => $qr->id,
                'url' => $url,
                'image' => asset("qrcodes/$filename"),
                'scans' => $qr->scans,
                'type' => $qr->type,
            ],
            'message' => 'QR generado exitosamente.',
        ]);
    }

    private function generateBasicQr($url, $path)
    {
        // Use Google Charts API as fallback
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($url);
        file_put_contents($path, file_get_contents($qrUrl));
    }

    public function list(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();
        $qrs = QrCode::where('store_id', $store->id)->orderByDesc('id')->get()
            ->map(fn($q) => ['id'=>$q->id,'type'=>$q->type,'label'=>$q->label,'url'=>$q->target_url,'image'=>asset($q->image_path),'scans'=>$q->scans,'created_at'=>$q->created_at]);
        return response()->json(['data' => $qrs]);
    }

    public function track($id)
    {
        $qr = QrCode::findOrFail($id);
        $qr->increment('scans');
        return redirect()->away($qr->target_url);
    }
}
