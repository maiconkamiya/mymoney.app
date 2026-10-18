<?php

namespace App\Settings;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The system-wide switches the owner turns on and off in Configurações >
 * Custos e serviços. Each one overrides a config value; the `.env` keeps the
 * starting value until someone saves the page. They are applied on every boot
 * and before every queued job, so the scheduler, the workers and the web see
 * a change without a deploy or `config:clear`.
 */
class SystemSettings
{
    private const CACHE_KEY = 'system_settings';

    /**
     * Config path => kind and the options a choice accepts.
     *
     * @var array<string, array{type: 'bool'}|array{type: 'choice', options: list<string|int>}>
     */
    public const SETTINGS = [
        'agents.models.skeptic' => ['type' => 'choice', 'options' => ['claude-opus-5-5', 'claude-sonnet-5-5']],
        'trading.scan_interval_minutes' => ['type' => 'choice', 'options' => [15, 30, 60]],
        'agents.risk_needs_direction' => ['type' => 'bool'],
        'agents.skip_skeptic_on_late_entry' => ['type' => 'bool'],
        'agents.compact_json' => ['type' => 'bool'],
        'news.b3_sources' => ['type' => 'bool'],
        'trading.b3_sync' => ['type' => 'bool'],
        'forecast.enabled' => ['type' => 'bool'],
        'explorer.enabled' => ['type' => 'bool'],
        'explorer.ai.enabled' => ['type' => 'bool'],
    ];

    /**
     * Current value of every switch (saved value, or the config/.env one).
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = [];

        foreach (self::SETTINGS as $key => $setting) {
            $value = config($key);
            $values[$key] = $setting['type'] === 'bool' ? (bool) $value : $value;
        }

        return $values;
    }

    /**
     * Puts the saved values over the config. Safe before the migrations run.
     */
    public function apply(): void
    {
        foreach ($this->saved() as $key => $value) {
            if (array_key_exists($key, self::SETTINGS)) {
                config([$key => $value]);
            }
        }
    }

    /**
     * Saves the switches that changed, with an audit entry, and applies them here.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values, User $user): void
    {
        $changes = [];

        foreach (self::SETTINGS as $key => $setting) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $setting['type'] === 'bool' ? (bool) $values[$key] : $values[$key];
            $before = $this->values()[$key];

            if ($value === $before) {
                continue;
            }

            SystemSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $user->id]);
            $changes[$key] = ['from' => $before, 'to' => $value];
        }

        if ($changes === []) {
            return;
        }

        Cache::forget(self::CACHE_KEY);
        AuditLog::record('user:'.$user->id, 'system_settings.updated', payload: ['changes' => $changes]);
        $this->apply();
    }

    /**
     * Only the e-mails in ADMIN_EMAILS change these switches; without the list, the first account.
     */
    public function canEdit(User $user): bool
    {
        $admins = config('app.admin_emails');

        if ($admins !== []) {
            return in_array(mb_strtolower($user->email), array_map(mb_strtolower(...), $admins), true);
        }

        return $user->id === User::query()->min('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function saved(): array
    {
        try {
            /** @var array<string, mixed> */
            return Cache::rememberForever(self::CACHE_KEY, fn () => SystemSetting::query()->pluck('value', 'key')->all());
        } catch (Throwable) {
            return []; // no table yet (fresh install, migrations pending)
        }
    }
}
