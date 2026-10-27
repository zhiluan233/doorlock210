<?php
namespace anim210System;

/** Redis is a disposable cache for immutable archives, never an attendance write queue. */
class OptionalRedisCache {
    private static $attempted = false;
    private static $client = null;

    private static function client()
    {
        if (self::$attempted) { return self::$client; }
        self::$attempted = true;
        $config = $GLOBALS['_config']['redis'] ?? [];
        if (empty($config['enabled']) || !class_exists('Redis')) { return null; }
        try {
            $client = new \Redis();
            $client->connect($config['host'] ?? '127.0.0.1',intval($config['port'] ?? 6379),0.15);
            $client->setOption(\Redis::OPT_READ_TIMEOUT,0.15);
            if (!empty($config['password'])) { $client->auth($config['password']); }
            if (!empty($config['database'])) { $client->select(intval($config['database'])); }
            self::$client = $client;
        } catch (\Throwable $e) { self::$client = null; }
        return self::$client;
    }
    private static function key($token) {
        return 'doorlock:archive:v1:' . hash('sha256',(string)($GLOBALS['_config']['db_name'] ?? 'doorlock')) . ':' . $token;
    }
    public static function get($token) {
        try { $client=self::client(); return $client ? $client->get(self::key($token)) : false; }
        catch (\Throwable $e) { self::$client=null; return false; }
    }
    public static function put($token,$compressed) {
        try { $client=self::client(); if ($client) { $client->setex(self::key($token),86400,$compressed); } }
        catch (\Throwable $e) { self::$client=null; }
    }
}
