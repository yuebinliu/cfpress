/**
 * KV缓存管理
 */

export async function getCache(kv, key) {
    try {
        const value = await kv.get(key);
        if (value) return JSON.parse(value);
        return null;
    } catch (error) {
        console.error('Cache get error:', error);
        return null;
    }
}

export async function setCache(kv, key, value, ttl = 300) {
    try {
        await kv.put(key, JSON.stringify(value), { expirationTtl: ttl });
        return true;
    } catch (error) {
        console.error('Cache set error:', error);
        return false;
    }
}

export async function deleteCache(kv, key) {
    try {
        await kv.delete(key);
        return true;
    } catch (error) {
        console.error('Cache delete error:', error);
        return false;
    }
}

export async function purgeCache(kv, pattern) {
    try {
        const list = await kv.list();
        for (const key of list.keys) {
            if (key.name.includes(pattern)) await kv.delete(key.name);
        }
        return true;
    } catch (error) {
        console.error('Cache purge error:', error);
        return false;
    }
}