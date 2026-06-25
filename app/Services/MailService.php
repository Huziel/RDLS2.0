<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class MailService
{
    public static function configure(): bool
    {
        $settings = SiteSetting::getSettings();

        if (!$settings->mail_host || !$settings->mail_username) {
            return false;
        }

        Config::set('mail.mailers.smtp', array_filter([
            'transport' => $settings->mail_mailer ?? 'smtp',
            'host' => $settings->mail_host,
            'port' => $settings->mail_port ?? '587',
            'username' => $settings->mail_username,
            'password' => $settings->mail_password,
            'encryption' => $settings->mail_encryption ?? 'tls',
            'timeout' => null,
            'auth_mode' => null,
        ], fn($v) => $v !== null));

        Config::set('mail.from.address', $settings->mail_from_address ?? $settings->mail_username);
        Config::set('mail.from.name', $settings->mail_from_name ?? $settings->site_name ?? 'Notificaciones');

        return true;
    }

    public static function getFromAddress(): string
    {
        $settings = SiteSetting::getSettings();
        return $settings->mail_from_address ?? $settings->mail_username ?? 'no-reply@example.com';
    }

    public static function getFromName(): string
    {
        $settings = SiteSetting::getSettings();
        return $settings->mail_from_name ?? $settings->site_name ?? 'Notificaciones';
    }

    public static function send($to, $subject, $body)
    {
        if (!self::configure()) {
            return false;
        }

        try {
            Mail::html($body, function ($message) use ($to, $subject) {
                $message->to($to)
                    ->subject($subject)
                    ->from(self::getFromAddress(), self::getFromName());
            });
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MailService send failed: ' . $e->getMessage());
            return false;
        }
    }
}
