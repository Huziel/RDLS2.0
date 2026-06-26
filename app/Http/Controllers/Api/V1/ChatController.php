<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Store;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    // Customer: start or get conversation
    public function customerConversation(Request $request)
    {
        $request->validate(['store_serial' => 'required|string']);
        $store = Store::where('serial', $request->store_serial)->firstOrFail();
        $sessionId = $request->header('X-Cart-Token') ?: ('session-' . uniqid());
        $name = $request->input('name', 'Cliente');

        $conv = ChatConversation::where('store_id', $store->id)->where('session_id', $sessionId)->where('status', 'open')->first();
        $phone = $request->input('phone');
        if (!$conv) {
            $conv = ChatConversation::create([
                'store_id' => $store->id, 'customer_name' => $name,
                'customer_phone' => $phone, 'session_id' => $sessionId,
            ]);
        }
        // Sync to CRM
        if ($phone) $this->syncToCrm($store->id, $name, $phone);
        return response()->json(['data' => $this->formatConversation($conv)]);
    }

    // Customer: send message
    public function customerSend(Request $request, $conversationId)
    {
        $request->validate(['message' => 'required|string']);
        $conv = ChatConversation::where('status', 'open')->findOrFail($conversationId);

        ChatMessage::create(['conversation_id' => $conv->id, 'sender_type' => 'customer', 'message' => $request->message]);
        $conv->update(['last_message_at' => now()]);

        return response()->json(['message' => 'Enviado.']);
    }

    // Customer: poll messages
    public function customerMessages(Request $request, $conversationId)
    {
        $conv = ChatConversation::findOrFail($conversationId);
        $since = $request->input('since_id', 0);
        $msgs = ChatMessage::where('conversation_id', $conv->id)->where('id', '>', $since)->orderBy('id')->get();
        // Mark store messages as read
        ChatMessage::where('conversation_id', $conv->id)->where('sender_type', 'store')->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['data' => $msgs]);
    }

    // Store owner: list conversations
    public function storeConversations(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $convs = ChatConversation::where('store_id', $store->id)->orderByDesc('last_message_at')->orderByDesc('id')->paginate(20);
        $data = $convs->through(fn($c) => $this->formatConversation($c));
        return response()->json(['data' => $data->items(), 'meta' => ['current_page' => $convs->currentPage(), 'last_page' => $convs->lastPage(), 'total' => $convs->total()]]);
    }

    // Store owner: send message
    public function storeSend(Request $request, $conversationId)
    {
        $request->validate(['message' => 'required|string']);
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $conv = ChatConversation::where('store_id', $store->id)->findOrFail($conversationId);

        ChatMessage::create(['conversation_id' => $conv->id, 'sender_type' => 'store', 'message' => $request->message]);
        $conv->update(['last_message_at' => now()]);

        return response()->json(['message' => 'Enviado.']);
    }

    // Store owner: get messages
    public function storeMessages(Request $request, $conversationId)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $conv = ChatConversation::where('store_id', $store->id)->findOrFail($conversationId);
        $since = $request->input('since_id', 0);
        $msgs = ChatMessage::where('conversation_id', $conv->id)->where('id', '>', $since)->orderBy('id')->get();
        // Mark customer messages as read
        ChatMessage::where('conversation_id', $conv->id)->where('sender_type', 'customer')->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['data' => $msgs]);
    }

    // Store owner: close conversation
    public function close(Request $request, $conversationId)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $conv = ChatConversation::where('store_id', $store->id)->findOrFail($conversationId);
        $conv->update(['status' => 'closed']);
        return response()->json(['message' => 'Conversacion cerrada.']);
    }

    private function syncToCrm($storeId, $name, $phone)
    {
        try {
            $client = Client::where('store_id', $storeId)->where('phone', $phone)->first();
            if ($client) {
                $client->update(['name' => $name]);
            } else {
                Client::create(['store_id' => $storeId, 'name' => $name, 'phone' => $phone, 'stage' => 'lead', 'tags' => ['chat']]);
            }
        } catch (\Exception $e) {}
    }

    private function formatConversation($conv)
    {
        return [
            'id' => $conv->id, 'store_id' => $conv->store_id,
            'customer_name' => $conv->customer_name, 'customer_phone' => $conv->customer_phone,
            'status' => $conv->status, 'last_message_at' => $conv->last_message_at,
            'unread_count' => $conv->unreadCount(),
            'last_message' => $conv->messages()->latest()->first()?->message,
            'created_at' => $conv->created_at,
        ];
    }
}
