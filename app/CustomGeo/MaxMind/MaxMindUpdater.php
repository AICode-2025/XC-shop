<?php

namespace App\CustomGeo\MaxMind;

use App\CustomGeo\Support\GeoPaths;
use GeoIp2\Database\Reader;
use RuntimeException;
use Throwable;

/**
 * GeoLite2 官方 MMDB 更新器（下载 → 校验 → 备份 → 原子替换 → 失败回滚）。
 *
 * 数据源：https://download.maxmind.com/app/geoip_download（需免费注册 MaxMind 账号获取 license key）
 * 许可：GeoLite2 采用 CC BY-SA 4.0，站点需保留 MaxMind 署名。
 *
 * 说明：项目已内置 geoip2/geoip2（含 maxmind-db/reader），这里不引入 torann/geoip 等额外依赖。
 */
class MaxMindUpdater
{
    public function __construct(private readonly ?string $licenseKey = null) {}

    public function licenseKey(): string
    {
        return (string) ($this->licenseKey ?? config('customgeo.maxmind.license_key', ''));
    }

    /**
     * 更新指定版本（city / country）。
     *
     * @param  array{dry_run?: bool, force?: bool, format?: string}  $options
     * @return array<string, mixed>
     */
    public function update(string $edition, array $options = [], ?callable $log = null): array
    {
        $edition = GeoPaths::normalizeEdition($edition);
        $key     = $this->licenseKey();

        if ($key === '') {
            throw new RuntimeException('缺少 MAXMIND_LICENSE_KEY，请先在 .env 配置（https://www.maxmind.com/en/geolite2/signup）');
        }

        $started = microtime(true);
        $target  = GeoPaths::mmdb($edition);
        $tmpDir  = GeoPaths::ensureDir(GeoPaths::maxmindDir().'/tmp');
        $dryRun  = (bool) ($options['dry_run'] ?? false);

        $this->write($log, "开始更新 {$edition}：目标 {$target}");

        // 逐格式尝试：tar.gz 优先，解压/下载失败自动换 zip
        $mmdbPath = null;
        $errors   = [];

        foreach ($this->formats($options) as $suffix) {
            try {
                $archive  = $this->download($edition, $key, $suffix, $tmpDir, $log);
                $mmdbPath = $this->extract($archive, $tmpDir, $log);
                break;
            } catch (Throwable $e) {
                $errors[] = "{$suffix}: ".$e->getMessage();
                $this->write($log, "{$suffix} 获取失败，尝试其它格式：".$e->getMessage());
            }
        }

        if ($mmdbPath === null) {
            throw new RuntimeException('GeoLite2 获取失败：'.implode('；', $errors));
        }

        $meta = $this->verify($mmdbPath, $edition, $log);
        $size = (int) filesize($mmdbPath);

        $result = [
            'edition'       => $edition,
            'path'          => $target,
            'database_type' => $meta['database_type'],
            'build_epoch'   => $meta['build_epoch'],
            'size'          => $size,
            'backup'        => null,
            'dry_run'       => $dryRun,
            'skipped'       => false,
            'duration_sec'  => 0,
        ];

        // 本地已是最新时不替换（--force 可跳过判断）
        if (! $dryRun && ! (bool) ($options['force'] ?? false)) {
            $current = $this->info($edition);
            if ($current['exists'] && (int) ($current['build_epoch'] ?? 0) >= $meta['build_epoch']) {
                @unlink($mmdbPath);
                $result['skipped']      = true;
                $result['duration_sec'] = round(microtime(true) - $started, 1);
                $this->write($log, '本地数据库已是最新版本，跳过替换（可加 --force 强制更新）');

                return $result;
            }
        }

        if ($dryRun) {
            @unlink($mmdbPath);
            $this->write($log, "DRY-RUN：校验通过（{$meta['database_type']}），未替换现有数据库");

            $result['duration_sec'] = round(microtime(true) - $started, 1);

            return $result;
        }

        $result['backup'] = $this->replace($target, $mmdbPath, $edition, $log);
        $this->pruneBackups($edition, $log);

        $result['duration_sec'] = round(microtime(true) - $started, 1);
        $this->write($log, "更新完成：{$edition}（{$meta['database_type']}，".$this->human($size).'）');

        return $result;
    }

