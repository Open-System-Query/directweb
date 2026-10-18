<?php
declare(strict_types=1);
namespace DirectWeb\Core;
final class Files
{
    public static function id(): string { return bin2hex(random_bytes(16)); }
    public static function path(string $path): string
    {
        if ($path === '' || strlen($path) > 512 || preg_match('~[\\\\:\x00-\x1f\x7f<>"|?*]~', $path) || str_starts_with($path, '/')) { throw new Problem('Invalid path.'); }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.') || rtrim($part, '. ') !== $part || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part)) { throw new Problem('Invalid path.'); }
        }
        return $path;
    }
    public static function mkdir(string $path): void
    {
        if (is_link($path)) { throw new Problem('Invalid storage.'); }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) { throw new Problem('Storage unavailable.', 503); }
    }
    public static function write(string $path, string $data): void
    {
        self::mkdir(dirname($path));
        $tmp = $path . '.' . self::id() . '.tmp';
        if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) { @unlink($tmp); throw new Problem('Save failed.', 503); }
        if (!rename($tmp, $path)) { @unlink($tmp); throw new Problem('Save failed.', 503); }
    }
    public static function remove(string $path): void
    {
        if (is_link($path)) { unlink($path); return; }
        if (is_dir($path)) { foreach (new \FilesystemIterator($path) as $file) { self::remove($file->getPathname()); } rmdir($path); }
        elseif (is_file($path)) { unlink($path); }
    }
    public static function copy(string $from, string $to): void
    {
        if (is_link($from)) { throw new Problem('Invalid storage.'); }
        self::mkdir($to);
        foreach (new \FilesystemIterator($from) as $file) {
            if ($file->isLink()) { throw new Problem('Invalid storage.'); }
            $target = $to . '/' . $file->getFilename();
            if ($file->isDir()) { self::copy($file->getPathname(), $target); }
            elseif (!copy($file->getPathname(), $target)) { throw new Problem('Copy failed.', 503); }
        }
    }
    public static function host(string $host): string
    {
        $host = strtolower(trim($host));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::[0-9]{1,5})?$/D', $host) || str_contains($host, '..')) { throw new Problem('Invalid domain.'); }
        return $host;
    }
    public static function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
}
