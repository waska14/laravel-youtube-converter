<?php

namespace Waska\LaravelYoutubeConverter\Overrides\YoutubeDl;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Filesystem\Filesystem;
use Waska\LaravelYoutubeConverter\Exceptions\YoutubeDlRuntimeException;
use YoutubeDl\Metadata\DefaultMetadataReader;
use YoutubeDl\Metadata\MetadataReaderInterface;
use YoutubeDl\Process\DefaultProcessBuilder;
use YoutubeDl\Process\ProcessBuilderInterface;
use YoutubeDl\YoutubeDl as BaseYoutubeDl;

class YoutubeDl extends BaseYoutubeDl
{
    protected ProcessBuilderInterface $processBuilder;
    protected MetadataReaderInterface $metadataReader;
    protected Filesystem $filesystem;
    protected ?string $binPath = null;
    protected ?string $pythonPath = null;
    protected $progress;
    protected $debug;

    public function __construct(ProcessBuilderInterface $processBuilder = null, MetadataReaderInterface $metadataReader = null, Filesystem $filesystem = null)
    {
        $this->processBuilder = $processBuilder ?? new DefaultProcessBuilder();
        $this->metadataReader = $metadataReader ?? new DefaultMetadataReader();
        $this->filesystem = $filesystem ?? new Filesystem();
        $this->progress = static function (string $progressTarget, string $percentage, string $size, ?string $speed, ?string $eta, ?string $totalTime): void {
        };
        $this->debug = static function (string $type, string $buffer): void {
        };
        parent::__construct($processBuilder, $metadataReader, $filesystem);
        if ($path = config('laravel-youtube-converter.bin_path')) {
            $this->setBinPath($path);
        }
        if ($path = config('laravel-youtube-converter.python_path')) {
            $this->setPythonPath($path);
        }
    }

    public function setBinPath(?string $binPath): self
    {
        $this->binPath = $binPath;
        return $this;
    }

    public function setPythonPath(?string $pythonPath): self
    {
        $this->pythonPath = $pythonPath;
        return $this;
    }

    public function onProgress(callable $onProgress): self
    {
        $this->progress = $onProgress;
        return $this;
    }

