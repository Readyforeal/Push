<?php

use App\Models\RelationshipInvitation;
use App\Models\User;
use App\Notifications\PartnerJoinedNotification;
use App\Services\RelationshipPairing;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('a user can create a private relationship invitation', function () {
    $inviter = User::factory()->create();

    $created = app(RelationshipPairing::class)->invite(
        $inviter,
        'Partner@Example.com',
        'America/Chicago',
    );

    expect($created->invitation->email)->toBe('partner@example.com')
        ->and($created->invitation->token_hash)->not->toBe($created->token)
        ->and($created->invitation->relationship->timezone)->toBe('America/Chicago')
        ->and($created->invitation->relationship->hasMember($inviter))->toBeTrue();
});

test('the invited user can accept and pair both accounts', function () {
    Notification::fake();
    $inviter = User::factory()->create();
    $partner = User::factory()->create(['email' => 'partner@example.com']);
    $created = app(RelationshipPairing::class)->invite($inviter, $partner->email, 'America/Chicago');

    $relationship = app(RelationshipPairing::class)->accept($partner, $created->token);

    expect($relationship->members)->toHaveCount(2)
        ->and($created->invitation->fresh()->accepted_at)->not->toBeNull();
    Notification::assertSentTo($inviter, PartnerJoinedNotification::class);
});

test('an invitation can only be accepted by its intended email', function () {
    $inviter = User::factory()->create();
    $wrongUser = User::factory()->create(['email' => 'someone-else@example.com']);
    $created = app(RelationshipPairing::class)->invite($inviter, 'partner@example.com', 'UTC');

    app(RelationshipPairing::class)->accept($wrongUser, $created->token);
})->throws(DomainException::class, 'This invitation was sent to partner@example.com.');

test('creating a new invitation cancels the previous invitation', function () {
    $inviter = User::factory()->create();
    $pairing = app(RelationshipPairing::class);
    $first = $pairing->invite($inviter, 'first@example.com', 'UTC');
    $second = $pairing->invite($inviter, 'second@example.com', 'UTC');

    expect($first->invitation->fresh()->isPending())->toBeFalse()
        ->and($second->invitation->isPending())->toBeTrue();
});

test('the reusable invitation modal can create an invitation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('relationship-invitation')
        ->set('email', 'partner@example.com')
        ->set('timezone', 'America/Chicago')
        ->call('createInvitation')
        ->assertHasNoErrors()
        ->assertSet('email', '')
        ->assertSet('inviteUrl', fn (?string $url) => str_contains((string) $url, '/invitations/'));

    expect(RelationshipInvitation::query()->where('email', 'partner@example.com')->exists())->toBeTrue();
});

test('the intended partner can view the invitation acceptance page', function () {
    $inviter = User::factory()->create(['name' => 'Jamie']);
    $partner = User::factory()->create(['email' => 'partner@example.com']);
    $created = app(RelationshipPairing::class)->invite($inviter, $partner->email, 'UTC');

    $this->actingAs($partner)
        ->get(route('invitations.accept', $created->token))
        ->assertOk()
        ->assertSee('Join Jamie')
        ->assertSee('partner@example.com');
});

test('the intended partner can accept a pending invitation from home', function () {
    Notification::fake();
    $inviter = User::factory()->create(['name' => 'Jamie']);
    $partner = User::factory()->create(['email' => 'test2@test.com']);
    app(RelationshipPairing::class)->invite($inviter, $partner->email, 'America/Chicago');
    $this->actingAs($partner);

    Livewire::test('relationship-invitation')
        ->assertSee('Join Jamie')
        ->assertSee('Accept invitation')
        ->call('acceptIncomingInvitation')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect($partner->fresh()->relationships()->first()->members()->count())->toBe(2);
});
