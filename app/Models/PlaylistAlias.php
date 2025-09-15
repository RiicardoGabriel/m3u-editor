<?php

namespace App\Models;

use App\Services\XtreamService;
use App\Traits\ShortUrlTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PlaylistAlias extends Model
{
    use HasFactory;
    use ShortUrlTrait;

    protected $casts = [
        'xtream_config' => 'array',
        'enabled' => 'boolean',
        'priority' => 'integer',
    ];

    protected $fillable = [
        'name',
        'playlist_id',
        'custom_playlist_id',
        'user_id',
        'xtream_config',
        'enabled',
        'priority',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function customPlaylist(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class);
    }

    /**
     * Get the effective playlist (either the main playlist or custom playlist)
     * This method returns the playlist that should be used for configuration
     */
    public function getEffectivePlaylist()
    {
        // Load relationships if not already loaded
        if (!$this->relationLoaded('playlist') && $this->playlist_id) {
            $this->load('playlist');
        }

        if (!$this->relationLoaded('customPlaylist') && $this->custom_playlist_id) {
            $this->load('customPlaylist');
        }

        return $this->playlist ?? $this->customPlaylist;
    }

    /**
     * Get the enable_proxy setting, prioritizing alias config if available
     */
    public function getEnableProxyAttribute(): bool
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        return $effectivePlaylist?->enable_proxy ?? false;
    }

    /**
     * Get the streams limit, prioritizing alias config if available
     */
    public function getStreamsAttribute(): ?int
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        return $effectivePlaylist?->streams;
    }

    /**
     * Get the available_streams limit, prioritizing alias config if available
     */
    public function getAvailableStreamsAttribute(): ?int
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        return $effectivePlaylist?->available_streams;
    }

    /**
     * Check if this alias/playlist supports xtream
     */
    public function getXtreamAttribute(): bool
    {
        return !empty($this->xtream_config);
    }

    /**
     * Get groups through the effective playlist
     */
    public function groups()
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        if (!$effectivePlaylist) {
            return collect();
        }
        return $effectivePlaylist->groups();
    }

    public function groupTags()
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        if (!$effectivePlaylist) {
            return collect();
        }
        return $effectivePlaylist->groupTags();
    }

    public function categoryTags()
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        if (!$effectivePlaylist) {
            return collect();
        }
        return $effectivePlaylist->categoryTags();
    }

    public function channels(): BelongsToMany|HasManyThrough
    {
        if ($this->custom_playlist_id) {
            return $this->belongsToMany(Channel::class, 'channel_custom_playlist', 'custom_playlist_id', 'channel_id');
        }
        return $this->hasManyThrough(
            Channel::class,
            Playlist::class,
            'id', // Foreign key on Playlist table
            'playlist_id', // Foreign key on Channel table
            'playlist_id', // Local key on PlaylistAlias table
            'id'  // Local key on Playlist table
        );
    }

    public function series(): BelongsToMany|HasManyThrough
    {
        if ($this->custom_playlist_id) {
            return $this->belongsToMany(Series::class, 'series_custom_playlist', 'custom_playlist_id', 'series_id');
        }
        return $this->hasManyThrough(
            Series::class,
            Playlist::class,
            'id', // Foreign key on Playlist table
            'playlist_id', // Foreign key on Series table
            'playlist_id', // Local key on PlaylistAlias table
            'id'  // Local key on Playlist table
        );
    }

    public function enabled_channels(): BelongsToMany|HasManyThrough
    {
        return $this->channels()
            ->where('enabled', true);
    }

    public function enabled_series(): BelongsToMany|HasManyThrough
    {
        return $this->series()
            ->where('enabled', true);
    }

    public function live_channels(): BelongsToMany|HasManyThrough
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): BelongsToMany|HasManyThrough
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): BelongsToMany|HasManyThrough
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): BelongsToMany|HasManyThrough
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }

    /**
     * Fetch the Xtream status for this alias
     */
    public function xtreamStatus(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                if (!$this->xtream_config) {
                    return [];
                }
                try {
                    $xtream = XtreamService::make(xtream_config: $this->xtream_config);
                    if ($xtream) {
                        return Cache::remember(
                            "playlist_alias:{$attributes['id']}:xtream_status",
                            5, // cache for 5 seconds
                            function () use ($xtream) {
                                $userInfo = $xtream->userInfo();
                                return $userInfo ?: [];
                            }
                        );
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to fetch metadata for Xtream playlist alias ' . $this->id, ['exception' => $e]);
                }

                return [];
            }
        );
    }

    /**
     * Transform channel URL to use this alias's provider config
     * Only transforms the standard URL, not custom URLs
     */
    public function transformChannelUrl(string $originalUrl): string
    {
        if (!$this->xtream_config) {
            return $originalUrl;
        }

        $effectivePlaylist = $this->getEffectivePlaylist();
        if (!$effectivePlaylist || !$effectivePlaylist->xtream_config) {
            return $originalUrl;
        }

        return $this->transformUrl($originalUrl, $effectivePlaylist->xtream_config, $this->xtream_config);
    }

    /**
     * Transform episode URL to use this alias's provider config
     */
    public function transformEpisodeUrl(string $originalUrl): string
    {
        if (!$this->xtream_config) {
            return $originalUrl;
        }

        $effectivePlaylist = $this->getEffectivePlaylist();
        if (!$effectivePlaylist || !$effectivePlaylist->xtream_config) {
            return $originalUrl;
        }

        return $this->transformUrl($originalUrl, $effectivePlaylist->xtream_config, $this->xtream_config);
    }

    /**
     * Transform URL from source config to alias config
     */
    private function transformUrl(string $originalUrl, array $sourceConfig, array $aliasConfig): string
    {
        // Extract the source provider details
        $sourceBaseUrl = rtrim($sourceConfig['url'], '/');
        $sourceUsername = $sourceConfig['username'];
        $sourcePassword = $sourceConfig['password'];

        // Extract the alias provider details  
        $aliasBaseUrl = rtrim($aliasConfig['url'], '/');
        $aliasUsername = $aliasConfig['username'];
        $aliasPassword = $aliasConfig['password'];

        // Replace the base URL and credentials
        // Pattern matches: http://domain:port/path/username/password/streamid.ext
        $pattern = '#^' . preg_quote($sourceBaseUrl, '#') . '/(live|series|movie)/' . preg_quote($sourceUsername, '#') . '/' . preg_quote($sourcePassword, '#') . '/(.+)$#';

        if (preg_match($pattern, $originalUrl, $matches)) {
            $streamType = $matches[1]; // live, series, or movie
            $streamIdAndExtension = $matches[2]; // e.g., "2083373.mkv" or "12345.ts"

            return "{$aliasBaseUrl}/{$streamType}/{$aliasUsername}/{$aliasPassword}/{$streamIdAndExtension}";
        }

        // If pattern doesn't match, return original URL
        return $originalUrl;
    }
}
