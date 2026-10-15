<?php

namespace Tests\Feature\MarketData;

use App\Events\CandleUpdated;
use App\Events\CollectorStatusChanged;
use App\MarketData\CollectorStatus;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MarketBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // Channels were registered on the "null" driver at boot; register them on the one under test.
        require base_path('routes/channels.php');
    }

    public function test_candle_updates_go_to_a_private_channel_per_symbol_without_a_queue()
    {
        $candle = ['time' => 1_792_065_600, 'open' => 1.0, 'high' => 2.0, 'low' => 0.5, 'close' => 1.5, 'volume' => 3.0];
        $event = new CandleUpdated('BTCUSDT', '1m', $candle, false, 1_792_065_612);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertEquals([new PrivateChannel('market.BTCUSDT')], $event->broadcastOn());
        $this->assertSame('candle.updated', $event->broadcastAs());
        $this->assertSame([
            'symbol' => 'BTCUSDT',
            'timeframe' => '1m',
            'candle' => $candle,
            'closed' => false,
            'event_at' => 1_792_065_612,
        ], $event->broadcastWith());
    }

    public function test_collector_status_changes_carry_the_current_snapshot()
    {
        app(CollectorStatus::class)->markConnected();
        $event = new CollectorStatusChanged;

        $this->assertEquals([new PrivateChannel('market.status')], $event->broadcastOn());
        $this->assertSame('collector.status', $event->broadcastAs());
        $this->assertTrue($event->broadcastWith()['connected']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function channels(): array
    {
        return ['symbol' => ['private-market.BTCUSDT'], 'status' => ['private-market.status']];
    }

    #[DataProvider('channels')]
    public function test_signed_in_users_can_subscribe_to_market_channels(string $channel)
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    #[DataProvider('channels')]
    public function test_guests_cannot_subscribe_to_market_channels(string $channel)
    {
        $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertForbidden();
    }

    public function test_odd_symbol_names_are_refused()
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-market.btc usdt!'])
            ->assertForbidden();
    }
}
