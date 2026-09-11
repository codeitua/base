<?php

declare(strict_types=1);

namespace CodeIT\Cache;

/**
 * The contract CodeIT\Model\CachedTable talks to.
 *
 * Every method is a round trip, which is what this layer costs, so the batched
 * ones exist to let a caller pay once for many keys.
 */
interface CacheInterface
{
    /**
     * @param string $key
     * @return mixed the stored value, or false when the key is absent
     */
    public function get($key);

    /**
     * Reads many keys in one round trip.
     *
     * @param array $keys
     * @return array values positionally aligned with $keys, false where absent
     */
    public function mget($keys);

    /**
     * @param string $key
     * @param mixed $value
     * @param int $ttl seconds, 0 to keep until deleted
     * @param int $try retry counter
     * @return bool
     */
    public function set($key, $value, $ttl = 0, $try = 0);

    /**
     * Writes many keys in as few round trips as the backend allows.
     *
     * @param array $pairs key => value
     * @param int $ttl seconds, 0 to keep until deleted
     * @return bool
     */
    public function setMany($pairs, $ttl = 0);

    /**
     * @param string|array $keys
     * @return int|bool number of keys deleted
     */
    public function deleteCache($keys);

    /**
     * Deletes every key starting with $mask.
     *
     * @param string $mask
     * @return int number of keys deleted
     * @throws \RuntimeException when the backend cannot enumerate keys
     */
    public function deleteByMask($mask);

    /**
     * Takes a short lived lock in a single command, released by expiry.
     *
     * A caller that does not get the lock must not wait for it: a queue of
     * blocked workers costs far more than the duplicated work it would prevent.
     *
     * @param string $key
     * @param int $ttl milliseconds
     * @return bool true when this process took the lock
     */
    public function acquireLock($key, $ttl);
}
