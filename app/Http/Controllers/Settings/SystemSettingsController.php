<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Settings\SystemSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações > Custos e serviços: system-wide switches for what spends AI
 * tokens and for the services the app does not use right now. Everyone sees
 * the current values; only the administrator changes them.
 */
class SystemSettingsController extends Controller
{
    public function edit(Request $request, SystemSettings $settings): Response
    {
        return Inertia::render('settings/SystemSettings', [
            'values' => collect($settings->values())->mapWithKeys(fn (mixed $value, string $key) => [$this->field($key) => $value])->all(),
            'can_edit' => $settings->canEdit($request->user()),
        ]);
    }

    public function update(Request $request, SystemSettings $settings): RedirectResponse
    {
        abort_unless($settings->canEdit($request->user()), 403);

        $rules = [];

        foreach (SystemSettings::SETTINGS as $key => $setting) {
            $rules[$this->field($key)] = $setting['type'] === 'bool'
                ? ['sometimes', 'boolean']
                : ['sometimes', Rule::in($setting['options'])];
        }

        $data = $request->validate($rules);
        $values = [];

        foreach (SystemSettings::SETTINGS as $key => $setting) {
            $field = $this->field($key);

            if (array_key_exists($field, $data)) {
                // Escolhas numéricas voltam do formulário como texto.
                $values[$key] = $setting['type'] === 'choice' && is_int($setting['options'][0]) ? (int) $data[$field] : $data[$field];
            }
        }

        $settings->save($values, $request->user());

        return back();
    }

    /**
     * Form field for a config path: the dots would read as nested input.
     */
    private function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
