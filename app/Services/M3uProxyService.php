<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Episode;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class M3uProxyService
{
    protected string $apiBaseUrl;
    protected string $apiToken;

    public function __construct()
    {
        $this->apiBaseUrl = rtrim(config('proxy.m3u_proxy_url'), '/');
        $this->apiToken = config('proxy.m3u_proxy_token');
    }

    /**
     * Get active streams count for a specific playlist using metadata filtering
     */
    public static function getPlaylistActiveStreamsCount($playlist): int
    {
        $service = new self();

        if (empty($service->apiBaseUrl)) {
            return 0;
        }

        try {
            $endpoint = $service->apiBaseUrl . '/streams/by-metadata';
            $response = Http::timeout(3)->acceptJson()
                ->withHeaders($service->apiToken ? [
                    'X-API-Token' => $service->apiToken,
                ] : [])
                ->get($endpoint, [
                    'field' => 'playlist_uuid',
                    'value' => $playlist->uuid,
                    'active_only' => true
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['total_clients'] ?? 0; // Return total client count across all streams
            }

            Log::warning('Failed to fetch playlist streams from m3u-proxy: HTTP ' . $response->status());
            return 0;
        } catch (Exception $e) {
            Log::warning('Failed to fetch playlist streams from m3u-proxy: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get active streams for a specific playlist using metadata filtering
     */
    public static function getPlaylistActiveStreams($playlist): array
    {
        $service = new self();

        if (empty($service->apiBaseUrl)) {
            return [];
        }

        try {
            $endpoint = $service->apiBaseUrl . '/streams/by-metadata';
            $response = Http::timeout(3)->acceptJson()
                ->withHeaders($service->apiToken ? [
                    'X-API-Token' => $service->apiToken,
                ] : [])
                ->get($endpoint, [
                    'field' => 'playlist_uuid',
                    'value' => $playlist->uuid,
                    'active_only' => true
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['matching_streams'] ?? [];
            }

            Log::warning('Failed to fetch playlist streams from m3u-proxy: HTTP ' . $response->status());
            return [];
        } catch (Exception $e) {
            Log::warning('Failed to fetch playlist streams from m3u-proxy: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if a specific channel is active using metadata filtering
     */
    public static function isChannelActive(Channel $channel): bool
    {
        $service = new self();

        if (empty($service->apiBaseUrl)) {
            return false;
        }

        try {
            $endpoint = $service->apiBaseUrl . '/streams/by-metadata';
            $response = Http::timeout(2)->acceptJson()
                ->withHeaders($service->apiToken ? [
                    'X-API-Token' => $service->apiToken,
                ] : [])
                ->get($endpoint, [
                    'field' => 'type',
                    'value' => 'channel',
                    'active_only' => true
                ]);

            if ($response->successful()) {
                $data = $response->json();

                // Check if any matching stream has this channel ID
                foreach ($data['matching_streams'] ?? [] as $stream) {
                    if (
                        isset($stream['metadata']['id']) &&
                        $stream['metadata']['id'] == $channel->id &&
                        $stream['client_count'] > 0
                    ) {
                        return true;
                    }
                }
            }

            return false;
        } catch (Exception $e) {
            Log::warning('Failed to check channel active status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get active streams count by any metadata field/value combination
     */
    public static function getActiveStreamsCountByMetadata(string $field, string $value): int
    {
        $service = new self();

        if (empty($service->apiBaseUrl)) {
            return 0;
        }

        try {
            $endpoint = $service->apiBaseUrl . '/streams/by-metadata';
            $response = Http::timeout(3)->acceptJson()
                ->withHeaders($service->apiToken ? [
                    'X-API-Token' => $service->apiToken,
                ] : [])
                ->get($endpoint, [
                    'field' => $field,
                    'value' => $value,
                    'active_only' => true
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['total_clients'] ?? 0;
            }

            return 0;
        } catch (Exception $e) {
            Log::warning("Failed to get active streams count for {$field}={$value}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get cached active streams count with smart invalidation
     */
    public static function getCachedActiveStreamsCountByMetadata(string $field, string $value, int $cacheTtlSeconds = 2): int
    {
        $cacheKey = "m3u_proxy_active_count:{$field}:{$value}";

        // Try to get from cache first
        $cachedCount = Cache::get($cacheKey);
        if ($cachedCount !== null) {
            return $cachedCount;
        }

        // Fetch fresh count
        $count = self::getActiveStreamsCountByMetadata($field, $value);

        // Cache for specified TTL
        Cache::put($cacheKey, $count, now()->addSeconds($cacheTtlSeconds));

        return $count;
    }

    /**
     * Get cached playlist active streams count
     */
    public static function getCachedPlaylistActiveStreamsCount($playlist, int $cacheTtlSeconds = 2): int
    {
        return self::getCachedActiveStreamsCountByMetadata('playlist_uuid', $playlist->uuid, $cacheTtlSeconds);
    }

    /**
     * Invalidate cache for specific metadata field/value
     */
    public static function invalidateMetadataCache(string $field, string $value): void
    {
        $cacheKey = "m3u_proxy_active_count:{$field}:{$value}";
        Cache::forget($cacheKey);
    }

    /**
     * Invalidate cache when we know a playlist's stream status changed
     */
    public static function invalidatePlaylistCache($playlist): void
    {
        self::invalidateMetadataCache('playlist_uuid', $playlist->uuid);
    }

    /**
     * Check if an episode is currently active (being streamed) via m3u-proxy.
     */
    public static function isEpisodeActive(Episode $episode): bool
    {
        $allStreams = (new self())->fetchActiveStreams();
        if (! $allStreams['success']) {
            return false;
        }

        foreach ($allStreams['streams'] as $stream) {
            if (
                isset($stream['metadata']['type'], $stream['metadata']['id']) &&
                $stream['metadata']['type'] === 'episode' &&
                $stream['metadata']['id'] == $episode->id
            ) {
                return $stream['client_count'] > 0;
            }
        }
        return false;
    }

    /**
     * Request or build a channel stream URL from the external m3u-proxy server.
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias  $playlist
     * @param  int  $id
     * @param  Request|null  $request  Optional request for additional parameters (e.g. timeshift)
     *
     * @throws Exception when base URL missing or API returns an error
     */
    public function getChannelUrl($playlist, $id, ?Request $request = null): string
    {
        if (empty($this->apiBaseUrl)) {
            throw new Exception('M3U Proxy base URL is not configured');
        }

        $channel = Channel::find($id);
        if (empty($channel)) {
            throw new Exception('Channel not found');
        }

        // Check if primary playlist has stream limits and if it's at capacity
        $primaryUrl = null;
        if ($playlist->available_streams !== 0) {
            $activeStreams = self::getActiveStreamsCountByMetadata('playlist_uuid', $playlist->uuid);

            // Keep track of original playlist in case we need to check failovers
            $originalUuid = $playlist->uuid;

            if ($activeStreams >= $playlist->available_streams) {
                // Primary playlist is at capacity, check failovers
                $failoverChannels = $channel->failoverChannels()
                    ->select(['channels.id', 'channels.url', 'channels.url_custom'])
                    ->get();

                foreach ($failoverChannels as $failoverChannel) {
                    $failoverPlaylist = $failoverChannel->getEffectivePlaylist();

                    // Check if failover playlist has limits and capacity
                    if ($failoverPlaylist->available_streams === 0) {
                        // No limits on this failover playlist, use it
                        $playlist = $failoverPlaylist;
                        $primaryUrl = PlaylistUrlService::getChannelUrl($failoverChannel, $playlist);
                        break;
                    } else {
                        // Check if failover playlist has capacity
                        $failoverActiveStreams = self::getActiveStreamsCountByMetadata('playlist_uuid', $failoverPlaylist->uuid);

                        if ($failoverActiveStreams < $failoverPlaylist->available_streams) {
                            // Found available failover playlist
                            $playlist = $failoverPlaylist;
                            $primaryUrl = PlaylistUrlService::getChannelUrl($failoverChannel, $playlist);
                            break;
                        }
                    }
                }

                // If we still have the original playlist, all are at capacity
                if ($playlist->uuid === $originalUuid) {
                    Log::info('Channel stream request denied - all playlists at capacity', [
                        'channel_id' => $id,
                        'primary_playlist' => $playlist->uuid,
                        'primary_limit' => $playlist->available_streams,
                        'primary_active' => $activeStreams
                    ]);

                    abort(503, 'All playlists have reached their maximum stream limit. Please try again later.');
                }
            }
        }

        // If we didn't already get a primary URL from failover logic, get it now
        if ($primaryUrl === null) {
            $primaryUrl = PlaylistUrlService::getChannelUrl($channel, $playlist);
        }
        if (empty($primaryUrl)) {
            throw new Exception('Channel primary URL is empty');
        }

        // Check if timeshift parameters are provided
        if ($request && ($request->filled('timeshift_duration') || $request->filled('timeshift_date') || $request->filled('utc'))) {
            $primaryUrl = PlaylistService::generateTimeshiftUrl($request, $primaryUrl, $playlist);
        }

        $userAgent = $playlist->user_agent;
        $failovers = $channel->failoverChannels()
            ->select(['channels.id', 'channels.url', 'channels.url_custom'])->get()
            ->map(fn($ch) => PlaylistUrlService::getChannelUrl($ch, $playlist))
            ->filter()
            ->values()
            ->toArray();

        // Create/fetch the stream from the m3u-proxy API
        $streamId = $this->createOrUpdateStream($primaryUrl, $failovers, $userAgent, [
            'id' => $id,
            'type' => 'channel',
            'playlist_uuid' => $playlist->uuid,
        ]);

        // Get the format from the URL
        $format = pathinfo($primaryUrl, PATHINFO_EXTENSION);
        $format = $format === 'm3u8' ? 'hls' : $format;

        // Return the proxy URL using the stream ID
        return $this->buildProxyUrl($streamId, $format);
    }

    /**
     * Request or build an episode stream URL from the external m3u-proxy server.
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias  $playlist
     * @param  int  $id
     *
     * @throws Exception when base URL missing or API returns an error
     */
    public function getEpisodeUrl($playlist, $id): string
    {
        if (empty($this->apiBaseUrl)) {
            throw new Exception('M3U Proxy base URL is not configured');
        }

        $episode = Episode::find($id);
        if (empty($episode)) {
            throw new Exception('Episode not found');
        }

        // Check if playlist has stream limits and if it's at capacity
        if ($playlist->available_streams !== 0) {
            $activeStreams = self::getCachedActiveStreamsCountByMetadata('playlist_uuid', $playlist->uuid, 1);

            if ($activeStreams >= $playlist->available_streams) {
                Log::info('Episode stream request denied - playlist at capacity', [
                    'episode_id' => $id,
                    'playlist' => $playlist->uuid,
                    'limit' => $playlist->available_streams,
                    'active' => $activeStreams
                ]);

                abort(503, 'Playlist has reached its maximum stream limit. Please try again later.');
            }
        }

        $url = PlaylistUrlService::getEpisodeUrl($episode, $playlist);
        if (empty($url)) {
            throw new Exception('Episode URL is empty');
        }

        $userAgent = $playlist->user_agent;

        // Episodes typically don't have failovers, but we'll support it if needed
        $failoverUrls = [];

        // Create/fetch the stream from the m3u-proxy API
        $streamId = $this->createOrUpdateStream($url, $failoverUrls, $userAgent, [
            'id' => $id,
            'type' => 'episode',
            'playlist_uuid' => $playlist->uuid,
        ]);

        // Get the format from the URL
        $format = pathinfo($url, PATHINFO_EXTENSION);
        $format = $format === 'm3u8' ? 'hls' : $format;

        // Return the proxy URL using the stream ID
        return $this->buildProxyUrl($streamId, $format);
    }

    /**
     * Delete/stop a stream on the external proxy (used by the Filament UI).
     * Returns true on success.
     */
    public function stopStream(string $streamId): bool
    {
        if (empty($this->apiBaseUrl)) {
            Log::warning('M3U Proxy base URL not configured');

            return false;
        }

        try {
            $endpoint = $this->apiBaseUrl . '/streams/' . $streamId;
            $response = Http::timeout(10)->acceptJson()
                ->withHeaders($this->apiToken ? [
                    'X-API-Token' => $this->apiToken,
                ] : [])
                ->delete($endpoint);

            if ($response->successful()) {
                Log::info("Stream {$streamId} stopped successfully");

                return true;
            }

            Log::warning("Failed to stop stream {$streamId}: " . $response->body());

            return false;
        } catch (Exception $e) {
            Log::error("Error stopping stream {$streamId}: " . $e->getMessage());

            return false;
        }
    }

    /**
     * Fetch active streams from external proxy server API.
     * Returns array with 'success', 'streams', and optional 'error' keys.
     */
    public function fetchActiveStreams(): array
    {
        if (empty($this->apiBaseUrl)) {
            return [
                'success' => false,
                'error' => 'M3U Proxy base URL is not configured',
                'streams' => [],
            ];
        }

        try {
            $endpoint = $this->apiBaseUrl . '/streams';
            $response = Http::timeout(5)->acceptJson()
                ->withHeaders($this->apiToken ? [
                    'X-API-Token' => $this->apiToken,
                ] : [])
                ->get($endpoint);
            if ($response->successful()) {
                $data = $response->json() ?: [];

                return [
                    'success' => true,
                    'streams' => $data['streams'] ?? [],
                    'total' => $data['total'] ?? 0,
                ];
            }

            Log::warning('Failed to fetch active streams from m3u-proxy: HTTP ' . $response->status());

            return [
                'success' => false,
                'error' => 'M3U Proxy returned status ' . $response->status(),
                'streams' => [],
            ];
        } catch (Exception $e) {
            Log::warning('Failed to fetch active streams from m3u-proxy: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => 'Unable to connect to m3u-proxy: ' . $e->getMessage(),
                'streams' => [],
            ];
        }
    }

    /**
     * Fetch active clients from external proxy server API.
     * Returns array with 'success', 'clients', and optional 'error' keys.
     */
    public function fetchActiveClients(): array
    {
        if (empty($this->apiBaseUrl)) {
            return [
                'success' => false,
                'error' => 'M3U Proxy base URL is not configured',
                'clients' => [],
            ];
        }

        try {
            $endpoint = $this->apiBaseUrl . '/clients';
            $response = Http::timeout(5)->acceptJson()
                ->withHeaders($this->apiToken ? [
                    'X-API-Token' => $this->apiToken,
                ] : [])
                ->get($endpoint);
            if ($response->successful()) {
                $data = $response->json() ?: [];

                return [
                    'success' => true,
                    'clients' => $data['clients'] ?? [],
                    'total_clients' => $data['total_clients'] ?? 0,
                ];
            }

            Log::warning('Failed to fetch active clients from m3u-proxy: HTTP ' . $response->status());

            return [
                'success' => false,
                'error' => 'M3U Proxy returned status ' . $response->status(),
                'clients' => [],
            ];
        } catch (Exception $e) {
            Log::warning('Failed to fetch active clients from m3u-proxy: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => 'Unable to connect to m3u-proxy: ' . $e->getMessage(),
                'clients' => [],
            ];
        }
    }

    /**
     * Create or update a stream on the m3u-proxy API.
     * Returns the stream ID.
     *
     * @param  string  $url  Primary stream URL
     * @param  array  $failoverUrls  Array of failover URLs
     * @param  string|null  $userAgent  Custom user agent
     * @param  array|null  $metadata  Additional metadata (e.g. ['id' => 123, 'type' => 'channel'])
     * @return string Stream ID
     *
     * @throws Exception when API request fails
     */
    protected function createOrUpdateStream(
        string $url,
        array $failoverUrls = [],
        ?string $userAgent = null,
        ?array $metadata = []
    ): string {
        try {
            $endpoint = $this->apiBaseUrl . '/streams';

            $payload = [
                'url' => $url,
                'failover_urls' => $failoverUrls ?: null,
                'metadata' => $metadata,
            ];

            if (! empty($userAgent)) {
                $payload['user_agent'] = $userAgent;
            }

            $response = Http::timeout(10)
                ->acceptJson()
                ->withHeaders($this->apiToken ? [
                    'X-API-Token' => $this->apiToken,
                ] : [])->post($endpoint, $payload);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['stream_id'])) {
                    Log::info('m3u-proxy stream created/updated successfully', [
                        'stream_id' => $data['stream_id'],
                        'url' => $url,
                    ]);

                    return $data['stream_id'];
                }

                throw new Exception('Stream ID not found in API response');
            }

            throw new Exception('Failed to create stream: ' . $response->body());
        } catch (Exception $e) {
            Log::error('Error creating/updating stream on m3u-proxy', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
            throw $e;
        }
    }

    /**
     * Build the proxy URL for a given stream ID.
     * Uses the configured proxy format (HLS or direct stream).
     *
     * @return string The full proxy URL
     */
    protected function buildProxyUrl(string $streamId, $format = 'hls'): string
    {
        $baseUrl = $this->apiBaseUrl;
        if ($format === 'hls') {
            // HLS format: /hls/{stream_id}/playlist.m3u8
            return $baseUrl . '/hls/' . $streamId . '/playlist.m3u8';
        }

        // Direct stream format: /stream/{stream_id}
        return $baseUrl . '/stream/' . $streamId;
    }
}
