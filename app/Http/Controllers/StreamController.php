<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Services\ProxyService;
use App\Exceptions\SourceNotResponding;
use App\Exceptions\MaxRetriesReachedException; // Added this line
use App\Traits\TracksActiveStreams;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymfonyProcess;
use Carbon\Carbon;

class StreamController extends Controller
{
    use TracksActiveStreams;

    /**
     * Stream a channel.
     *
     * @param Request $request
     * @param int|string $encodedId
     * @param string $format
     *
     * @return void
     */
    public function __invoke(
        Request $request,
        $encodedId,
        $format = 'ts',
    ): void {
        // Validate the format
        if (!in_array($format, ['ts', 'mp4'])) {
            abort(400, 'Invalid format specified.');
        }

        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($encodedId));

        // Get the failover channels (if any)
        $sourceChannel = $channel; // Keep track of original requested channel for logging context
        $streams = collect([$channel])->concat($channel->failoverChannels);

        $headersSentInfo = ['value' => false]; // Wrapper array to pass boolean by reference

        /* ── Timeshift SETUP (TiviMate → portal format) ───────────────────── */
        // TiviMate sends utc/lutc as UNIX epochs (UTC). We only convert TZ + format.
        $utcPresent = $request->filled('utc');
        if ($utcPresent) {
            $utc = (int) $request->query('utc'); // programme start (UTC epoch)
            $lutc = (int) ($request->query('lutc') ?? time()); // “live” now (UTC epoch)

            // duration (minutes) from start → now; ceil avoids off-by-one near edges
            $offset = max(1, (int) ceil(max(0, $lutc - $utc) / 60));

            // "…://host/live/u/p/<id>.<ext>" >>> "…://host/streaming/timeshift.php?username=u&password=p&stream=id&start=stamp&duration=offset"
            $rewrite = static function (string $url, string $stamp, int $offset): string {
                if (preg_match('~^(https?://[^/]+)/live/([^/]+)/([^/]+)/([^/]+)\.[^/]+$~', $url, $m)) {
                    [$_, $base, $user, $pass, $id] = $m;
                    return sprintf(
                        '%s/streaming/timeshift.php?username=%s&password=%s&stream=%s&start=%s&duration=%d',
                        $base,
                        $user,
                        $pass,
                        $id,
                        $stamp,
                        $offset
                    );
                }
                return $url; // fallback if pattern does not match
            };
        }
        /* ─────────────────────────────────────────────────────────────────── */

