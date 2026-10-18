<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Análise do dia de um ativo ("Por que X está em queda hoje?"), escrita por um
 * modelo barato a partir das velas, do sentimento e das notícias já coletadas.
 *
 * @property int $id
 * @property int $asset_id
 * @property string $direction up, down ou flat
 * @property float $change_24h_pct
 * @property string $summary
 * @property list<string> $points
 * @property int $news_count
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property float $cost
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Asset $asset
 */
#[Fillable(['asset_id', 'direction', 'change_24h_pct', 'summary', 'points', 'news_count', 'model', 'input_tokens', 'output_tokens', 'cost'])]
class AssetInsight extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'change_24h_pct' => 'float',
            'points' => 'array',
            'cost' => 'float',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
