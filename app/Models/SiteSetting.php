<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $table = 'site_settings';

    protected $fillable = [
        'site_name', 'site_logo', 'site_favicon',
        'landing_hero_title', 'landing_hero_text', 'landing_features',
        'marketplace_colors', 'login_colors', 'landing_colors', 'landing_custom_html',
        'mail_mailer', 'mail_host', 'mail_port', 'mail_username',
        'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name',
    ];

    protected $casts = [
        'landing_features' => 'array',
        'marketplace_colors' => 'array',
        'login_colors' => 'array',
        'landing_colors' => 'array',
    ];

    public static function getSettings(): self
    {
        return static::firstOrCreate([], [
            'site_name' => 'Ruta de la Seda',
            'landing_hero_title' => 'Crea tu tienda online en minutos',
            'landing_hero_text' => 'La plataforma todo en uno para emprendedores.',
            'landing_features' => [],
            'marketplace_colors' => ['primary' => '#667eea', 'secondary' => '#764ba2', 'bg' => '#f8fafc', 'text' => '#1e293b', 'accent' => '#f59e0b'],
            'login_colors' => ['bg_start' => '#667eea', 'bg_end' => '#764ba2', 'card_bg' => '#ffffff', 'primary' => '#667eea', 'text' => '#1e293b'],
            'mail_mailer' => 'smtp',
            'mail_port' => '587',
            'mail_encryption' => 'tls',
        ]);
    }
}
