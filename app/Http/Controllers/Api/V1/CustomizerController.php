<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DeepSeekService;
use Illuminate\Http\Request;

class CustomizerController extends Controller
{
    public function aiSuggest(Request $request)
    {
        $request->validate([
            'action' => 'required|in:description,colors,slogan',
            'business_name' => 'required|string',
            'business_type' => 'required|string',
            'products' => 'nullable|string',
        ]);

        $ai = new DeepSeekService();
        $result = match($request->action) {
            'description' => $ai->generateStoreDescription($request->business_name, $request->business_type, $request->products ?? ''),
            'colors' => $ai->suggestColors($request->business_type),
            'slogan' => $ai->generateSlogan($request->business_name, $request->business_type),
            default => null,
        };

        if (!$result) return response()->json(['message' => 'Error al contactar con la IA.'], 500);
        return response()->json(['data' => ['result' => $result]]);
    }

    public function aiTemplate(Request $request)
    {
        $request->validate(['prompt' => 'required|string', 'business_name' => 'nullable|string']);

        $ai = new DeepSeekService();
        $result = $ai->generate(
            "Genera un JSON con secciones para una tienda online. Basado en: \"{$request->prompt}\". Nombre: \"" . ($request->business_name ?: 'Mi Tienda') . "\".\n\n".
            "Responde SOLO con un array JSON. Cada objeto debe tener: type (hero|about|products|gallery|map|contact|social), title (string), content (string), style (default|centered|dark|gradient).\n\n".
            "Ejemplo: [{\"type\":\"hero\",\"title\":\"Bienvenidos\",\"content\":\"Tu tienda de confianza\",\"style\":\"gradient\"},{\"type\":\"products\",\"title\":\"Productos\",\"content\":\"\",\"style\":\"default\"}]"
        );

        if (!$result) return response()->json(['message' => 'Error con IA.'], 500);

        // Extract JSON from response
        preg_match('/\[.*\]/s', $result, $matches);
        return response()->json(['data' => ['sections' => $matches[0] ?? $result]]);
    }

    public function aiCss(Request $request)
    {
        $request->validate(['description' => 'required|string', 'current_colors' => 'nullable|string']);
        $ai = new \App\Services\DeepSeekService();
        $result = $ai->suggestCss($request->description, $request->current_colors ?? '');
        if (!$result) return response()->json(['message' => 'Error con IA.'], 500);
        return response()->json(['data' => ['css' => $result]]);
    }
}
