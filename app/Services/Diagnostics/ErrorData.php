<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use App\Models\ReleaseJob;
use Illuminate\Log\Events\MessageLogged;
use Throwable;

final class ErrorData
{
    public const CATEGORIES = ['system', 'upload rilis', 'cek status', 'akun', 'browser', 'instalasi', 'lainnya'];

    public function initialize(): void
    {
        if (! config('diagnostics.enabled')) return;
        $directory = config('diagnostics.directory');
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) return;
        foreach (self::CATEGORIES as $category) {
            $file = $directory.'/error '.$category.'.log';
            if (! is_file($file)) @touch($file);
        }
        $readme = resource_path('diagnostics/BACA SAYA.txt');
        if (is_file($readme) && ! is_file($directory.'/BACA SAYA.txt')) @copy($readme, $directory.'/BACA SAYA.txt');
    }

    public function write(string $category, string $message, array $context = [], string $level = 'error'): void
    {
        if (! config('diagnostics.enabled')) return;
        $lock = null;
        try {
            $this->initialize();
            $category = in_array($category, self::CATEGORIES, true) ? $category : 'lainnya';
            $file = config('diagnostics.directory').'/error '.$category.'.log';
            $lock = @fopen($file.'.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX)) return;
            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) >= (int) config('diagnostics.max_bytes')) {
                for ($index = (int) config('diagnostics.backups'); $index >= 1; $index--) {
                    $destination = $file.'.'.$index;
                    $source = $index === 1 ? $file : $file.'.'.($index - 1);
                    if (is_file($destination)) @unlink($destination);
                    if (is_file($source)) @rename($source, $destination);
                }
            }
            $record = [
                'time' => now()->toIso8601String(), 'version' => config('automation.bundled_app_version'),
                'level' => $level, 'category' => $category, 'message' => $this->scrub($message),
                'context' => $this->scrub($context),
            ];
            @file_put_contents($file, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL, FILE_APPEND);
        } catch (Throwable) {
            // Diagnostics must never interrupt the operation being diagnosed.
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    public function message(MessageLogged $event): void
    {
        if (! in_array($event->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) return;
        $message = is_array($event->message) ? json_encode($this->scrub($event->message), JSON_INVALID_UTF8_SUBSTITUTE) : (string) $event->message;
        $exception = $event->context['exception'] ?? null;
        $context = [];
        $trace = [];
        if ($exception instanceof Throwable) {
            $context['exception'] = get_class($exception);
            $context['file'] = $exception->getFile().':'.$exception->getLine();
            foreach (array_slice($exception->getTrace(), 0, 30) as $frame) {
                $trace[] = ($frame['file'] ?? $frame['class'] ?? '').':'.($frame['line'] ?? '').' '.($frame['function'] ?? '');
            }
            $context['trace'] = $trace; // Never include function arguments or request/session payloads.
        }
        foreach (['platform', 'job_id', 'release_id', 'error_code'] as $key) {
            if (isset($event->context[$key])) $context[$key] = $event->context[$key];
        }
        $source = implode(' ', $trace).' '.$message;
        $category = match (true) {
            (bool) preg_match('/SessionManager|SessionHealth|AUTH_|login|authentication/i', $source) => 'akun',
            (bool) preg_match('/CheckSoundOn|QueueSoundOn|VerifySoundfresh|RejectSoundfresh|ReleaseStatus/i', $source) => 'cek status',
            (bool) preg_match('/ProcessRelease|CheckPending|ReviewManually|StartAutomation|AssetDownload/i', $source) => 'upload rilis',
            default => 'system',
        };
        $this->write($category, (string) $message, $context, $event->level);
    }

    public function release(ReleaseJob $job, bool $created = false): void
    {
        foreach (['error_message' => 'upload rilis', 'soundon_check_error' => 'cek status', 'soundfresh_verify_error' => 'cek status'] as $field => $category) {
            if ((! $created && ! $job->wasChanged($field)) || ! filled($job->$field)) continue;
            $this->write($category, (string) $job->$field, [
                'source' => $field, 'job_id' => $job->id, 'run_id' => $job->automation_run_id,
                'release_id' => $job->soundfresh_release_id, 'title' => $job->release_title,
                'artist' => $job->artist_name, 'error_code' => $job->error_code,
                'checkpoint' => $job->checkpoint?->value, 'soundon_status' => $job->soundon_release_status,
                'soundfresh_status' => $job->soundfresh_workflow_status,
            ]);
        }
    }

    private function scrub(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = preg_match('/password|passwd|secret|token|cookie|authorization|session|app_key|hmac/i', (string) $key)
                    ? '[REDACTED]' : $this->scrub($item);
            }
            return $value;
        }
        if (! is_string($value)) return is_scalar($value) || $value === null ? $value : '[omitted]';
        $value = preg_replace('/(https?:\/\/[^\s?]+)\?[^\s]+/i', '$1?[REDACTED]', $value);
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [REDACTED]', $value);
        $value = preg_replace('/(["\']?(?:password|passwd|secret|[\w-]*token|cookie|authorization|session|app_key|hmac[\w-]*)["\']?\s*[:=]\s*)("[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s,;]+)/i', '$1[REDACTED]', $value);
        return mb_substr($value, 0, 24000);
    }
}