    /**
     * 读取本地 MMDB 元数据。
     *
     * @return array<string, mixed>
     */
    public function info(string $edition = 'city'): array
    {
        $path = GeoPaths::mmdb($edition);

        clearstatcache(true, $path);
        if (! is_file($path)) {
            return ['exists' => false, 'path' => $path, 'size' => 0, 'database_type' => '', 'build_epoch' => 0];
        }

        $info = [
            'exists'        => true,
            'path'          => $path,
            'size'          => (int) filesize($path),
            'modified'      => date('Y-m-d H:i:s', (int) filemtime($path)),
            'database_type' => '',
            'build_epoch'   => 0,
        ];

        try {
            $reader = new Reader($path);
            $meta   = $reader->metadata();
            $info['database_type'] = (string) $meta->databaseType;
            $info['build_epoch']   = (int) $meta->buildEpoch;
            $info['build_date']    = $meta->buildEpoch ? date('Y-m-d', (int) $meta->buildEpoch) : '';
        } catch (Throwable $e) {
            $info['error'] = $e->getMessage();
        }

        return $info;
    }

    private function download(string $edition, string $licenseKey, string $suffix, string $tmpDir, ?callable $log): string
    {
        $archive = $tmpDir.'/'.GeoPaths::editionId($edition).'.'.$suffix;
        @unlink($archive);

        $this->downloadArchive($edition, $licenseKey, $suffix, $archive, $log);

        if (! is_file($archive) || filesize($archive) <= 1024) {
            throw new RuntimeException('下载内容异常（体积过小）');
        }

        return $archive;
    }

    /**
     * @return string[]
     */
    private function formats(array $options): array
    {
        $format = strtolower((string) ($options['format'] ?? 'tar.gz'));

        return $format === 'zip' ? ['zip', 'tar.gz'] : ['tar.gz', 'zip'];
    }

