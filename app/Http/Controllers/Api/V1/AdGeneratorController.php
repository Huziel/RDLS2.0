<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdGeneratorController extends Controller
{
    private function callDeepSeek($messages, $temperature = 0.2, $maxTokens = 3000)
    {
        $apiKey = config('services.deepseek.api_key');
        if (!$apiKey) return null;

        $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'deepseek-chat',
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]),
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200) return null;

        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'] ?? null;
    }

    public function generateAd(Request $request)
    {
        $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
            'business_name' => ['nullable', 'string'],
            'whatsapp' => ['nullable', 'string'],
            'logo_url' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string'],
            'colors' => ['nullable', 'string'],
            'font' => ['nullable', 'string'],
        ]);

        $businessName = $request->business_name ?: 'Mi Negocio';
        $whatsapp = preg_replace('/[^0-9]/', '', $request->whatsapp ?: '');
        $logoUrl = $request->logo_url ?: '';
        $imageUrl = $request->image_url ?: '';
        $colors = $request->colors ?: '#1e293b, #ffffff, #667eea';
        $font = $request->font ?: 'Inter, sans-serif';
        $prompt = $request->prompt;

        $colorsArray = array_map('trim', explode(',', $colors));
        $c1 = $colorsArray[0] ?? '#ffffff';
        $c2 = $colorsArray[1] ?? '#000000';
        $c3 = $colorsArray[2] ?? '#667eea';
        $imageUrls = array_filter([$imageUrl]);

        $promptFull = "Crea un anuncio HTML/CSS de 500px de ancho para:
