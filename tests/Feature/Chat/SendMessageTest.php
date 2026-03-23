<?php

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Services\AiMemoryService;
use App\Services\AiReasoningService;
use App\Services\MediationAssessmentService;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeUser(string $name = 'Alice'): User
{
    return User::factory()->create([
        'name' => $name,
        'username' => strtolower(str_replace(' ', '_', $name)).'_'.rand(1000, 9999),
    ]);
}

function makeActiveChat(User $creator, User $participant): Chat
{
    $chat = Chat::create([
        'context_type' => 'general',
        'title' => 'Test Chat',
        'created_by' => $creator->id,
        'status' => 'active',
    ]);

    $chat->participants()->attach([$creator->id, $participant->id]);

    return $chat;
}

function mockAiServices(): void
{
    // Replace AI services with mocks so tests never call Anthropic
    app()->instance(AiReasoningService::class, Mockery::mock(AiReasoningService::class, function ($mock) {
        $mock->shouldReceive('triage')->andReturn('respond');
        $mock->shouldReceive('mediateStreaming')->andReturnUsing(function ($chat, $onToken) {
            $onToken('Hello from Accord.');
            Message::create([
                'chat_id' => $chat->id,
                'sender_type' => 'ai',
                'sender_id' => null,
                'content' => 'Hello from Accord.',
            ]);
        });
        $mock->shouldReceive('mediate')->andReturnUsing(function ($chat) {
            return Message::create([
                'chat_id' => $chat->id,
                'sender_type' => 'ai',
                'sender_id' => null,
                'content' => 'Hello from Accord.',
            ]);
        });
        $mock->shouldReceive('generateSummary')->andReturn('Test summary.');
        $mock->shouldReceive('closingRitual')->andReturn(null);
        $mock->shouldReceive('sendEngagementNudge')->andReturn(null);
    }));

    app()->instance(MediationAssessmentService::class, Mockery::mock(MediationAssessmentService::class, function ($mock) {
        $mock->shouldIgnoreMissing();
    }));

    app()->instance(AiMemoryService::class, Mockery::mock(AiMemoryService::class, function ($mock) {
        $mock->shouldIgnoreMissing();
    }));

    app()->instance(WebPushService::class, Mockery::mock(WebPushService::class, function ($mock) {
        $mock->shouldIgnoreMissing();
    }));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test('creator can send a message and gets a streaming response', function () {
    Event::fake();
    mockAiServices();

    $creator = makeUser('Alice');
    $participant = makeUser('Bob');
    $chat = makeActiveChat($creator, $participant);

    $response = $this->actingAs($creator)
        ->withHeaders(['Accept' => 'text/event-stream'])
        ->post("/chats/{$chat->id}/messages", ['content' => 'Hello Bob, let us talk.']);

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

    // User message was saved to DB
    $this->assertDatabaseHas('messages', [
        'chat_id' => $chat->id,
        'sender_type' => 'user',
        'sender_id' => $creator->id,
        'content' => 'Hello Bob, let us talk.',
    ]);
});

test('invited participant can send a message', function () {
    Event::fake();
    mockAiServices();

    $creator = makeUser('Alice');
    $participant = makeUser('Bob');
    $chat = makeActiveChat($creator, $participant);

    $response = $this->actingAs($participant)
        ->withHeaders(['Accept' => 'text/event-stream'])
        ->post("/chats/{$chat->id}/messages", ['content' => 'Hi Alice!']);

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

    $this->assertDatabaseHas('messages', [
        'chat_id' => $chat->id,
        'sender_type' => 'user',
        'sender_id' => $participant->id,
        'content' => 'Hi Alice!',
    ]);
});

test('AI message is saved to DB (non-streaming path)', function () {
    Event::fake();
    mockAiServices();

    $creator = makeUser('Alice');
    $participant = makeUser('Bob');
    $chat = makeActiveChat($creator, $participant);

    // Non-streaming path: no Accept: text/event-stream header → mediate() runs synchronously
    $response = $this->actingAs($creator)
        ->postJson("/chats/{$chat->id}/messages", ['content' => 'We have a disagreement.']);

    $response->assertStatus(200);

    // AI message persisted synchronously by mediate() mock
    $this->assertDatabaseHas('messages', [
        'chat_id' => $chat->id,
        'sender_type' => 'ai',
        'content' => 'Hello from Accord.',
    ]);
});

test('non-participant cannot send a message (403)', function () {
    Event::fake();

    $creator = makeUser('Alice');
    $participant = makeUser('Bob');
    $outsider = makeUser('Charlie');
    $chat = makeActiveChat($creator, $participant);

    $response = $this->actingAs($outsider)
        ->withHeaders(['Accept' => 'text/event-stream'])
        ->post("/chats/{$chat->id}/messages", ['content' => 'I should not be here.']);

    $response->assertStatus(403);

    $this->assertDatabaseMissing('messages', [
        'chat_id' => $chat->id,
        'content' => 'I should not be here.',
    ]);
});

test('cannot send to a waiting chat', function () {
    Event::fake();

    $creator = makeUser('Alice');
    $chat = Chat::create([
        'context_type' => 'general',
        'title' => 'Waiting Chat',
        'created_by' => $creator->id,
        'status' => 'waiting',
    ]);
    $chat->participants()->attach($creator->id);

    $response = $this->actingAs($creator)
        ->withHeaders(['Accept' => 'text/event-stream'])
        ->post("/chats/{$chat->id}/messages", ['content' => 'Hello?']);

    // Should get an error response, not a stream
    $this->assertDatabaseMissing('messages', [
        'chat_id' => $chat->id,
        'content' => 'Hello?',
    ]);
});

test('cannot send to a finalized chat', function () {
    Event::fake();

    $creator = makeUser('Alice');
    $participant = makeUser('Bob');
    $chat = Chat::create([
        'context_type' => 'general',
        'title' => 'Finalized Chat',
        'created_by' => $creator->id,
        'status' => 'finalized',
    ]);
    $chat->participants()->attach([$creator->id, $participant->id]);

    $response = $this->actingAs($creator)
        ->withHeaders(['Accept' => 'text/event-stream'])
        ->post("/chats/{$chat->id}/messages", ['content' => 'One more thing.']);

    $this->assertDatabaseMissing('messages', [
        'chat_id' => $chat->id,
        'content' => 'One more thing.',
    ]);
});

test('polling endpoint returns messages after given ID', function () {
    $creator = makeUser('Alice');
    $participant = makeUser('Bob');
    $chat = makeActiveChat($creator, $participant);

    $msg1 = Message::create([
        'chat_id' => $chat->id, 'sender_type' => 'user',
        'sender_id' => $creator->id, 'content' => 'First message',
    ]);
    $msg2 = Message::create([
        'chat_id' => $chat->id, 'sender_type' => 'user',
        'sender_id' => $participant->id, 'content' => 'Second message',
    ]);

    $response = $this->actingAs($participant)
        ->getJson("/chats/{$chat->id}/messages?after={$msg1->id}");

    $response->assertStatus(200);
    $response->assertJsonCount(1, 'messages');
    $response->assertJsonPath('messages.0.content', 'Second message');
});

test('show page is accessible to participants', function () {
    $creator = makeUser('Alice');
    $participant = makeUser('Bob');
    $chat = makeActiveChat($creator, $participant);

    $response = $this->actingAs($creator)->get("/chats/{$chat->id}");
    $response->assertStatus(200);

    $response = $this->actingAs($participant)->get("/chats/{$chat->id}");
    $response->assertStatus(200);
});

test('show page redirects non-participants', function () {
    $creator = makeUser('Alice');
    $participant = makeUser('Bob');
    $outsider = makeUser('Charlie');
    $chat = makeActiveChat($creator, $participant);

    $response = $this->actingAs($outsider)->get("/chats/{$chat->id}");
    $response->assertRedirect('/chats');
});
