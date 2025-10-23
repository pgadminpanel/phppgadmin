<?php
namespace PhpPgAdmin\Database\Export\Compression;

class ZipWriterStream
{
    public $context;
    protected $id;
    protected $stream;

    protected static $instances = [];
    protected static $registered = false;

    public static function registerWrapper(): void
    {
        if (!self::$registered) {
            $wrappers = stream_get_wrappers();
            if (!in_array('phppgadminzip', $wrappers, true)) {
                @stream_wrapper_register('phppgadminzip', __CLASS__);
            }
            self::$registered = true;
        }
    }

    public static function addInstance(string $id, ZipStreamer $inst): void
    {
        self::$instances[$id] = $inst;
    }

    public static function removeInstance(string $id): void
    {
        unset(self::$instances[$id]);
    }

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $parts = parse_url($path);
        $this->id = $parts['host'] ?? ($parts['path'] ?? '');
        if (!isset(self::$instances[$this->id])) {
            return false;
        }
        $this->stream = self::$instances[$this->id];
        return true;
    }

    public function stream_write($data)
    {
        $this->stream->writeData($data);
        return strlen($data);
    }

    public function stream_close()
    {
    }

    public function stream_eof()
    {
        return true;
    }
}
