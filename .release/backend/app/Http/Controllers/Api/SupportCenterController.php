<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\SupportRealtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupportCenterController extends Controller
{
    public function __construct(private readonly SupportRealtime $realtime) {}

    public function customerShow(Request $request): JsonResponse
    {
        $this->ensureCustomer($request->user());
        $conversation = SupportConversation::where('customer_id', $request->user()->id)->first();

        return response()->json([
            'data' => $conversation ? $this->detail($request, $conversation, false) : [
                'conversation' => null, 'messages' => [], 'has_more' => false,
            ],
            'realtime' => $this->realtime->publicConfiguration(),
        ]);
    }

    public function customerUnread(Request $request): JsonResponse
    {
        $this->ensureCustomer($request->user());
        $conversation = SupportConversation::where('customer_id', $request->user()->id)->first();

        return response()->json(['data' => ['unread_count' => $conversation
            ? $conversation->messages()->where('sender_role', 'team')->where('id', '>', $conversation->customer_last_read_message_id ?? 0)->count()
            : 0]]);
    }

    public function customerSend(Request $request): JsonResponse
    {
        $this->ensureCustomer($request->user());
        $body = $this->validatedBody($request);
        $message = DB::transaction(function () use ($request, $body): SupportMessage {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $conversation = SupportConversation::firstOrCreate(['customer_id' => $request->user()->id]);
            $message = $conversation->messages()->create([
                'sender_id' => $request->user()->id, 'sender_role' => 'customer', 'body' => $body,
            ]);
            $conversation->touch();

            return $message;
        });
        $this->realtime->signal($message->support_conversation_id, $request->user()->id);

        return response()->json(['data' => $this->messagePayload($message->load('sender'))], 201);
    }

    public function customerRead(Request $request): JsonResponse
    {
        $this->ensureCustomer($request->user());
        $conversation = SupportConversation::where('customer_id', $request->user()->id)->firstOrFail();

        return $this->markRead($request, $conversation, 'customer');
    }

    public function staffIndex(Request $request): JsonResponse
    {
        $this->ensureStaff($request->user());
        $data = $request->validate(['search' => ['sometimes', 'string', 'max:100']]);
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            $page = User::query()->select('id', 'name', 'email')->where('role', 'customer')
                ->where(fn ($customer) => $customer->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%'))
                ->with(['supportConversation' => fn ($conversation) => $conversation
                    ->with('latestMessage.sender:id,name')
                    ->withCount(['messages as unread_count' => fn ($messages) => $messages
                        ->where('sender_role', 'customer')
                        ->whereRaw('support_messages.id > COALESCE(support_conversations.team_last_read_message_id, 0)')])])
                ->orderBy('name')->orderBy('id')->paginate(20);
            $page->through(function (User $customer): array {
                if ($customer->supportConversation) {
                    return $this->conversationPayload($customer->supportConversation->setRelation('customer', $customer));
                }

                return [
                    'id' => null,
                    'customer' => ['id' => $customer->id, 'name' => $customer->name, 'email' => $customer->email],
                    'latest_message' => null, 'unread_count' => 0, 'updated_at' => null,
                ];
            });

            return response()->json(['data' => $page, 'realtime' => $this->realtime->publicConfiguration()]);
        }
        $query = SupportConversation::query()
            ->with(['customer:id,name,email', 'latestMessage.sender:id,name'])
            ->withCount(['messages as unread_count' => fn ($query) => $query
                ->where('sender_role', 'customer')
                ->whereRaw('support_messages.id > COALESCE(support_conversations.team_last_read_message_id, 0)')]);
        $page = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(20);
        $page->through(fn (SupportConversation $conversation) => $this->conversationPayload($conversation));

        return response()->json(['data' => $page, 'realtime' => $this->realtime->publicConfiguration()]);
    }

    public function staffUnread(Request $request): JsonResponse
    {
        $this->ensureStaff($request->user());
        $count = SupportMessage::query()
            ->join('support_conversations', 'support_conversations.id', '=', 'support_messages.support_conversation_id')
            ->where('support_messages.sender_role', 'customer')
            ->whereRaw('support_messages.id > COALESCE(support_conversations.team_last_read_message_id, 0)')
            ->count();

        return response()->json(['data' => ['unread_count' => $count]]);
    }

    public function staffShow(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->ensureStaff($request->user());

        return response()->json(['data' => $this->detail($request, $conversation, true), 'realtime' => $this->realtime->publicConfiguration()]);
    }

    public function staffCustomerShow(Request $request, User $customer): JsonResponse
    {
        $this->ensureStaff($request->user());
        abort_unless($customer->role === 'customer', 404);
        $conversation = SupportConversation::where('customer_id', $customer->id)->first();

        return response()->json([
            'data' => $conversation ? $this->detail($request, $conversation, true) : [
                'conversation' => null, 'messages' => [], 'has_more' => false,
            ],
            'realtime' => $this->realtime->publicConfiguration(),
        ]);
    }

    public function staffCustomerSend(Request $request, User $customer): JsonResponse
    {
        $this->ensureStaff($request->user());
        abort_unless($customer->role === 'customer', 404);
        $body = $this->validatedBody($request);
        $message = DB::transaction(function () use ($customer, $request, $body): SupportMessage {
            User::whereKey($customer->id)->where('role', 'customer')->lockForUpdate()->firstOrFail();
            $conversation = SupportConversation::firstOrCreate(['customer_id' => $customer->id]);
            $locked = SupportConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $message = $locked->messages()->create([
                'sender_id' => $request->user()->id, 'sender_role' => 'team', 'body' => $body,
            ]);
            $locked->touch();

            return $message;
        });
        $this->realtime->signal($message->support_conversation_id, $customer->id);

        return response()->json(['data' => $this->messagePayload($message->load('sender'))], 201);
    }

    public function staffSend(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->ensureStaff($request->user());
        $body = $this->validatedBody($request);
        $message = DB::transaction(function () use ($conversation, $request, $body): SupportMessage {
            $locked = SupportConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $message = $locked->messages()->create([
                'sender_id' => $request->user()->id, 'sender_role' => 'team', 'body' => $body,
            ]);
            $locked->touch();

            return $message;
        });
        $this->realtime->signal($conversation->id, $conversation->customer_id);

        return response()->json(['data' => $this->messagePayload($message->load('sender'))], 201);
    }

    public function staffRead(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->ensureStaff($request->user());

        return $this->markRead($request, $conversation, 'team');
    }

    public function authorizeRealtime(Request $request): JsonResponse
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $allowed = $data['channel_name'] === 'private-support-team'
            ? $user->hasStaffPermission('manage_support')
            : $user->role === 'customer' && $data['channel_name'] === "private-support-customer-{$user->id}";
        abort_unless($allowed, 403);
        abort_unless($this->realtime->configured(), 503, 'Live chat is not configured.');

        return response()->json($this->realtime->authorize($data['channel_name'], $data['socket_id']));
    }

    private function detail(Request $request, SupportConversation $conversation, bool $staff): array
    {
        $data = $request->validate([
            'before' => ['sometimes', 'integer', 'min:1'],
            'after' => ['sometimes', 'integer', 'min:1'],
        ]);
        if (isset($data['before'], $data['after'])) {
            throw ValidationException::withMessages(['before' => ['Choose either before or after, not both.']]);
        }
        $after = isset($data['after']);
        $limit = $after ? 200 : 40;
        $query = $conversation->messages()->with('sender:id,name');
        if ($after) {
            $query->where('id', '>', $data['after'])->orderBy('id');
        } else {
            if (isset($data['before'])) {
                $query->where('id', '<', $data['before']);
            }
            $query->orderByDesc('id');
        }
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $messages = $rows->take($limit)->map(fn (SupportMessage $message) => $this->messagePayload($message, $staff))->values();
        if (! $after) {
            $messages = $messages->reverse()->values();
        }
        $conversation->loadMissing('customer:id,name,email', 'latestMessage.sender:id,name');

        return [
            'conversation' => $this->conversationPayload($conversation, $staff),
            'messages' => $messages,
            'has_more' => $hasMore,
        ];
    }

    private function markRead(Request $request, SupportConversation $conversation, string $reader): JsonResponse
    {
        $data = $request->validate(['last_seen_message_id' => ['required', 'integer', 'min:1']]);
        $senderRole = $reader === 'team' ? 'customer' : 'team';
        $message = $conversation->messages()->where('sender_role', $senderRole)->findOrFail($data['last_seen_message_id']);
        $column = $reader === 'team' ? 'team_last_read_message_id' : 'customer_last_read_message_id';
        $lastRead = DB::transaction(function () use ($conversation, $column, $message): int {
            $locked = SupportConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $lastRead = max($locked->$column ?? 0, $message->id);
            DB::table('support_conversations')->where('id', $locked->id)->update([$column => $lastRead]);

            return $lastRead;
        });
        if ($reader === 'team') {
            $this->realtime->signal($conversation->id, $conversation->customer_id);
        }

        return response()->json(['data' => ['last_read_message_id' => $lastRead]]);
    }

    private function conversationPayload(SupportConversation $conversation, bool $includeEmail = true): array
    {
        return [
            'id' => $conversation->id,
            'customer' => [
                'id' => $conversation->customer_id,
                'name' => $conversation->customer?->name,
                'email' => $includeEmail ? $conversation->customer?->email : null,
            ],
            'latest_message' => $conversation->latestMessage ? $this->messagePayload($conversation->latestMessage, $includeEmail) : null,
            'unread_count' => $conversation->unread_count ?? $conversation->messages()
                ->where('sender_role', $includeEmail ? 'customer' : 'team')
                ->where('id', '>', $includeEmail ? ($conversation->team_last_read_message_id ?? 0) : ($conversation->customer_last_read_message_id ?? 0))
                ->count(),
            'updated_at' => $conversation->updated_at,
        ];
    }

    private function messagePayload(SupportMessage $message, bool $staff = true): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->support_conversation_id,
            'sender_role' => $message->sender_role,
            'sender_name' => $message->sender_role === 'team'
                ? ($staff ? ($message->sender?->name ?? 'Support Team') : 'Support Team')
                : ($message->sender?->name ?? 'Customer'),
            'body' => $message->body,
            'created_at' => $message->created_at,
        ];
    }

    private function validatedBody(Request $request): string
    {
        $request->merge(['body' => trim((string) $request->input('body'))]);

        return $request->validate(['body' => ['required', 'string', 'max:2000']])['body'];
    }

    private function ensureCustomer(User $user): void
    {
        abort_unless($user->role === 'customer', 403);
    }

    private function ensureStaff(User $user): void
    {
        abort_unless($user->hasStaffPermission('manage_support'), 403);
    }
}
