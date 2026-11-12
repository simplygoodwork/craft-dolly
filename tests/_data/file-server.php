<?php

/**
 * Router for the local file server that tests fetch assets from (see `tests/_support/FileServer.php`). It serves the
 * files in `tests/_data/files`, so tests never fetch from the internet.
 *
 * - `/files/…/<name>`: the fixture `<name>`, or a 404 if there isn't one
 * - `/private/…`: 401, as a source behind basic auth would
 * - `/slow/…/<name>`: the fixture `<name>`, after 3 seconds
 * - anything else: 404
 */

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$section = explode('/', ltrim($path, '/'))[0];

if ($section === 'private') {
    header('WWW-Authenticate: Basic realm="Staging"');
    http_response_code(401);
    echo 'Unauthorized';
    return true;
}

if ($section === 'slow') {
    sleep(3);
}

$file = __DIR__ . '/files/' . basename(rawurldecode($path));
if (!in_array($section, ['files', 'slow'], true) || !is_file($file)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

header('Content-Type: ' . (mime_content_type($file) ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
readfile($file);

return true;