Negocio: $businessName
WhatsApp: $whatsapp (link a wa.me/$whatsapp)
Logo: $logoUrl
Imagenes: " . implode(', ', $imageUrls) . "
Colores: " . implode(', ', $colorsArray) . "
Fuente: $font
REGLAS:
1. $prompt
2. El contenedor principal debe tener width:500px
3. Usa los colores EXACTOS proporcionados
4. El logo debe ser visible (max-width:120px)
5. Boton de WhatsApp llamativo con enlace correcto
6. Diseno atractivo con bordes redondeados y sombras
7. SOLO el codigo HTML/CSS, sin explicaciones
8. Comienza con <style> y termina con </div>
9. El logo debe ser importante en el diseno";

        $html = $this->callDeepSeek([
            ['role' => 'system', 'content' => 'Eres disenador frontend. Generas SOLO codigo HTML/CSS. Usas EXACTAMENTE los colores que te dan. Responde completo.'],
            ['role' => 'user', 'content' => $promptFull],
        ]);

        if (!$html) {
            return response()->json([
                'success' => true,
                'html' => $this->fallbackAd($businessName, $whatsapp, $logoUrl, $imageUrl, $c1, $c2, $c3, $font, $prompt),
                'warning' => 'Usando plantilla de respaldo',
            ]);
        }

        if (substr_count($html, '<div') !== substr_count($html, '</div>')) {
            return response()->json([
                'success' => true,
                'html' => $this->fallbackAd($businessName, $whatsapp, $logoUrl, $imageUrl, $c1, $c2, $c3, $font, $prompt),
                'warning' => 'HTML truncado, usando plantilla',
            ]);
        }

        $html = $this->cleanHtml($html);

        // Replace absolute localhost URLs with relative paths (fixes CORS for image download)
        $appUrl = rtrim(config('app.url'), '/');
        $html = str_replace($appUrl . '/', '/', $html);
        $html = str_replace('http://localhost:8000/', '/', $html);
        $html = str_replace('http://localhost/', '/', $html);

        if (strpos($html, '#FF0000') !== false && $c1 !== '#FF0000') {
            $html = str_replace('#FF0000', $c1, $html);
        }
        if (strpos($html, '#000000') !== false && $c2 !== '#000000') {
            $html = str_replace('#000000', $c2, $html);
        }

        // Fix logo size: ensure any image containing the logo URL has max-width constraint
        if ($logoUrl) {
            // Match by filename (last part of URL) to handle different origins
            $logoFile = basename(parse_url($logoUrl, PHP_URL_PATH));
            if ($logoFile) {
                $html = preg_replace_callback(
                    '/<img([^>]*src=["\'][^"\']*' . preg_quote($logoFile, '/') . '["\'][^>]*)>/i',
                    function ($m) {
                        $tag = $m[1];
                        if (stripos($tag, 'max-width') !== false) return '<img' . $tag . '>';
                        if (stripos($tag, 'style=') !== false) {
                            return '<img' . preg_replace('/(style=["\'])/i', '$1max-width:120px;height:auto;', $tag) . '>';
                        }
                        return '<img' . $tag . ' style="max-width:120px;height:auto">';
                    },
                    $html
                );
            }
        }

        if ($whatsapp && strpos($html, $whatsapp) === false) {
            $waHtml = "<div style='text-align:center;margin-top:20px'><a href='https://wa.me/$whatsapp' style='display:inline-block;background:$c3;color:white;padding:15px 30px;border-radius:50px;text-decoration:none;font-weight:bold;font-family:$font'>📱 WhatsApp: $whatsapp</a></div>";
            $html = preg_replace('/(<\/div>\s*$)/', $waHtml . '$1', $html);
        }

        return response()->json(['success' => true, 'html' => $html]);
    }

    public function generateDescription(Request $request)
    {
        $request->validate([
            'texto' => ['required', 'string', 'max:2000'],
            'platform' => ['nullable', 'string', 'in:instagram,facebook,twitter,linkedin,tiktok'],
            'tone' => ['nullable', 'string'],
            'max_length' => ['nullable', 'integer', 'min:50', 'max:5000'],
            'include_hashtags' => ['nullable', 'boolean'],
            'language' => ['nullable', 'string'],
            'action' => ['nullable', 'string', 'in:single,multiple'],
            'variants' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $texto = $request->texto;
        $platform = $request->platform ?: 'instagram';
        $tone = $request->tone ?: 'profesional';
        $maxLength = $request->max_length ?: 500;
        $includeHashtags = $request->include_hashtags ?? true;
        $language = $request->language ?: 'español';
        $action = $request->action ?: 'single';
        $variants = min(5, max(1, $request->variants ?: 3));

        $toneMap = [
            'profesional' => 'formal, serio y corporativo sin emoticonos',
            'casual' => 'relajado, amigable y conversacional',
            'divertido' => 'alegre, con emojis y juegos de palabras',
            'inspirador' => 'motivador, emocional y aspiracional',
            'informativo' => 'claro, directo y basado en hechos',
            'urgente' => 'con sentido de inmediatez y escasez',
        ];
        $platformFormats = [
            'instagram' => 'max 2200 caracteres, emojis, hashtags relevantes',
            'facebook' => 'mas extenso, enlaces y preguntas',
            'twitter' => 'max 280 caracteres, conciso',
            'linkedin' => 'formal, listas y estadisticas',
            'tiktok' => 'corto, max 150 caracteres, tendencias',
        ];

        $toneDesc = $toneMap[$tone] ?? $toneMap['profesional'];
        $platformDesc = $platformFormats[$platform] ?? $platformFormats['instagram'];

        if ($action === 'multiple') {
            $prompt = "Genera $variants versiones diferentes de descripcion para $platform: \"$texto\". Tono: $tone - $toneDesc. Formato: $platformDesc. Max: $maxLength car. Idioma: $language. " . ($includeHashtags ? "Incluye 3-5 hashtags." : "Sin hashtags.") . " Responde SOLO JSON: {\"variants\":[{\"variant\":1,\"description\":\"...\"},...]}";
        } else {
            $prompt = "Genera UNA descripcion para $platform: \"$texto\". Tono: $tone - $toneDesc. Formato: $platformDesc. Max: $maxLength car. Idioma: $language. " . ($includeHashtags ? "Incluye 3-5 hashtags." : "Sin hashtags.") . " Responde SOLO el texto.";
        }

        $content = $this->callDeepSeek([
            ['role' => 'system', 'content' => 'Eres copywriter experto en redes sociales.'],
            ['role' => 'user', 'content' => $prompt],
        ], 0.8, $action === 'multiple' ? 2000 : 800);

        if (!$content) {
            $fallback = "✨ Descubre " . mb_substr($texto, 0, 50) . "...\n🌟 Calidad garantizada\n📞 Contactanos!" . ($includeHashtags ? "\n#Ofertas #Calidad" : "");
            if ($action === 'multiple') {
                return response()->json(['success' => true, 'variants' => [['variant' => 1, 'description' => $fallback, 'characters' => mb_strlen($fallback)]]]);
            }
            return response()->json(['success' => true, 'description' => $fallback, 'characters' => mb_strlen($fallback)]);
        }

        if ($action === 'multiple') {
            $content = preg_replace('/```json\s*/', '', $content);
            $content = preg_replace('/```\s*$/', '', $content);
            $json = json_decode($content, true);
            if ($json && isset($json['variants'])) {
                foreach ($json['variants'] as &$v) $v['characters'] = mb_strlen($v['description']);
                return response()->json(['success' => true, 'variants' => $json['variants']]);
            }
        }
        return response()->json(['success' => true, 'description' => trim($content), 'characters' => mb_strlen(trim($content))]);
    }

    private function cleanHtml($html)
    {
        $html = preg_replace('/```html\s*/', '', $html);
        $html = preg_replace('/```\s*$/', '', $html);
        $html = preg_replace('/^```\s*/', '', $html);
        $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
        $html = preg_replace('/<html[^>]*>/i', '', $html);
        $html = preg_replace('/<\/html>/i', '', $html);
        $html = preg_replace('/<head>.*?<\/head>/is', '', $html);
        $html = preg_replace('/<body[^>]*>/i', '', $html);
        $html = preg_replace('/<\/body>/i', '', $html);
        $html = preg_replace('/<img([^>]*?)>/i', '<img$1 onerror="this.style.display=\'none\'">', $html);
        return trim($html);
    }

    private function fallbackAd($name, $wa, $logo, $img, $c1, $c2, $c3, $font, $prompt)
    {
        $logoHtml = $logo ? "<img src='$logo' style='max-width:100px;height:auto;margin-bottom:15px;border-radius:15px' onerror=\"this.style.display='none'\">" : '';
        $imgBg = $img ? "background-image:url('$img');background-size:cover;background-position:center;" : "background:$c1;";
        $overlay = $img ? "background:linear-gradient(rgba(0,0,0,0.35),rgba(0,0,0,0.35));" : '';
        $waLink = $wa ? "<a href='https://wa.me/$wa' style='display:inline-block;background:$c3;color:white;padding:14px 28px;border-radius:50px;text-decoration:none;font-weight:bold;font-size:16px;margin-top:10px'>📱 WhatsApp: $wa</a>" : '';

        return "<style>
.ad-box{width:500px;margin:0 auto;border-radius:20px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,0.2);font-family:$font}
.ad-inner{position:relative;padding:30px 24px;text-align:center;$overlay $imgBg color:$c2;min-height:200px;display:flex;flex-direction:column;align-items:center;justify-content:center}
.ad-title{font-size:28px;font-weight:700;margin-bottom:10px;text-shadow:1px 1px 3px rgba(0,0,0,0.3)}
.ad-text{font-size:16px;opacity:0.9;margin-bottom:20px;line-height:1.5;max-width:400px}
</style>
<div class='ad-box'><div class='ad-inner'>
$logoHtml
<div class='ad-title'>$name</div>
<div class='ad-text'>$prompt</div>
$waLink
</div></div>";
    }
}
