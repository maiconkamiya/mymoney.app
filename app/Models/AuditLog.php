<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $actor
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable $created_at
 * @property-read Model|null $subject
 */
#[Fillable(['actor', 'action', 'subject_type', 'subject_id', 'payload'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(string $actor, string $action, ?Model $subject = null, array $payload = []): self
    {
        return self::create([
            'actor' => $actor,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'payload' => $payload,
        ]);
    }
}
