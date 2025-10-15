<?php

use Illuminate\Support\Facades\Route;

/*
 * EPG API routes
 */

Route::group(['prefix' => 'epg'], function () {
    Route::get('{uuid}/data', [\App\Http\Controllers\Api\EpgApiController::class, 'getData'])
        ->name('api.epg.data');
    Route::get('playlist/{uuid}/data', [\App\Http\Controllers\Api\EpgApiController::class, 'getDataForPlaylist'])
        ->name('api.epg.playlist.data');
});

/*
 * Player API routes
 */
Route::group(['prefix' => 'player'], function () {
    Route::get('channel/{id}', [\App\Http\Controllers\Api\PlayerApiController::class, 'channelPlayer'])
        ->name('channel.player');
    Route::get('episode/{id}', [\App\Http\Controllers\Api\PlayerApiController::class, 'episodePlayer'])
        ->name('episode.player');
});

/*
 * m3u-proxy API routes
 */
Route::group(['prefix' => 'm3u-proxy'], function () {
    // Main proxy routes
    Route::post('webhooks', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'handleWebhook'])
        ->name('m3u-proxy.webhook');
    Route::get('channel/{id}', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'channel'])
        ->name('m3u-proxy.channel');
    Route::get('episode/{id}', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'episode'])
        ->name('m3u-proxy.episode');

    // Player preview routes
    Route::get('channel/{id}/player', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'channelPlayer'])
        ->name('m3u-proxy.channel.player');
    Route::get('episode/{id}/player', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'episodePlayer'])
        ->name('m3u-proxy.episode.player');
});
