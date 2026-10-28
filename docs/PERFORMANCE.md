# Performance Deployment Checklist

These are server settings, not application code. Apply them on staging and production.

1. **Environment** – set `CI_ENVIRONMENT = production` in the machine's local `.env`. `development` enables the debug toolbar and query collection on every request.
2. **PHP OPcache** – enable in `php.ini` (XAMPP ships it disabled):
   ```
   zend_extension=opcache
   opcache.enable=1
   opcache.memory_consumption=192
   opcache.interned_strings_buffer=16
   opcache.max_accelerated_files=20000
   opcache.validate_timestamps=1
   opcache.revalidate_freq=60
   realpath_cache_size=4096k
   realpath_cache_ttl=600
   ```
3. **Apache modules** – enable `mod_deflate` and `mod_expires` (used by `public/.htaccess` for compression and static-asset caching).
4. **MySQL** – run migration 033 (performance indexes); give InnoDB a buffer pool of at least 256 MB (`innodb_buffer_pool_size`).
5. **Composer** – deploy with `composer install --no-dev --optimize-autoloader`.
