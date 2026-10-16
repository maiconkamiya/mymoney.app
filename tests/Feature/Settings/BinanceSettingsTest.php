<?php

namespace Tests\Feature\Settings;

use App\Models\Asset;
use App\Models\BrokerCredential;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BinanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Asset::factory()->create(['symbol' => 'BTCUSDT']);
    }

    private function fakeBinance(bool $withdrawals = false): void
    {
        Http::fake([
            '*/sapi/v1/account/apiRestrictions*' => Http::response(['enableSpotAndMarginTrading' => true, 'enableWithdrawals' => $withdrawals]),
            '*/api/v3/account*' => Http::response(['balances' => [['asset' => 'USDT', 'free' => '100', 'locked' => '0']]]),
        ]);
    }

    public function test_saving_a_key_verifies_it_stores_it_encrypted_and_creates_the_portfolio()
    {
        $this->fakeBinance();
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('binance.update'), ['api_key' => 'AK-123', 'api_secret' => 'SK-456'])->assertSessionHasNoErrors();

        $credential = BrokerCredential::sole();
        $this->assertSame($user->id, $credential->user_id);
        $this->assertNotNull($credential->verified_at);
        $this->assertStringNotContainsString('AK-123', (string) $credential->getRawOriginal('api_key'));
        $this->assertStringNotContainsString('SK-456', (string) $credential->getRawOriginal('api_secret'));
        $this->assertEqualsWithDelta(100, Portfolio::sole()->cash, 1e-6);
    }

    public function test_the_page_never_returns_the_key_or_secret()
    {
        $this->fakeBinance();
        $user = User::factory()->create();
        $this->actingAs($user)->put(route('binance.update'), ['api_key' => 'AK-123', 'api_secret' => 'SK-456']);

        $response = $this->actingAs($user)->get(route('binance.edit'));

        $response->assertInertia(fn (Assert $page) => $page->component('settings/Binance')->has('credential', fn (Assert $c) => $c->has('label')->has('configured_at')->where('verified', true)));
        $this->assertStringNotContainsString('AK-123', $response->getContent());
        $this->assertStringNotContainsString('SK-456', $response->getContent());
    }

    public function test_a_key_that_can_withdraw_is_not_stored()
    {
        $this->fakeBinance(withdrawals: true);

        $this->actingAs(User::factory()->create())->put(route('binance.update'), ['api_key' => 'a', 'api_secret' => 'b'])->assertSessionHasErrors('api_key');

        $this->assertSame(0, BrokerCredential::count());
    }

    public function test_a_rejected_key_is_not_stored_and_geoblocking_shows_up()
    {
        Http::fake(['*' => Http::response(['msg' => 'x'], 451)]);

        $this->actingAs(User::factory()->create())->put(route('binance.update'), ['api_key' => 'a', 'api_secret' => 'b'])->assertSessionHasErrors('api_key');

        $this->assertSame(0, BrokerCredential::count());
    }

    public function test_removing_the_key_pauses_the_portfolio()
    {
        $this->fakeBinance();
        $user = User::factory()->create();
        $this->actingAs($user)->put(route('binance.update'), ['api_key' => 'a', 'api_secret' => 'b']);

        $this->actingAs($user)->delete(route('binance.destroy'))->assertRedirect();

        $this->assertSame(0, BrokerCredential::count());
        $this->assertSame('paused', Portfolio::sole()->status->value);
    }

    public function test_replacing_the_key_keeps_a_single_credential()
    {
        $this->fakeBinance();
        $user = User::factory()->create();
        $this->actingAs($user)->put(route('binance.update'), ['api_key' => 'a', 'api_secret' => 'b']);
        $this->actingAs($user)->put(route('binance.update'), ['api_key' => 'c', 'api_secret' => 'd']);

        $this->assertSame(1, BrokerCredential::count());
        $this->assertSame(1, Portfolio::count());
    }

    public function test_a_user_never_touches_another_users_key_or_portfolio()
    {
        $this->fakeBinance();
        $owner = User::factory()->create();
        $this->actingAs($owner)->put(route('binance.update'), ['api_key' => 'a', 'api_secret' => 'b']);
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('binance.edit'))->assertInertia(fn (Assert $page) => $page->where('credential', null)->where('portfolio_id', null));
        $this->actingAs($other)->post(route('binance.sync'))->assertNotFound();
        $this->actingAs($other)->delete(route('binance.destroy'));

        $this->assertSame(1, BrokerCredential::where('user_id', $owner->id)->count());
        $this->assertSame('running', Portfolio::sole()->status->value);
    }
}