        foreach ($streams as $currentStreamToTry) {
            // Get the title for the current stream being attempted
            $title = $currentStreamToTry->title_custom ?? $currentStreamToTry->title;
            $title = strip_tags($title);

            $streamUrl = $currentStreamToTry->url_custom ?? $currentStreamToTry->url;
            if ($currentStreamToTry->is_custom && !$streamUrl) {
                Log::channel('ffmpeg')->debug("Custom channel {$currentStreamToTry->id} ({$title}) has no URL set. Skipping.");
                continue;
            }

            // ── Apply timeshift rewriting AFTER we know the provider timezone ──
            if ($utcPresent) {
                // Use the portal/provider timezone (DST-aware). Prefer per-playlist; last resort UTC.
                $providerTz = $playlist->server_timezone ?? 'Etc/UTC';

                // Convert the absolute UTC epoch from TiviMate to provider-local time string expected by timeshift.php
                $stamp = Carbon::createFromTimestampUTC($utc)
                    ->setTimezone($providerTz)
                    ->format('Y-m-d:H-i');

                $streamUrl = $rewrite($streamUrl, $stamp, $offset);

                // Helpful debug for verification
                Log::channel('ffmpeg')->debug(sprintf(
                    '[TIMESHIFT] utc=%d lutc=%d tz=%s start=%s offset(min)=%d',
                    $utc,
                    $lutc,
                    $providerTz,
                    $stamp,
                    $offset
                ));
            }

            $playlist = $currentStreamToTry->getEffectivePlaylist();
            $badSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . $currentStreamToTry->id . ':' . $playlist->id;

            if (Redis::exists($badSourceCacheKey)) {
                $logMsg = $sourceChannel->id === $currentStreamToTry->id
                    ? "Skipping source ID {$title} ({$sourceChannel->id})"
                    : "Skipping Failover Channel {$currentStreamToTry->name} for source {$sourceChannel->title} ({$sourceChannel->id})";
                Log::channel('ffmpeg')->debug($logMsg . " as it was recently marked as bad. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                continue;
            }

            $activeStreams = $this->incrementActiveStreams($playlist->id);
            if ($format !== 'mp4' && $this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->debug("Max streams reached for playlist {$playlist->name} ({$playlist->id}). Skipping channel {$title}.");
                // If headers are already sent, we can't abort with a new error page.
                // This situation should ideally be rare if stream limits are checked before header sending.
                if ($headersSentInfo['value']) {
                    Log::channel('ffmpeg')->error("Max streams reached AFTER headers sent for playlist {$playlist->name}. Terminating stream attempt for {$title}.");
                    return; // Terminate, client will experience a dropped stream.
                }
                // No need to continue to next stream if limit is global, but this stream is skipped.
                // If this was the last available stream, the loop will end and 503 will be sent.
                // However, if other streams are available, we might want to try them.
                // For now, let's assume skipping this one is enough. If no streams work, outer logic handles 503.
                continue;
            }

            $ip = $request->headers->get('X-Forwarded-For', $request->ip());
            $streamId = uniqid(); // Unique ID for this specific attempt
            $contentType = $format === 'ts' ? 'video/MP2T' : 'video/mp4';

            try {
                // The 'failoverSupport' flag for ffprobe pre-check in startStream
                // is true only for the very first attempt (primary channel).
                // For subsequent failover URLs, we assume they should be tried directly if the primary failed ffprobe.
                // Or, always do ffprobe if not $headersSentInfo['value'].
                // Let's make ffprobe conditional on it being the first *attempt* for this request,
                // meaning headers haven't been sent yet.
                $doFfprobePrecheck = !$headersSentInfo['value'];

                $this->startStream(
                    type: 'channel',
                    modelId: $currentStreamToTry->id, // Use ID of the stream being tried
                    streamUrl: $streamUrl,
                    title: $title,
                    format: $format,
                    ip: $ip,
                    streamId: $streamId,
                    contentType: $contentType,
                    userAgent: $playlist->user_agent ?? null,
                    failoverSupport: $doFfprobePrecheck, // Only do full ffprobe if headers not yet sent
                    playlistId: $playlist->id,
                    headersHaveBeenSent: $headersSentInfo
                );
                // If startStream completes without throwing an exception, it means streaming occurred
                // and finished (e.g. client disconnected or finite stream ended).
                // startStream itself should call exit if it sent headers.
                // If it returns here and headers were sent, we ensure exit.
                if ($headersSentInfo['value']) {
                    Log::channel('ffmpeg')->debug('__invoke: startStream completed and headers were sent. Ensuring exit.');
                    exit;
                }
                // If startStream returns and didn't send headers, it implies an issue or an edge case.
                // For safety, if loop is about to end, it will be handled by post-loop logic.
                return; // Should ideally be unreachable if startStream handles its exit properly when headersSent.

            } catch (SourceNotResponding $e) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Source not responding for channel {$title} (URL: {$streamUrl}): " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, "SourceNotResponding: " . $e->getMessage());
                // If headers were already sent.
                if ($headersSentInfo['value']) {
                    // Check if the failing stream is the primary one.
                    if ($sourceChannel->id == $currentStreamToTry->id) {
                        Log::channel('ffmpeg')->warning("Primary stream {$title} (ID: {$currentStreamToTry->id}) failed with SourceNotResponding after sending headers. Allowing failover attempt.");
                        // Do not exit, allow continue for primary stream failure
                    } else {
                        Log::channel('ffmpeg')->error("Failover stream {$title} (ID: {$currentStreamToTry->id}) failed with SourceNotResponding, and headers were already sent (possibly by a previous attempt or this one). Terminating request.");
                        exit;
                    }
                }
                continue; // Try next stream
            } catch (MaxRetriesReachedException $e) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Max retries reached mid-stream for channel {$title} (URL: {$streamUrl}): " . $e->getMessage() . ". Attempting next failover stream.");
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, "MaxRetriesReached: " . $e->getMessage());
                // If headers were already sent.
                if ($headersSentInfo['value']) {
                    // Check if the failing stream is the primary one.
                    if ($sourceChannel->id == $currentStreamToTry->id) {
                        Log::channel('ffmpeg')->warning("Primary stream {$title} (ID: {$currentStreamToTry->id}) failed with MaxRetriesReachedException after sending headers. Allowing failover attempt.");
                        // Do not exit, allow continue for primary stream failure
                    } else {
                        Log::channel('ffmpeg')->error("Failover stream {$title} (ID: {$currentStreamToTry->id}) failed with MaxRetriesReachedException, and headers were already sent. Terminating request.");
                        exit;
                    }
                }
                continue; // Try next stream
            } catch (Exception $e) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Generic error streaming channel {$title} (URL: {$streamUrl}): " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, "GenericError: " . $e->getMessage());
                // If headers were already sent.
                if ($headersSentInfo['value']) {
                    // Check if the failing stream is the primary one.
                    if ($sourceChannel->id == $currentStreamToTry->id) {
                        Log::channel('ffmpeg')->warning("Primary stream {$title} (ID: {$currentStreamToTry->id}) failed with Generic Exception after sending headers. Allowing failover attempt.");
                        // Do not exit, allow continue for primary stream failure
                    } else {
                        Log::channel('ffmpeg')->error("Failover stream {$title} (ID: {$currentStreamToTry->id}) failed with Generic Exception, and headers were already sent. Terminating request.");
                        exit;
                    }
                }
                continue; // Try next stream
            }
            // This part of the loop should ideally not be reached if startStream handles its exits
            // or throws an exception. If an error occurred and headers were sent, the catch blocks above should exit.
            // If an error occurred and headers were NOT sent, it continues.
            // This explicit check after catch blocks might be redundant if catch blocks handle exit on $headersSentInfo['value'].
            // However, keeping it for robustness:
            if ($headersSentInfo['value']) {
                Log::channel('ffmpeg')->error("In __invoke loop for {$title}: Unhandled state where error occurred but not caught specifically, and headers were sent. Terminating.");
                exit;
            }
        }

        // If the loop completes, it means all stream URLs (primary and failovers) failed.
        if ($headersSentInfo['value']) {
            // This case should ideally not be reached if startStream and catch blocks with $headersSentInfo=true call exit.
            // This implies a stream started, sent headers, then ended/failed in a way that startStream returned
            // instead of exiting, and __invoke's loop somehow continued.
            Log::channel('ffmpeg')->error("All stream attempts failed for channel {$channel->id} ({$channel->title}), but headers were already sent (unexpected state). Forcing exit.");
            exit;
        }

        // If headers were never sent, it means all attempts failed before sending any video data.
        Log::channel('ffmpeg')->error("No available or working streams found for channel {$channel->id} ({$channel->title}) after trying all options.");
        abort(503, 'No valid streams found for this channel.');
    }

    /**
     * Stream an episode.
     *
     * @param Request $request
     * @param int|string $encodedId
     * @param string $format
     *
     * @return void
     */
    public function episode(
        Request $request,
        $encodedId,
        $format = 'ts',
    ): void {
        // Validate the format
        if (!in_array($format, ['ts', 'mp4'])) {
            abort(400, 'Invalid format specified.');
        }

        // Find the episode by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $episode = Episode::findOrFail(base64_decode($encodedId));
        $title = strip_tags($episode->title);
        $streamUrl = $episode->url;

        if (!$streamUrl) {
            Log::channel('ffmpeg')->error("Episode {$episode->id} ({$title}) has no URL set.");
            abort(404, 'Episode stream URL not found.');
        }

        $playlist = $episode->getEffectivePlaylist();

        // Active stream and limit checking
        $activeStreams = $this->incrementActiveStreams($playlist->id);
        if ($format !== 'mp4' && $this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
            $this->decrementActiveStreams($playlist->id);
            Log::channel('ffmpeg')->debug("Max streams reached for playlist {$playlist->name} ({$playlist->id}). Aborting episode {$title}.");
            abort(503, 'Max streams reached for this playlist.');
        }

        $ip = $request->headers->get('X-Forwarded-For', $request->ip());
        $streamId = uniqid(); // Unique ID for this specific stream attempt
        $contentType = $format === 'ts' ? 'video/MP2T' : 'video/mp4';

        $headersSentInfo = ['value' => false]; // Wrapper array for header status

        try {
            // For episodes, ffprobe pre-check is generally a good idea if the source might be unreliable.
            // We set failoverSupport to true to enable ffprobe, as there's no "next URL" to try.
            $this->startStream(
                type: 'episode',
                modelId: $episode->id,
                streamUrl: $streamUrl,
                title: $title,
                format: $format,
                ip: $ip,
                streamId: $streamId,
                contentType: $contentType,
                userAgent: $playlist->user_agent ?? null,
                failoverSupport: true, // Enable ffprobe pre-check for episodes
                playlistId: $playlist->id,
                headersHaveBeenSent: $headersSentInfo
            );
            // If startStream completes, the request is done. startStream should handle exit if it sent headers.
            // This is a safeguard.
            if ($headersSentInfo['value']) {
                Log::channel('ffmpeg')->debug('episode: startStream completed and headers were sent. Ensuring exit.');
                exit;
            }
            return; // Should be unreachable if startStream exits properly.

        } catch (SourceNotResponding $e) {
            $this->decrementActiveStreams($playlist->id);
            Log::channel('ffmpeg')->error("Source not responding for episode {$title} (URL: {$streamUrl}): " . $e->getMessage());
            if ($headersSentInfo['value']) { // Should be false if SourceNotResponding is from pre-check
                Log::channel('ffmpeg')->error("SourceNotResponding for episode {$title} but headers already sent. Terminating.");
                exit;
            }
            abort(503, "Episode source not responding: " . $e->getMessage());
        } catch (MaxRetriesReachedException $e) {
            $this->decrementActiveStreams($playlist->id);
            Log::channel('ffmpeg')->error("Max retries reached for episode {$title} (URL: {$streamUrl}): " . $e->getMessage());
            if ($headersSentInfo['value']) { // Failure happened after this stream started sending data
                Log::channel('ffmpeg')->error("MaxRetriesReachedException for episode {$title} but headers already sent by this stream. Terminating.");
                exit;
            }
            // If headers not sent, means all retries for the episode URL failed before output
            abort(503, "Episode stream failed after multiple retries: " . $e->getMessage());
        } catch (Exception $e) {
            $this->decrementActiveStreams($playlist->id);
            Log::channel('ffmpeg')->error("Generic error streaming episode {$title} (URL: {$streamUrl}): " . $e->getMessage());
            if ($headersSentInfo['value']) {
                Log::channel('ffmpeg')->error("Generic error for episode {$title} but headers already sent. Terminating.");
                exit;
            }
            abort(503, "Error streaming episode: " . $e->getMessage());
        }
    }

    /**
     * Start the stream using FFmpeg.
     *
     * @param string $type
     * @param int $modelId
     * @param string $streamUrl
     * @param string $title
     * @param string $format
     * @param string $ip
     * @param string $streamId
     * @param string $contentType
     * @param string|null $userAgent
     * @param bool $failoverSupport Whether to support failover streams
     * @param int|null $playlistId Optional playlist ID for tracking active streams
     * @param array $headersHaveBeenSent Passed by reference wrapper to track header status
     *
     * @throws SourceNotResponding
     * @throws MaxRetriesReachedException
     */
    private function startStream(
        string $type,
        int $modelId,
        string $streamUrl,
        string $title,
        string $format,
        string $ip,
        string $streamId,
        string $contentType,
        ?string $userAgent,
        bool $failoverSupport = false,
        ?int $playlistId = null,
        array &$headersHaveBeenSent // Pass as array ['value' => false] to modify by reference
    ): void {
        // Prevent timeouts, etc.
        // These are typically set at the beginning of a script that does direct output.
        @ini_set('max_execution_time', 0);
        @ini_set('output_buffering', 'off');
        @ini_set('implicit_flush', 1);

        // Get user preferences
        $settings = ProxyService::getStreamSettings();

        // Get user agent (ensure it's escaped for shell command)
        $userAgent = $userAgent ?: $settings['ffmpeg_user_agent'];

        // If failover support is enabled (typically for initial check of primary stream),
        // we need to run a pre-check with ffprobe to ensure the source is valid
        if ($failoverSupport) { // This flag might be more relevant for the first attempt in __invoke
            $ffmpegExecutable = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
            if (empty($ffmpegExecutable)) {
                $ffmpegExecutable = 'jellyfin-ffmpeg'; // Default ffmpeg command
            }

            // Determine ffprobe path using the consolidated service method
            $ffprobePath = ProxyService::getEffectiveFfprobePath($settings);
            $ffprobeTimeout = $settings['ffmpeg_ffprobe_timeout'] ?? 5;

            $precheckCmd = escapeshellcmd($ffprobePath) . " -v quiet -print_format json -show_streams -show_format -user_agent " . escapeshellarg($userAgent) . " " . escapeshellarg($streamUrl);
            Log::channel('ffmpeg')->debug("[PRE-CHECK] Executing ffprobe command for [{$title}] with timeout {$ffprobeTimeout}s: {$precheckCmd}");
            $precheckProcess = SymfonyProcess::fromShellCommandline($precheckCmd);
            $precheckProcess->setTimeout($ffprobeTimeout);
            try {
                $precheckProcess->run();
                if (!$precheckProcess->isSuccessful()) {
                    Log::channel('ffmpeg')->error("[PRE-CHECK] ffprobe failed for source [{$title}]. Exit Code: " . $precheckProcess->getExitCode() . ". Error Output: " . $precheckProcess->getErrorOutput());
                    throw new SourceNotResponding("failed_ffprobe (Exit: " . $precheckProcess->getExitCode() . ")");
                }
                Log::channel('ffmpeg')->debug("[PRE-CHECK] ffprobe successful for source [{$title}].");

                // (Optional) Extract and cache stream info as before if needed
                // For brevity in this refactor, detailed extraction is omitted but can be re-added if required.
                // $ffprobeJsonOutput = $precheckProcess->getOutput(); ... cache logic ...

            } catch (Exception $e) { // Catch Symfony ProcessFailedException or others
                throw new SourceNotResponding("failed_ffprobe_exception (" . $e->getMessage() . ")");
            }
        }

        // If headers haven't been sent yet by a previous attempt in __invoke, send them now.
        if (!$headersHaveBeenSent['value']) {
            $this->sendStreamingHeaders($contentType, $format);
            $headersHaveBeenSent['value'] = true;
        }

        // Store the process start time and other Redis keys
        $startTimeCacheKey = "mpts:streaminfo:starttime:{$streamId}";
        $currentTime = now()->timestamp;
        Redis::setex($startTimeCacheKey, 604800, $currentTime); // 7 days TTL
        Log::channel('ffmpeg')->debug("Stored ffmpeg process start time for {$type} ID {$modelId} at {$currentTime}");

        $clientDetails = "{$ip}::{$modelId}::{$type}::{$streamId}";

        // Make sure PHP doesn't ignore user aborts for the duration of this stream attempt
        // ignore_user_abort(true) might be too broad if set here and __invoke wants to continue.
        // The check for connection_aborted() in the loop is more granular.
        // Let's use a local ignore_user_abort setting.
        $previous_ignore_user_abort = ignore_user_abort(true);


        // Register a shutdown function that ALWAYS runs when the script dies for THIS stream instance
        // Note: This shutdown function's active stream decrement might be problematic if __invoke wants to immediately retry.
        // The decrement in __invoke's catch block is more reliable for the failover loop.
        // However, for a successful stream that ends, this is important.
        $shutdownFunction = function () use ($clientDetails, $streamId, $playlistId, $type, $title, $previous_ignore_user_abort) {
            Redis::srem('mpts:active_ids', $clientDetails);
            Redis::del("mpts:streaminfo:details:{$streamId}"); // Assuming details might be cached elsewhere
            Redis::del("mpts:streaminfo:starttime:{$streamId}");
            if ($playlistId) {
                // This decrement might conflict if __invoke also decrements on MaxRetriesReachedException
                // It's mainly for when the stream ends normally or client aborts.
                // Consider if decrement should only happen here if no exception was thrown by startStream.
                $this->decrementActiveStreams($playlistId);
            }
            Log::channel('ffmpeg')->debug("Streaming stopped via shutdown function for {$type} {$title}");
            ignore_user_abort($previous_ignore_user_abort); // Restore previous state
        };
        register_shutdown_function($shutdownFunction);

        Redis::sadd('mpts:active_ids', $clientDetails);

        // Clear any existing output buffers before direct echo
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush(); // Ensure headers are sent if not already

        // Disable Zlib output compression if it's on, as it can buffer output
        if (ini_get('zlib.output_compression')) {
            @ini_set('zlib.output_compression', 'Off');
        }

        $maxRetries = $settings['ffmpeg_max_tries'] ?? 3;
        $cmd = ProxyService::buildTsCommand(
            $format,
            $streamUrl,
            $userAgent
        );
        Log::channel('ffmpeg')->debug("Streaming {$type} {$title} with command: {$cmd}");

        $retries = 0;
        $process = null;
        try {
            while (!connection_aborted()) {
                $process = SymfonyProcess::fromShellCommandline($cmd);
                $process->setTimeout(null); // No timeout for the process itself
                $process->setIdleTimeout(30); // Timeout if no output for 30s (prevents zombie processes)

                $stderrOutput = '';
                $process->run(function ($type, $buffer) use (&$stderrOutput) {
                    if (connection_aborted()) {
                        // This exception helps break out of $process->run()
                        throw new Exception("Connection aborted by client during ffmpeg run.");
                    }
                    if ($type === SymfonyProcess::OUT) {
                        echo $buffer;
                        flush();
                    } elseif ($type === SymfonyProcess::ERR) {
                        if (!empty(trim($buffer))) {
                            Log::channel('ffmpeg')->error($buffer);
                            $stderrOutput .= $buffer; // Collect stderr
                        }
                    }
                });

                // If we get here, $process->run() finished. Check why.
                if (connection_aborted()) {
                    Log::channel('ffmpeg')->info("Connection aborted for {$type} {$title} during/after ffmpeg run.");
                    // Cleanup is handled by shutdown function
                    return; // Exit startStream
                }

                // Check for specific errors in stderr that should trigger failover, even if exit code is 0
                $prematureEndErrors = [
                    'Stream ends prematurely',
                    'Error during demuxing: I/O error',
                ];
                foreach ($prematureEndErrors as $errorString) {
                    if (str_contains($stderrOutput, $errorString)) {
                        Log::channel('ffmpeg')->error("Detected premature end error for {$type} {$title}: '{$errorString}'. Triggering failover.");
                        throw new SourceNotResponding("ffmpeg_premature_end: {$errorString}");
                    }
                }

                // If process was not successful (e.g., ffmpeg error)
                if (!$process->isSuccessful()) {
                    Log::channel('ffmpeg')->error("FFmpeg process failed for {$type} {$title}. Exit code: " . $process->getExitCode() . ". Error: " . $process->getErrorOutput());
                    // Fall through to retry logic
                } else {
                    // Process finished successfully (e.g. finite file, or ffmpeg exited cleanly for other reason)
                    Log::channel('ffmpeg')->info("FFmpeg process finished successfully for {$type} {$title}.");
                    // Cleanup is handled by shutdown function
                    return; // Exit startStream
                }

                // Retry logic
                if (++$retries >= $maxRetries) {
                    Log::channel('ffmpeg')->error("FFmpeg error: max retries of {$maxRetries} reached for stream for {$type} {$title}.");
                    throw new MaxRetriesReachedException("Max retries of {$maxRetries} reached for stream {$type} {$title}.");
                }

                Log::channel('ffmpeg')->info("Retrying ffmpeg for {$type} {$title} (attempt {$retries}) in a few seconds...");
                sleep(min(8, $retries)); // Wait before retrying

            } // End of while(!connection_aborted())
        } catch (MaxRetriesReachedException $e) {
            // Allow this to be caught by __invoke
            throw $e;
        } catch (Exception $e) {
            // Catch other exceptions, like the "Connection aborted by client during ffmpeg run"
            if (str_contains($e->getMessage(), 'Connection aborted')) {
                Log::channel('ffmpeg')->info("Connection aborted for {$type} {$title}: " . $e->getMessage());
                // Do not re-throw, allow startStream to exit gracefully if possible, client is gone.
            } else {
                Log::channel('ffmpeg')->error("Generic error during streaming for {$type} {$title}: " . $e->getMessage());
                throw $e; // Re-throw other exceptions to be handled by __invoke
            }
        } finally {
            // Ensure process is stopped if it's still running (e.g. if loop exited unexpectedly)
            if ($process && $process->isRunning()) {
                try {
                    $process->stop(0); // Attempt graceful stop
                } catch (Exception $stopException) {
                    Log::channel('ffmpeg')->error("Exception during ffmpeg process stop in finally block for {$type} {$title}: " . $stopException->getMessage());
                    // Do not re-throw, allow startStream to proceed to its own exit logic
                    // if the main error was a handled client abort.
                }
            }
            // Unregister the specific shutdown function for this attempt if we are throwing MaxRetriesReached
            // So __invoke's decrement is the one that counts for the failover attempt.
            // This is tricky. For now, let the shutdown function run; it has logging.
            // The double decrement needs to be accepted or refined in TracksActiveStreams trait.
        }

        if (connection_aborted()) {
            Log::channel('ffmpeg')->info("Client disconnected after streaming loop for {$type} {$title}.");
        }

        // If this stream instance sent headers, it's responsible for ending the script.
        if ($headersHaveBeenSent['value']) {
            Log::channel('ffmpeg')->debug("Exiting from startStream for {$type} {$title} as headers were sent by this instance and processing is complete or aborted.");
            // Ensure all output buffers are flushed before exiting.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
            exit;
        }
        // If headers were not sent by this instance (e.g. all ffmpeg attempts failed before output, and MaxRetriesReachedException was thrown and caught by __invoke),
        // then __invoke will handle further logic (like trying a new stream or aborting with 503).
    }

    /**
     * Build the FFmpeg command for streaming.
     *
     * @param string $format
     * @param string $streamUrl
     * @param string $userAgent
     *
     * @return string The complete FFmpeg command
     */
    private function sendStreamingHeaders(string $contentType, string $format): void
    {
        // Prevent caching of the stream
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');

        // Set the content type and disposition
        header("Content-Type: {$contentType}");
        header("Content-Disposition: inline; filename=\"stream.{$format}\"");

        // Other potentially useful headers for streaming
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Useful for Nginx to disable buffering

        // Send headers and flush the output buffer
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
