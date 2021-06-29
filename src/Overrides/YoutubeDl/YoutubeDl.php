<?php

namespace Waska\LaravelYoutubeConverter\Overrides\YoutubeDl;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Symfony\Component\Filesystem\Filesystem;
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
        $this->progress = static function (string $progressTarget, string $percentage, string $size, ?string $speed, ?string $eta, ?string $totalTime): void {};
        $this->debug = static function (string $type, string $buffer): void {};
        parent::__construct($processBuilder, $metadataReader, $filesystem);
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

    public function getQuietUrls(string $url)
    {
        $process = $this->processBuilder->build($this->binPath, $this->pythonPath, ['--youtube-skip-dash-manifest', '-g', $url]);
        $output = $this->getProcessOutput($process);
        return array_values(array_filter(explode("\n", $output)));
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
        $process->run(function ($type, $value) use (&$output) {
            if ($type == 'err') {
                dd($value);
            }
            $output = $value;
        });
        return $output;
    }
}
