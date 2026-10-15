<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Market data is shared by every signed-in user.
Broadcast::channel('market.status', fn ($user) => $user !== null);
Broadcast::channel('market.{symbol}', fn ($user, string $symbol) => $user !== null && preg_match('/^[A-Z0-9]{3,20}$/', $symbol) === 1);
