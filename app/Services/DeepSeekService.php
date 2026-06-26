<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DeepSeekService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.deepseek.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.deepseek.api_key') ?: 'sk-e23ea84fb438495c94f049246e3d8a75';
    }

    public function generate(string $prompt, string $systemPrompt = ''): ?string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/chat/completions", [
            'model' => 'deepseek-chat',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt ?: 'Eres un experto en diseño de tiendas online y marketing digital. Responde en español con creatividad.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.8,
            'max_tokens' => 500,
        ]);

        if ($response->successful()) {
            return $response->json('choices.0.message.content');
        }

        return null;
    }

    public function generateStoreDescription(string $businessName, string $type, string $products): ?string
    {
        return $this->generate(
            "Genera una descripción atractiva para la tienda \"{$businessName}\" que es de tipo {$type}. Productos: {$products}. Máximo 150 palabras, tono profesional y persuasivo."
        );
    }

    public function suggestColors(string $businessType): ?string
    {
        return $this->generate(
            "Sugiere una paleta de 3 colores (hex) para un negocio de tipo {$businessType}. Solo responde con los códigos hex separados por comas. Ejemplo: #1a1a2e,#16213e,#0f3460"
        );
    }

    public function generateSlogan(string $businessName, string $type): ?string
    {
        return $this->generate(
            "Genera 3 slogans cortos y creativos para \"{$businessName}\" que es un negocio de tipo {$type}. Sepáralos con |."
        );
    }

    public function suggestCss(string $description, string $currentColors): ?string
    {
        return $this->generate(
            "Genera CSS para personalizar una tienda online. Descripción: \"{$description}\". Colores actuales: {$currentColors}.\n\n".
            "Responde SOLO con reglas CSS válidas (sin explicación). Enfócate en: --primary-color, --secondary-color, --bg-color, --text-color, fuentes, bordes redondeados, sombras. Usa variables CSS.",
            "Eres un diseñador UI/UX experto. Generas CSS moderno y profesional."
        );
    }
}
