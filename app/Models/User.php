<?php

namespace App\Models;

use App\Support\VersaoDoSistema;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $ultima_versao_vista
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        // Quem acaba de se cadastrar não vê o aviso da versão que já estava no ar.
        static::creating(function (self $usuario): void {
            $usuario->ultima_versao_vista ??= app(VersaoDoSistema::class)->versao();
        });
    }

    /** Com a confirmação de e-mail desligada (`auth.email_verification`), todo e-mail vale como confirmado. */
    public function hasVerifiedEmail(): bool
    {
        return ! config('auth.email_verification', true) || ! is_null($this->email_verified_at);
    }

    /** `true` quando a versão do sistema é posterior à última que esta pessoa viu. */
    public function temNovidadeNaoVista(VersaoDoSistema $versaoDoSistema): bool
    {
        return VersaoDoSistema::ehPosterior($versaoDoSistema->versao(), $this->ultima_versao_vista);
    }

    /** Grava a versão corrente como vista, sem tocar em `updated_at` (não é edição do cadastro). */
    public function marcarVersaoComoVista(VersaoDoSistema $versaoDoSistema): void
    {
        $versao = $versaoDoSistema->versao();

        $this->newQuery()->whereKey($this->getKey())->toBase()->update(['ultima_versao_vista' => $versao]);
        $this->setAttribute('ultima_versao_vista', $versao)->syncOriginalAttribute('ultima_versao_vista');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return HasMany<Portfolio, $this> */
    public function portfolios(): HasMany
    {
        return $this->hasMany(Portfolio::class);
    }
}
