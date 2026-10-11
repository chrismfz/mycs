<?php

namespace Nixpal\MyOpenprovider;

/**
 * Key/value store for one module's meta, one key at a time.
 *
 * Not ModuleManager::setMeta(): in Blesta 6.0.3 that deletes ALL of the module's meta and
 * writes back only what it is given, so saving a token would erase every renewal record.
 * The caller passes the three operations (the module uses SQL on module_meta, whose
 * primary key is module_id + key); writes are immediate.
 */
final class MetaStore
{
    /** Longest key module_meta accepts. */
    const MAX_KEY = 32;

    /** @var callable fn(string): ?string */
    private $read;

    /** @var callable fn(string, string): void, insert or update */
    private $write;

    /** @var callable fn(string): void */
    private $remove;

    public function __construct(callable $read, callable $write, callable $remove)
    {
        $this->read = $read;
        $this->write = $write;
        $this->remove = $remove;
    }

    /** @return string|null */
    public function get($key)
    {
        $value = call_user_func($this->read, self::key($key));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set($key, $value)
    {
        call_user_func($this->write, self::key($key), (string) $value);
    }

    public function delete($key)
    {
        call_user_func($this->remove, self::key($key));
    }

    /** @return array|null */
    public function getJson($key)
    {
        $data = json_decode((string) $this->get($key), true);

        return is_array($data) ? $data : null;
    }

    public function setJson($key, array $value)
    {
        $this->set($key, json_encode($value));
    }

    private static function key($key)
    {
        $key = (string) $key;
        if ($key === '' || strlen($key) > self::MAX_KEY) {
            throw new \InvalidArgumentException('Invalid module meta key: ' . $key);
        }

        return $key;
    }
}