    public function debug(callable $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    public function getQuietUrl(string $url): ?string
    {
        $process = $this->processBuilder->build($this->binPath, $this->pythonPath, ['--youtube-skip-dash-manifest', '-f', 'b', '-g', $url]);
        $output = $this->getProcessOutput($process);
        return Arr::first(array_values(array_filter(explode("\n", $output))));
    }

    public function getData(string $videoUrl): ?array
    {
        $cacheKey = $this->getCacheKey($videoUrl);
        $data = null;
        if (!Cache::get($cacheKey)) {
            $params = [];
            if ($cookiePath = config('laravel-youtube-converter.cookies_path')) {
                $params = ['--cookies', $cookiePath];
            }

            // Include youtube playlist index
            try {
                parse_str(parse_url($videoUrl)['query'], $query);
                if (Arr::has($query, 'list')) {
                    $params = array_merge($params, ['--playlist-items', Arr::get($query, 'index') ?: 1]);
                }
            } catch (\Throwable $e) {
            }

            $process = $this->processBuilder->build($this->binPath, $this->pythonPath, array_merge($params, [
                '-f',
                'b',
                '--no-warnings',
                '--get-url',
                '--get-title',
                '--get-id',
                '--youtube-skip-dash-manifest',
                '--youtube-skip-hls-manifest',
                $videoUrl
            ]));

            $output = $this->getProcessOutput($process);
            $data = array_filter(preg_split('/[\r\n]/', $output));

            // Try max 5 times, because sometimes yt-dlp returns only url of the video without title and id.
            for ($i = 0; $i < 5; $i++) {
                if (count($data) === 3) {
                    break;
                }
                sleep(1);
            }

            if (count($data) !== 3) {
                Log::error('YoutubeDl file data was not found: ' . $videoUrl, $data);
                return null;
            }

            $data = array_combine(['title', 'id', 'url'], $data);

            // Attempt to extract the expiration time from the video URL.
            // If an expiration time is found, cache the URL for that duration to prevent redundant endpoint calls.
            try {
                if ($parsed = parse_url($data['url'])) {
                    $query = Arr::get($parsed, 'query');
                    parse_str($query, $query);
                    if ($expire = Arr::get($query, 'expire')) {
                        $expire = Carbon::createFromTimestamp($expire);
                        if ($expire && $expire > now()) {
                            Cache::put($cacheKey, $data, $expire);
                        }
                    }
                }
            } catch (\Throwable $e) {
                return $data;
            }
        }

        return Cache::get($cacheKey) ?: $data;
    }

    public function getCacheKey(string $videoUrl): string
    {
        return sprintf('YoutubeDl:%s', md5($videoUrl));
    }

    public function getVideoAndSubtitles(string $videoUrl, string $subtitleFormat = 'vtt'): ?array
    {
        $params = [];
        if ($cookiePath = config('laravel-youtube-converter.cookies_path')) {
            $params = [
                '--cookies',
                $cookiePath
            ];
        }
        // Include playlist index
        try {
            parse_str(parse_url($videoUrl)['query'], $query);
            if (Arr::has($query, 'list')) {
                $params = array_merge($params, ['--playlist-items', Arr::get($query, 'index') ?: 1]);
            }
        } catch (\Throwable $e) {
        }
        $process = $this->processBuilder->build($this->binPath, $this->pythonPath, array_merge($params, [
            '-f',
            'b',
            '--get-url',
            '--youtube-skip-dash-manifest',
            '--youtube-skip-hls-manifest',
            '--convert-subs',
            'vtt',
            $videoUrl,
            '--print',
            "%(subtitles)s",
        ]));
        for ($i = 0; $i < 5; $i++) {
            $output = $this->getProcessOutput($process);
            $data = array_filter(preg_split('/[\r\n]/', $output));
            if (count($data) != 2) {
                sleep(1); // Try again
            } else {
                break;
            }
        }
        if (count($data) == 2) {
            $data = array_combine(['subtitles', 'url'], $data);
            $rnd = '|' . Str::random(60) . 'waska|';
            $subtitles = str_replace("\\'", $rnd, $data['subtitles']);
            $subtitles = str_replace('\'', '"', $subtitles);
            $subtitles = str_replace($rnd, '\'', $subtitles);

            $data['subtitles'] = collect(json_decode($subtitles, true))->map(function (array $subtitle) use ($subtitleFormat) {
                return collect($subtitle)
                    ->filter(function ($formatData) use ($subtitleFormat) {
                        return is_array($formatData) && Str::lower(Arr::get($formatData, 'ext')) == Str::lower($subtitleFormat);
                    })
                    ->first();
            })->filter(function ($item) {
                return is_array($item) && Arr::has($item, ['url', 'name']);
            })->map(function (array $item) {
                return Arr::only($item, ['url', 'name']);
            })->all();

            return $data;
        }
        return null;
    }

    public function getDuration(string $url)
    {
        $process = $this->processBuilder->build($this->binPath, $this->pythonPath, ['--get-duration', $url]);
        $output = $this->getProcessOutput($process);
        $duration = Arr::first(array_values(array_filter(explode("\n", $output))));
        return $this->timestampToSeconds($duration);
    }

    public function timestampToSeconds(string $timestamp): int
    {
        $splitted = explode(':', $timestamp);
        $reversed = array_reverse($splitted);
        $seconds = 0;
        $multiplier = 1;
        for ($i = 0; $i < count($reversed); $i++) {
            $seconds += intval($reversed[$i]) * $multiplier;
            $multiplier *= 60;
        }
        return $seconds;
    }

    public function secondsToTimestamp(float $seconds): string
    {
        list($whole, $decimal) = sscanf($seconds, '%d.%d');
        return sprintf('%s.%s', gmdate('H:i:s', $whole), round($decimal, 2));
    }

    protected function getProcessOutput($process)
    {
        $output = null;
        $process->setEnv([
            'LC_ALL' => config('laravel-youtube-converter.lc_all'),
        ]);
        $process->run(function ($type, $value) use (&$output) {
            if ($type == 'err') {
                throw new YoutubeDlRuntimeException($value);
            }
            $output = $value;
        });
        return $output;
    }
}
