<?php

use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('portfolios.{portfolio}.agents', fn (User $user, int $portfolio) => Portfolio::query()->whereKey($portfolio)->where('user_id', $user->id)->exists());
