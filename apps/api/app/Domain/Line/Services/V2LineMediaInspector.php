<?php

namespace App\Domain\Line\Services;

use Symfony\Component\Process\Process;

/** Only task-owned local files enter a decoder. Never accepts an upload URL. */
final class V2LineMediaInspector
{
    public const IMAGE_BYTES = 10 * 1024 * 1024;
    public const VIDEO_BYTES = 200 * 1024 * 1024;
    public const PREVIEW_BYTES = 1024 * 1024;
    public const MAX_PIXELS = 16000000;

    public function image(string $path, int $limit = self::IMAGE_BYTES): array
    {
        $this->local($path, $limit);
        if (! extension_loaded('gd')) $this->reject();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $info = @getimagesize($path);
        if (! in_array($mime, ['image/jpeg', 'image/png'], true) || $info === false
            || ($info['mime'] ?? null) !== $mime || $info[0] < 1 || $info[1] < 1
            || $info[0] > 12000 || $info[1] > 12000 || $info[0] * $info[1] > self::MAX_PIXELS) $this->reject();
        $image = $mime === 'image/jpeg' ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if ($image === false) $this->reject();
        imagedestroy($image);
        return ['mime' => $mime, 'width' => $info[0], 'height' => $info[1], 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }

    public function video(string $path, string $preview): array
    {
        $this->local($path, self::VIDEO_BYTES);
        if (! is_executable('/usr/bin/ffprobe') || ! is_executable('/usr/bin/prlimit')) $this->reject();
        $handle = fopen($path, 'rb');
        try { $header = fread($handle, 32); } finally { fclose($handle); }
        if (strlen($header) < 16 || substr($header, 4, 4) !== 'ftyp'
            || ! in_array(substr($header, 8, 4), ['isom', 'iso2', 'mp41', 'mp42', 'avc1', 'M4V '], true)) $this->reject();
        // argv (no shell), fixed binaries, no network protocols, bounded address space,
        // CPU, wall time, demux allocation/probe size, and output. One video stream only.
        $environment = array_fill_keys(array_keys(getenv()), false);
        $environment['OMP_NUM_THREADS'] = '1';
        $environment['LANG'] = 'C';
        $process = new Process(['/usr/bin/prlimit', '--as=536870912', '--cpu=10', '--nofile=32', '--',
            '/usr/bin/ffprobe', '-v', 'error', '-protocol_whitelist', 'file', '-max_alloc', '67108864',
            '-probesize', '8388608', '-analyzeduration', '5000000', '-threads', '1', '-nofind_stream_info',
            '-show_entries', 'format=format_name,duration:stream=codec_type,codec_name,width,height,sample_aspect_ratio',
            '-of', 'json', '-format_whitelist', 'mov', '-f', 'mov', '-enable_drefs', '0', '-use_absolute_path', '0', '-i', $path], dirname($path), $environment, null, 15);
        $output = ''; $size = 0;
        try {
            $process->run(function (string $type, string $data) use (&$output, &$size, $process): void {
                $size += strlen($data);
                if ($size > 65536) { $process->stop(0); $this->reject(); }
                if ($type === Process::OUT) $output .= $data;
            });
            if (! $process->isSuccessful()) $this->reject();
            $data = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) { $this->reject(); }
        $streams = array_values(array_filter($data['streams'] ?? [], fn ($s) => ($s['codec_type'] ?? '') === 'video'));
        $v = $streams[0] ?? [];
        $width = $v['width'] ?? 0; $height = $v['height'] ?? 0;
        if (count($streams) !== 1 || ! is_int($width) || ! is_int($height) || $width < 1 || $height < 1
            || $width > 12000 || $height > 12000 || $width * $height > self::MAX_PIXELS
            || ! in_array($v['sample_aspect_ratio'] ?? '1:1', ['1:1', 'N/A'], true)
            || ! str_contains($data['format']['format_name'] ?? '', 'mp4')
            || ! is_numeric($data['format']['duration'] ?? null) || (float) $data['format']['duration'] <= 0) $this->reject();
        $p = $this->image($preview, self::PREVIEW_BYTES);
        if ($p['width'] * $height !== $p['height'] * $width) $this->reject();
        return ['mime' => 'video/mp4', 'width' => $width, 'height' => $height, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }

    /** Generates a new JPEG; output never reuses an upload key. */
    public function resize(string $input, string $output, int $width, bool $square = false): array
    {
        $info = $this->image($input);
        if ($width < 1 || $width > 1040 || ($square && $info['width'] !== $info['height'])) $this->reject();
        $height = max(1, (int) round($info['height'] * $width / $info['width']));
        if ($height > 12000 || $height * $width > self::MAX_PIXELS) $this->reject();
        $source = $info['mime'] === 'image/jpeg' ? imagecreatefromjpeg($input) : imagecreatefrompng($input);
        $target = imagecreatetruecolor($width, $height);
        try {
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            if (! imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $info['width'], $info['height'])) $this->reject();
            foreach ([85, 70, 55, 40] as $quality) {
                if (! imagejpeg($target, $output, $quality)) $this->reject();
                clearstatcache(true, $output);
                if (filesize($output) <= self::PREVIEW_BYTES) return $this->image($output, self::PREVIEW_BYTES);
            }
            $this->reject();
        } finally { imagedestroy($source); imagedestroy($target); }
    }

    private function local(string $path, int $limit): void
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) || is_link($path) || ! is_file($path)
            || realpath($path) !== $path || ! is_readable($path) || filesize($path) < 1 || filesize($path) > $limit) $this->reject();
    }

    private function reject(): never { throw new \InvalidArgumentException('LINE_MEDIA_INVALID'); }
}
