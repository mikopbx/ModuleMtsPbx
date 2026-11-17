<?php

namespace Modules\ModuleMtsPbx\Lib;

/**
 * Межпроцессная блокировка на flock(): атомарна и снимается ядром ОС при завершении процесса,
 * в том числе аварийном, поэтому «зависших» pid-файлов не бывает.
 */
final class ProcessLock
{
    private string $file;
    /** @var resource|null */
    private $handle = null;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    public function acquire(): bool
    {
        if ($this->handle !== null) {
            return true;
        }
        $handle = @fopen($this->file, 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid());
        fflush($handle);
        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    /**
     * Занята ли блокировка другим процессом (без её захвата).
     */
    public static function isLocked(string $file): bool
    {
        $lock = new self($file);
        if ($lock->acquire()) {
            $lock->release();
            return false;
        }
        return file_exists($file);
    }
}
