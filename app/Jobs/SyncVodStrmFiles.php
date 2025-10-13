<?php

namespace App\Jobs;

use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\Playlist;
use App\Services\PlaylistService;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SyncVodStrmFiles implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public bool $notify = true,
        public ?Channel $channel = null,
        public ?Collection $channels = null,
        public ?Playlist $playlist = null,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        try {
            // Get global sync settings
            $global_sync_settings = [
                'enabled' => $settings->vod_stream_file_sync_enabled ?? false,
                'include_season' => $settings->vod_stream_file_sync_include_season ?? true,
                'sync_location' => $settings->vod_stream_file_sync_location ?? null,
                'path_structure' => $settings->vod_stream_file_sync_path_structure ?? ['group'],
                'filename_metadata' => $settings->vod_stream_file_sync_filename_metadata ?? [],
                'tmdb_id_format' => $settings->vod_stream_file_sync_tmdb_id_format ?? 'square',
                'clean_special_chars' => $settings->vod_stream_file_sync_clean_special_chars ?? false,
                'remove_consecutive_chars' => $settings->vod_stream_file_sync_remove_consecutive_chars ?? false,
                'replace_char' => $settings->vod_stream_file_sync_replace_char ?? 'space',
            ];

            // Setup our channels to sync
            $channels = $this->channels ?? collect();
            if ($this->channel) {
                $channels->push($this->channel);
            } elseif ($this->playlist) {
                $channels = $this->playlist->channels()
                    ->where([
                        ['is_vod', true],
                        ['enabled', true],
                        ['source_id', '!=', null],
                    ])
                    ->get();
            }

            // Loop through each channel and sync
            foreach ($channels as $channel) {
                $sync_settings = array_merge($global_sync_settings, $channel->sync_settings ?? []);
                if (! $sync_settings['enabled'] ?? false) {
                    continue;
                }

                // Get the path info
                $path = rtrim($sync_settings['sync_location'], '/');
                if (! is_dir($path)) {
                    if ($this->notify) {
                        Notification::make()
                            ->danger()
                            ->title("Error sync .strm files for VOD channel \"{$channel->title}\"")
                            ->body("Sync location \"{$path}\" does not exist.")
                            ->broadcast($channel->user)
                            ->sendToDatabase($channel->user);
                    } else {
                        Log::error("Error sync .strm files for VOD channel \"{$channel->title}\": Sync location \"{$path}\" does not exist.");
                    }

                    return;
                }

                // Get path structure and replacement character settings
                $pathStructure = $sync_settings['path_structure'] ?? ['group'];
                $replaceChar = $sync_settings['replace_char'] ?? 'space';
                $cleanSpecialChars = $sync_settings['clean_special_chars'] ?? false;
                $filenameMetadata = $sync_settings['filename_metadata'] ?? [];
                $tmdbIdFormat = $sync_settings['tmdb_id_format'] ?? 'square';
                $removeConsecutiveChars = $sync_settings['remove_consecutive_chars'] ?? false;

                // Create the group folder if enabled
                if (in_array('group', $pathStructure)) {
                    $group = $cleanSpecialChars
                        ? PlaylistService::makeFilesystemSafe($channel->group, $replaceChar)
                        : PlaylistService::makeFilesystemSafe($channel->group);
                    $groupPath = $path . '/' . $group;
                    if (! is_dir($groupPath)) {
                        mkdir($groupPath, 0777, true);
                    }
                    $path = $groupPath;
                }

                // Build the filename
                $fileName = $channel->title;

                // Add metadata to filename
                if (in_array('year', $filenameMetadata) && ! empty($channel->year)) {
                    // Only add year if it's not already in the title
                    if (strpos($fileName, "({$channel->year})") === false) {
                        $fileName .= " ({$channel->year})";
                    }
                }

                if (in_array('tmdb_id', $filenameMetadata)) {
                    $tmdbId = $channel->info['tmdb_id'] ?? $channel->movie_data['tmdb_id'] ?? null;
                    if (! empty($tmdbId)) {
                        $bracket = $tmdbIdFormat === 'curly' ? ['{', '}'] : ['[', ']'];
                        $fileName .= " {$bracket[0]}tmdb-{$tmdbId}{$bracket[1]}";
                    }
                }

                // Clean the filename
                $fileName = $cleanSpecialChars
                    ? PlaylistService::makeFilesystemSafe($fileName, $replaceChar)
                    : PlaylistService::makeFilesystemSafe($fileName);

                // Remove consecutive replacement characters if enabled
                if ($removeConsecutiveChars && $replaceChar !== 'remove') {
                    $char = $replaceChar === 'space' ? ' ' : ($replaceChar === 'dash' ? '-' : ($replaceChar === 'underscore' ? '_' : '.'));
                    $fileName = preg_replace('/' . preg_quote($char, '/') . '{2,}/', $char, $fileName);
                }

                $fileName = "{$fileName}.strm";
                $filePath = $path . '/' . $fileName;

                // Generate the url
                $playlist = $this->playlist ?? $channel->getEffectivePlaylist();
                $extension = $channel->container_extension ?? 'mkv';
                $url = rtrim("/movie/{$playlist->user->name}/{$playlist->uuid}/" . $channel->id . "." . $extension, '.');
                $url = PlaylistService::getBaseUrl($url);

                // Check if the file already exists
                if (file_exists($filePath)) {
                    // If the file exists, check if the URL is the same
                    $currentUrl = file_get_contents($filePath);
                    if ($currentUrl === $url) {
                        // Skip if the URL is the same
                        continue;
                    }
                }
                file_put_contents($filePath, $url);
            }
        } catch (\Exception $e) {
            // Log the exception or handle it as needed
            Log::error('Error syncing VOD .strm files: ' . $e->getMessage());
        }
    }
}
