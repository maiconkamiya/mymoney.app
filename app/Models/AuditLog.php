<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['actor', 'action', 'subject_type', 'subject_id', 'payload'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

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
