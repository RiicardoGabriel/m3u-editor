<?php

namespace App\Jobs;

use App\Facades\ProxyFacade;
use App\Models\Series;
use App\Models\User;
use App\Services\PlaylistService;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncSeriesStrmFiles implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?Series $series = null,
        public bool $notify = true,
        public bool $all_playlists = false,
        public ?int $playlist_id = null,
        public ?int $user_id = null,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        // Get all the series episodes
        $series = $this->series;
        if ($series) {
            $this->fetchMetadataForSeries($series, $settings);
        } else {
            // Disable notifications for bulk processing
            $this->notify = false;

            // Process all series in chunks
            Series::query()
                ->where('enabled', true)
                ->when($this->playlist_id, function ($query) {
                    $query->where('playlist_id', $this->playlist_id);
                })
                ->with(['enabled_episodes', 'playlist', 'user', 'category'])
                ->chunkById(100, function ($seriesChunk) use ($settings) {
                    foreach ($seriesChunk as $series) {
                        $this->fetchMetadataForSeries($series, $settings);
                    }
                });

            // Notify the user we're done!
            if ($this->user_id) {
                $user = User::find($this->user_id);
                if ($user) {
                    Notification::make()
                        ->success()
                        ->title('Sync .strm files for series completed')
                        ->body('Sync completed for all series.')
                        ->broadcast($user)
                        ->sendToDatabase($user);
                }
            }
        }
    }

    private function fetchMetadataForSeries(Series $series, $settings)
    {
        $series->load('enabled_episodes', 'playlist', 'user', 'category');

        $playlist = $series->playlist;
        try {
            // Get playlist sync settings
            $sync_settings = $series->sync_settings;

            // Get global sync settings
            $global_sync_settings = [
                'enabled' => $settings->stream_file_sync_enabled ?? false,
                'include_category' => $settings->stream_file_sync_include_category ?? true,
                'include_series' => $settings->stream_file_sync_include_series ?? true,
                'include_season' => $settings->stream_file_sync_include_season ?? true,
                'sync_location' => $series->sync_location ?? $settings->stream_file_sync_location ?? null,
                'path_structure' => $settings->stream_file_sync_path_structure ?? ['category', 'series', 'season'],
                'filename_metadata' => $settings->stream_file_sync_filename_metadata ?? [],
                'tmdb_id_format' => $settings->stream_file_sync_tmdb_id_format ?? 'square',
                'clean_special_chars' => $settings->stream_file_sync_clean_special_chars ?? false,
                'remove_consecutive_chars' => $settings->stream_file_sync_remove_consecutive_chars ?? false,
                'replace_char' => $settings->stream_file_sync_replace_char ?? 'space',
            ];

            // Merge global settings with series specific settings
            $sync_settings = array_merge($global_sync_settings, $sync_settings ?? []);

            // Check if sync is enabled
            if (! $sync_settings['enabled'] ?? false) {
                if ($this->notify) {
                    Notification::make()
                        ->danger()
                        ->title("Error sync .strm files for series \"{$series->name}\"")
                        ->body('Sync is not enabled for this series.')
                        ->broadcast($series->user)
                        ->sendToDatabase($series->user);
                }

                return;
            }

            // Get the series episodes
            $episodes = $series->enabled_episodes;

            // Check if there are any episodes
            if ($episodes->isEmpty()) {
                if ($this->notify) {
                    Notification::make()
                        ->danger()
                        ->title("Error sync .strm files for series \"{$series->name}\"")
                        ->body('No episodes found for this series. Try processing it first.')
                        ->broadcast($series->user)
                        ->sendToDatabase($series->user);
                }

                return;
            }

            // Get the path info
            $path = rtrim($sync_settings['sync_location'], '/');
            if (! is_dir($path)) {
                if ($this->notify) {
                    Notification::make()
                        ->danger()
                        ->title("Error sync .strm files for series \"{$series->name}\"")
                        ->body("Sync location \"{$path}\" does not exist.")
                        ->broadcast($series->user)
                        ->sendToDatabase($series->user);
                } else {
                    Log::error("Error sync .strm files for series \"{$series->name}\": Sync location \"{$path}\" does not exist.");
                }

                return;
            }

            // Get path structure and replacement character settings
            $pathStructure = $sync_settings['path_structure'] ?? ['category', 'series', 'season'];
            $replaceChar = $sync_settings['replace_char'] ?? 'space';
            $cleanSpecialChars = $sync_settings['clean_special_chars'] ?? false;

            // See if the category is enabled, if not, skip, else create the folder
            if (in_array('category', $pathStructure)) {
                // Create the category folder
                // Remove any special characters from the category name
                $category = $series->category;
                $catName = $category->name ?? $category->name_internal ?? 'Uncategorized';
                $cleanName = $cleanSpecialChars
                    ? PlaylistService::makeFilesystemSafe($catName, $replaceChar)
                    : PlaylistService::makeFilesystemSafe($catName);
                $path .= '/' . $cleanName;
                if (! is_dir($path)) {
                    mkdir($path, 0777, true);
                }
            }

            // See if the series is enabled, if not, skip, else create the folder
            if (in_array('series', $pathStructure)) {
                // Create the series folder
                // Remove any special characters from the series name
                $cleanName = $cleanSpecialChars
                    ? PlaylistService::makeFilesystemSafe($series->name, $replaceChar)
                    : PlaylistService::makeFilesystemSafe($series->name);
                $path .= '/' . $cleanName;
                if (! is_dir($path)) {
                    mkdir($path, 0777, true);
                }
            }

            // Get filename metadata settings
            $filenameMetadata = $sync_settings['filename_metadata'] ?? [];
            $tmdbIdFormat = $sync_settings['tmdb_id_format'] ?? 'square';
            $removeConsecutiveChars = $sync_settings['remove_consecutive_chars'] ?? false;

            // Loop through each episode
            foreach ($episodes as $ep) {
                // Setup episode prefix
                $season = $ep->season;
                $num = str_pad($ep->episode_num, 2, '0', STR_PAD_LEFT);
                $prefx = 'S' . str_pad($season, 2, '0', STR_PAD_LEFT) . "E{$num}";

                // Build the base filename
                $fileName = "{$prefx} - {$ep->title}";

                // Add metadata to filename
                if (in_array('year', $filenameMetadata) && ! empty($series->release_date)) {
                    $year = substr($series->release_date, 0, 4);
                    $fileName .= " ({$year})";
                }

                if (in_array('tmdb_id', $filenameMetadata)) {
                    $tmdbId = $series->metadata['tmdb_id'] ?? $ep->info['tmdb_id'] ?? null;
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

                // Create the season folder
                if (in_array('season', $pathStructure)) {
                    $seasonPath = $path . '/Season ' . str_pad($season, 2, '0', STR_PAD_LEFT);
                    if (! is_dir($seasonPath)) {
                        mkdir($seasonPath, 0777, true);
                    }
                    $filePath = $seasonPath . '/' . $fileName;
                } else {
                    $filePath = $path . '/' . $fileName;
                }

                // Generate the url
                $containerExtension = $ep->container_extension ?? 'mp4';
                $url = url("/series/{$playlist->user->name}/{$playlist->uuid}/" . $ep->id . "." . $containerExtension);

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

            // Notify the user
            if ($this->notify) {
                Notification::make()
                    ->success()
                    ->title("Sync .strm files for series \"{$series->name}\"")
                    ->body("Sync completed for series \"{$series->name}\".")
                    ->broadcast($series->user)
                    ->sendToDatabase($series->user);
            }
        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title("Error sync .strm files for series \"{$series->name}\"")
                ->body("Error: {$e->getMessage()}")
                ->broadcast($series->user)
                ->sendToDatabase($series->user);
        }
    }
}
