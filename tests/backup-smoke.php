<?php
/**
 * Read-only smoke test for the administrator backup payload.
 *
 * Run from the command line in a local WordPress environment.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$wp_load = dirname(__DIR__, 4) . '/wp-load.php';
if (!file_exists($wp_load)) {
    fwrite(STDERR, "wp-load.php was not found.\n");
    exit(1);
}

require $wp_load;

if (!class_exists('AI_Sidrene_Cijene')) {
    fwrite(STDERR, "Plugin class is not loaded.\n");
    exit(1);
}

$method = new ReflectionMethod('AI_Sidrene_Cijene', 'podaci_sigurnosne_kopije');
$method->setAccessible(true);
$payload = $method->invoke(null);
$required = array('format', 'format_version', 'plugin_version', 'exported_at', 'site_url', 'options', 'archive_page', 'items');

foreach ($required as $key) {
    if (!array_key_exists($key, $payload)) {
        fwrite(STDERR, "Missing backup key: {$key}\n");
        exit(1);
    }
}

$json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "Backup payload cannot be encoded as JSON.\n");
    exit(1);
}

printf(
    "Backup payload OK: version=%s, options=%d, items=%d, bytes=%d\n",
    $payload['plugin_version'],
    count($payload['options']),
    count($payload['items']),
    strlen($json)
);