    private function downloadArchive(string $edition, string $licenseKey, string $suffix, string $archive, ?callable $log): void
    {
        $url = (string) config('customgeo.maxmind.download_url', 'https://download.maxmind.com/app/geoip_download')
            .'?'.http_build_query([
                'edition_id'  => GeoPaths::editionId($edition),
                'license_key' => $licenseKey,
                'suffix'      => $suffix,
            ]);

        $this->write($log, '下载：'.GeoPaths::editionId($edition)."（{$suffix}）");

        $handle = fopen($archive, 'wb');
        if ($handle === false) {
            throw new RuntimeException("无法写入临时文件：{$archive}");
        }

        $lastTick = 0;
        $curl     = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE           => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => (int) config('customgeo.maxmind.timeout', 900),
            CURLOPT_USERAGENT      => 'InnoShop-CustomGeo/1.0',
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadSize, $downloaded) use (&$lastTick, $log) {
                if ($downloadSize <= 0 || $downloaded <= 0) {
                    return;
                }
                $now = time();
                if ($now - $lastTick < 5) {
                    return;
                }
                $lastTick = $now;
                $this->write($log, '  '.number_format(min(100, ($downloaded / $downloadSize) * 100), 1).'%（'.$this->human((int) $downloaded).'）');
            },
        ]);

        $ok   = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($ok === false || $code >= 400 || $code === 0) {
            @unlink($archive);

            throw new RuntimeException("HTTP {$code} {$err}".($code === 401 ? '（license key 无效或未授权）' : ''));
        }
    }

    private function extract(string $archive, string $tmpDir, ?callable $log): string
    {
        $extractDir = GeoPaths::ensureDir($tmpDir.'/extract-'.pathinfo($archive, PATHINFO_EXTENSION).'-'.uniqid());
        $this->write($log, '解压：'.basename($archive));

        try {
            if (str_ends_with($archive, '.zip')) {
                $this->unzip($archive, $extractDir);
            } else {
                $this->untarGz($archive, $extractDir);
            }
        } catch (Throwable $e) {
            $this->removeTree($extractDir);
            @unlink($archive);

            throw new RuntimeException('解压失败：'.$e->getMessage());
        }

        $mmdb = $this->findMmdb($extractDir);
        if ($mmdb === null) {
            throw new RuntimeException('压缩包内未找到 .mmdb 文件');
        }

        $final = $tmpDir.'/'.basename($mmdb).'.new';
        if (! copy($mmdb, $final)) {
            throw new RuntimeException("无法写入临时数据库：{$final}");
        }

        $this->removeTree($extractDir);
        @unlink($archive);

        return $final;
    }

    private function untarGz(string $archive, string $dir): void
    {
        if (! class_exists(\PharData::class)) {
            throw new RuntimeException('缺少 phar 扩展，无法解压 tar.gz（可改用 --format=zip）');
        }

        (new \PharData($archive))->extractTo($dir, null, true);
    }

    private function unzip(string $archive, string $dir): void
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive;
            if ($zip->open($archive) !== true) {
                throw new RuntimeException('ZIP 打开失败');
            }
            $zip->extractTo($dir);
            $zip->close();

            return;
        }

        if (class_exists(\PhpZip\ZipFile::class)) {
            (new \PhpZip\ZipFile)->openFile($archive)->extractTo($dir);

            return;
        }

        throw new RuntimeException('缺少 zip 解压能力，可改用 --format=tar.gz');
    }

    private function findMmdb(string $dir): ?string
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.mmdb')) {
                return $file->getPathname();
            }
        }

        return null;
    }

    /**
     * @return array{database_type: string, build_epoch: int}
     */
    private function verify(string $path, string $edition, ?callable $log): array
    {
        $minBytes = (int) config('customgeo.maxmind.min_bytes', 1048576);
        $size     = (int) filesize($path);

        if ($size < $minBytes) {
            @unlink($path);

            throw new RuntimeException("数据库体积异常（{$size} 字节），已丢弃");
        }

        try {
            $reader   = new Reader($path);
            $metadata = $reader->metadata();
            $type     = (string) $metadata->databaseType;
            $epoch    = (int) $metadata->buildEpoch;
        } catch (Throwable $e) {
            @unlink($path);

            throw new RuntimeException('MMDB 校验失败：'.$e->getMessage());
        }

        $expected = GeoPaths::editionId($edition);
        if ($expected !== '' && ! str_contains($type, str_replace('GeoLite2-', '', $expected))) {
            @unlink($path);

            throw new RuntimeException("数据库类型不匹配：期望 {$expected}，实际 {$type}");
        }

        if ($epoch <= 0) {
            @unlink($path);

            throw new RuntimeException('MMDB 缺少 build epoch，判定为无效文件');
        }

        $this->write($log, "校验通过：{$type}，构建日期 ".date('Y-m-d', $epoch).'，大小 '.$this->human($size));

        return ['database_type' => $type, 'build_epoch' => $epoch];
    }

    private function replace(string $target, string $newFile, string $edition, ?callable $log): ?string
    {
        GeoPaths::ensureDir(dirname($target));

        $backup = null;
        if (is_file($target)) {
            $backup = GeoPaths::backupDir().'/'.$edition.'-'.date('YmdHis').'.mmdb';
            if (! @copy($target, $backup)) {
                @unlink($newFile);

                throw new RuntimeException("备份现有数据库失败：{$backup}");
            }
            $this->write($log, '已备份旧数据库：'.GeoPaths::relative($backup));
        }

        try {
            // POSIX 下 rename 可直接覆盖；Windows 需先删除旧文件
            if (! @rename($newFile, $target)) {
                if (is_file($target) && ! @unlink($target)) {
                    throw new RuntimeException("无法删除旧数据库：{$target}");
                }
                if (! @rename($newFile, $target)) {
                    throw new RuntimeException("替换失败：{$newFile} => {$target}");
                }
            }
        } catch (Throwable $e) {
            // 回滚：恢复备份
            if ($backup && is_file($backup)) {
                @copy($backup, $target);
                $this->write($log, '已回滚到备份数据库');
            }
            @unlink($newFile);

            throw new RuntimeException('替换数据库失败（已回滚）：'.$e->getMessage());
        }

        $this->write($log, '数据库已启用：'.$target);

        return $backup;
    }

    private function pruneBackups(string $edition, ?callable $log): void
    {
        $keep  = max(1, (int) config('customgeo.maxmind.backup_keep', 3));
        $files = glob(GeoPaths::backupDir().'/'.$edition.'-*.mmdb') ?: [];
        rsort($files);

        foreach (array_slice($files, $keep) as $file) {
            @unlink($file);
            $this->write($log, '清理旧备份：'.basename($file));
        }
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow   = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $pow), 2).' '.$units[$pow];
    }

    private function write(?callable $log, string $message): void
    {
        if ($log) {
            $log($message);
        }
    }
}
