<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Settings\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_account_turns_a_switch_on_and_it_is_applied_and_audited()
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->put(route('system-settings.update'), ['agents__models__skeptic' => 'claude-sonnet-5-5', 'agents__risk_needs_direction' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame('claude-sonnet-5-5', config('agents.models.skeptic'));
        $this->assertTrue(config('agents.risk_needs_direction'));
        $this->assertSame('claude-sonnet-5-5', SystemSetting::findOrFail('agents.models.skeptic')->value);
        $this->assertSame($admin->id, SystemSetting::findOrFail('agents.models.skeptic')->updated_by);

        $log = AuditLog::query()->where('action', 'system_settings.updated')->sole();
        $this->assertSame('user:'.$admin->id, $log->actor);
        $this->assertSame(['from' => false, 'to' => true], $log->payload['changes']['agents.risk_needs_direction']);

        $this->actingAs($admin)->get(route('system-settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('settings/SystemSettings')
            ->where('can_edit', true)
            ->where('values.agents__models__skeptic', 'claude-sonnet-5-5')
            ->where('values.agents__risk_needs_direction', true)
            ->where('values.trading__scan_interval_minutes', 15));
    }

    public function test_another_account_sees_the_values_but_cannot_change_them()
    {
        User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('system-settings.edit'))->assertInertia(fn (Assert $page) => $page->where('can_edit', false));
        $this->actingAs($other)->put(route('system-settings.update'), ['agents__compact_json' => true])->assertForbidden();

        $this->assertFalse(config('agents.compact_json'));
        $this->assertSame(0, SystemSetting::count());
    }

    public function test_admin_emails_decide_who_edits()
    {
        $first = User::factory()->create();
        $listed = User::factory()->create(['email' => 'dono@example.com']);
        config(['app.admin_emails' => ['DONO@example.com']]);

        $this->actingAs($first)->put(route('system-settings.update'), ['trading__b3_sync' => false])->assertForbidden();
        $this->actingAs($listed)->put(route('system-settings.update'), ['trading__b3_sync' => false])->assertSessionHasNoErrors();

        $this->assertFalse(config('trading.b3_sync'));
    }

    public function test_only_the_offered_choices_are_accepted()
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->put(route('system-settings.update'), ['trading__scan_interval_minutes' => 45])->assertSessionHasErrors('trading__scan_interval_minutes');
        $this->actingAs($admin)->put(route('system-settings.update'), ['agents__models__skeptic' => 'claude-fable-5-1'])->assertSessionHasErrors('agents__models__skeptic');
        $this->actingAs($admin)->put(route('system-settings.update'), ['trading__scan_interval_minutes' => '30'])->assertSessionHasNoErrors();

        $this->assertSame(30, config('trading.scan_interval_minutes'));

        $this->actingAs($admin)->put(route('system-settings.update'), ['explorer__breakout__min_score' => 65])->assertSessionHasErrors('explorer__breakout__min_score');
        $this->actingAs($admin)->put(route('system-settings.update'), ['explorer__breakout__min_score' => '70'])->assertSessionHasNoErrors();
        $this->assertSame(70, config('explorer.breakout.min_score'));
        $this->assertSame(0, SystemSetting::query()->where('key', 'agents.models.skeptic')->count());
    }

    public function test_saved_values_override_the_config_on_the_next_boot()
    {
        SystemSetting::create(['key' => 'explorer.enabled', 'value' => false]);
        SystemSetting::create(['key' => 'trading.scan_interval_minutes', 'value' => 60]);
        SystemSetting::create(['key' => 'app.name', 'value' => 'não é uma chave']);
        Cache::flush();
        config(['explorer.enabled' => true, 'trading.scan_interval_minutes' => 15]);

        app(SystemSettings::class)->apply();

        $this->assertFalse(config('explorer.enabled'));
        $this->assertSame(60, config('trading.scan_interval_minutes'));
        $this->assertNotSame('não é uma chave', config('app.name'));
    }

    public function test_guests_are_sent_to_login()
    {
        $this->get(route('system-settings.edit'))->assertRedirect(route('login'));
        $this->put(route('system-settings.update'), ['agents__compact_json' => true])->assertRedirect(route('login'));
    }
}
