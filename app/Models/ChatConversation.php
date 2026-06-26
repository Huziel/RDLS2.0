<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatConversation extends Model
{
    protected $fillable = ['store_id', 'customer_name', 'customer_phone', 'customer_email', 'session_id', 'status', 'last_message_at'];
    protected $casts = ['last_message_at' => 'datetime'];

    public function messages() { return $this->hasMany(ChatMessage::class, 'conversation_id'); }
    public function store() { return $this->belongsTo(Store::class, 'store_id'); }
    public function unreadCount(): int { return $this->messages()->where('sender_type', 'customer')->whereNull('read_at')->count(); }
}
