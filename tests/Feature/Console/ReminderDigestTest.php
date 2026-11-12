<?php

namespace Tests\Feature\Console;

use App\Models\Business;
use App\Models\BusinessTask;
use App\Models\User;
use App\Notifications\ReminderDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReminderDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-20 07:00:00');
        Notification::fake();
    }

    public function test_emails_overdue_and_upcoming_deadlines_grouped_by_business(): void
    {
        [$user, $business] = $this->member(['trade_name' => 'Kafe Drini']);
        BusinessTask::factory()->for($business)->create(['title' => 'Deklarata e TVSH-së për qershor', 'due_on' => '2026-07-18']);
        BusinessTask::factory()->for($business)->create(['title' => 'Pagat e korrikut', 'due_on' => '2026-07-25']);
        BusinessTask::factory()->for($business)->create(['title' => 'Far away', 'due_on' => '2026-09-30']);

        $this->artisan('kontotrack:reminders:send')->expectsOutputToContain('1 reminder email(s) sent.')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDigest::class, function (ReminderDigest $digest) use ($user): bool {
            $mail = $digest->toMail($user);
            $text = implode("\n", $mail->introLines);

            return str_contains($mail->subject, '1 me vonesë')
                && str_contains($text, '**Kafe Drini**')
                && str_contains($text, 'Deklarata e TVSH-së për qershor – 18.07.2026 (me vonesë · overdue)')
                && str_contains($text, 'Pagat e korrikut – 25.07.2026')
                && ! str_contains($text, 'Far away');
        });
    }

    public function test_an_unchanged_list_is_not_emailed_again_until_a_week_has_passed(): void
    {
        [$user, $business] = $this->member();
        BusinessTask::factory()->for($business)->create(['due_on' => '2026-07-18']);

        $this->artisan('kontotrack:reminders:send')->assertSuccessful();
        $this->travelTo('2026-07-21 07:00:00');
        $this->artisan('kontotrack:reminders:send')->expectsOutputToContain('0 reminder email(s) sent.')->assertSuccessful();
        Notification::assertSentToTimes($user, ReminderDigest::class, 1);

        BusinessTask::factory()->for($business)->create(['due_on' => '2026-07-22']);
        $this->artisan('kontotrack:reminders:send')->assertSuccessful();
        Notification::assertSentToTimes($user, ReminderDigest::class, 2);

        $this->travelTo('2026-07-29 07:00:00');
        $this->artisan('kontotrack:reminders:send')->assertSuccessful();
        Notification::assertSentToTimes($user, ReminderDigest::class, 3);
    }

    public function test_skips_users_who_turned_emails_off_were_deactivated_or_have_nothing_due(): void
    {
        [$optedOut, $business] = $this->member();
        $optedOut->forceFill(['email_notifications' => false])->save();
        $deactivated = User::factory()->create(['deactivated_at' => now()]);
        $business->users()->attach($deactivated, ['role' => 'accountant', 'status' => 'active']);
        BusinessTask::factory()->for($business)->create(['due_on' => '2026-07-18']);
        [$nothingDue] = $this->member();

        $this->artisan('kontotrack:reminders:send')->expectsOutputToContain('0 reminder email(s) sent.')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($nothingDue->refresh()->notification_digest_sent_at);
    }

    public function test_user_turns_reminder_emails_off_in_preferences(): void
    {
        [$user] = $this->member();

        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.email_notifications', true);
        $this->actingAs($user)->putJson('/api/v1/me/preferences', ['email_notifications' => false])->assertOk()->assertJsonPath('data.email_notifications', false);
        $this->actingAs($user)->putJson('/api/v1/me/preferences', ['email_notifications' => 'sometimes'])->assertUnprocessable();

        $this->assertFalse($user->refresh()->email_notifications);
    }

    /**
     * @param  array<string, mixed>  $businessAttributes
     * @return array{User, Business}
     */
    private function member(array $businessAttributes = []): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create($businessAttributes);
        $business->users()->attach($user, ['role' => 'owner', 'status' => 'active']);

        return [$user, $business];
    }
}
