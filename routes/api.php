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
 * m3u-proxy API routes
 */
Route::group(['prefix' => 'm3u-proxy'], function () {
    Route::get('channel/{id}', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'channel'])
        ->name('m3u-proxy.channel');
    Route::get('episode/{id}', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'episode'])
        ->name('m3u-proxy.episode');
    Route::get('channel/{id}/player', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'channelPlayer'])
        ->name('m3u-proxy.channel.player');
    Route::get('episode/{id}/player', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'episodePlayer'])
        ->name('m3u-proxy.episode.player');
});
