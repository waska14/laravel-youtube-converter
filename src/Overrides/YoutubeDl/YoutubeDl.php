<?php

namespace Waska\LaravelYoutubeConverter\Overrides\YoutubeDl;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        $process = $this->processBuilder->build($this->binPath, $this->pythonPath, ['--youtube-skip-dash-manifest', '-f', 'best', '-g', $url]);
        $output = $this->getProcessOutput($process);
        return Arr::first(array_values(array_filter(explode("\n", $output))));
    }

    public function getData(string $videoUrl): ?array
    {
        if (!Cache::get($videoUrl)) {
            $cookieParams = [];
            if ($cookiePath = config('laravel-youtube-converter.cookies_path')) {
                $cookieParams = [
                    '--cookies',
                    $cookiePath
                ];
            }
            $process = $this->processBuilder->build($this->binPath, $this->pythonPath, array_merge($cookieParams, [
                '-f',
                'best',
                '--get-url',
                '--get-title',
                '--get-id',
                '--get-format',
                '--youtube-skip-dash-manifest',
                '--youtube-skip-hls-manifest',
                $videoUrl
            ]));
            for ($i = 0; $i < 5; $i++) {
                $output = $this->getProcessOutput($process);
                $data = array_filter(preg_split('/[\r\n]/', $output));
                if (count($data) < 4 || count($data) > 5) {
                    sleep(1); // Try again in 5 seconds
                } else {
                    break;
                }
            }
            if (count($data) == 4) {
                $data = array_combine(['title', 'id', 'url', 'format'], $data);
            } else {
                $data = array_combine(['warning', 'title', 'id', 'url', 'format'], $data);
            }
            $data = array_combine([
                'title', 'id', 'url', 'format'
            ], array_filter(preg_split('/[\r\n]/', $output)));
            $url = $data['url'];

            $data['format'] = Str::afterLast(Str::beforeLast($data['format'], ' '), ' ');
            list($data['width'], $data['height']) = explode('x', $data['format']);

            if ($parsed = parse_url($data['url'])) {
                $query = Arr::get($parsed, 'query');
                parse_str($query, $query);
                if ($expire = Arr::get($query, 'expire')) {
                    $expire = Carbon::createFromTimestamp($expire);
                    if ($expire && $expire > now()) {
                        Cache::put($videoUrl, $data, $expire);
                    }
                }
            }
        }

        return Cache::get($videoUrl) ?: $data;
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
