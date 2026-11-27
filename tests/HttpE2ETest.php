<?php
declare(strict_types=1);

/**
 * HTTP End-to-End Integration Tests against live `php -S` server.
 */
function runHttpE2ETests(TestContext $ctx, string $sourceFile, string $tempDir): void
{
    $ctx->beginGate('3. HTTP End-to-End Integration Tests (php -S + curl)');

    $httpRoot = $tempDir . DIRECTORY_SEPARATOR . 'http_root';
    mkdir($httpRoot, 0755, true);
    copy($sourceFile, $httpRoot . DIRECTORY_SEPARATOR . 'filedock.php');

    // Find an available local TCP port
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        $ctx->fail('Allocate ephemeral local port', $errstr);
        return;
    }
    $addr = stream_socket_get_name($sock, false);
    fclose($sock);
    $port = (int) substr((string) $addr, strrpos((string) $addr, ':') + 1);
    $baseUrl = "http://127.0.0.1:{$port}/filedock.php";

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $tempDir . '/php_server.stdout.log', 'w'],
        2 => ['file', $tempDir . '/php_server.stderr.log', 'w'],
    ];
    $cmd = sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($httpRoot));
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        $ctx->fail('Start php -S test server');
        return;
    }
    fclose($pipes[0]);

    try {
        // Wait for server readiness
        $ready = false;
        for ($i = 0; $i < 30; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1);
            if (is_resource($conn)) {
                fclose($conn);
                $ready = true;
                break;
            }
            usleep(100000);
        }
        $ctx->assertTrue($ready, "php -S server listening on 127.0.0.1:{$port}");
        if (!$ready) {
            return;
        }

        $cookieJar = $tempDir . DIRECTORY_SEPARATOR . 'cookies.txt';

        // 1. Unauthenticated GET returns login page with CSRF token
        $loginPage = httpRequest('GET', $baseUrl, null, $cookieJar);
        $ctx->assertSame(200, $loginPage['status'], 'GET unauthenticated returns 200 login page');
        $loginCsrf = '';
        if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/i', $loginPage['body'], $m)) {
            $loginCsrf = $m[1];
        }
        $ctx->assertTrue($loginCsrf !== '', 'Login page embeds CSRF token');

        // 2. Unauthenticated API POST is rejected (401)
        $unauthApi = httpJsonPost($baseUrl, ['action' => 'list', 'csrf_token' => $loginCsrf], $cookieJar);
        $ctx->assertSame(401, $unauthApi['status'], 'Unauthenticated POST API returns 401');

        // 3. Login with default password ("admin")
        $loginResp = httpFormPost($baseUrl, [
            'fm_login_submit' => '1',
            'csrf_token' => $loginCsrf,
            'password' => 'admin',
        ], $cookieJar);
        $ctx->assertSame(302, $loginResp['status'], 'Valid login returns 302 redirect');

        // 4. Fetch authenticated app page & extract BOOTSTRAP JSON
        $appPage = httpRequest('GET', $baseUrl, null, $cookieJar);
        $ctx->assertSame(200, $appPage['status'], 'GET authenticated app page returns 200');
        $bootstrap = null;
        if (preg_match('/const BOOTSTRAP = (\{.*?\});/s', $appPage['body'], $bm)) {
            $bootstrap = json_decode($bm[1], true);
        }
        $ctx->assertTrue(is_array($bootstrap) && !empty($bootstrap['csrfToken']), 'Authenticated page provides BOOTSTRAP.csrfToken');
        $csrf = is_array($bootstrap) ? (string) $bootstrap['csrfToken'] : '';

        // 5. Invalid CSRF token is rejected with 403
        $badCsrf = httpJsonPost($baseUrl, ['action' => 'list', 'csrf_token' => 'invalid_token_000'], $cookieJar);
        $ctx->assertSame(403, $badCsrf['status'], 'Invalid CSRF token rejected with 403');

        // 6. Change default password if required
        if (!empty($bootstrap['mustChangePassword'])) {
            $cp = httpJsonPost($baseUrl, [
                'action' => 'change_password',
                'csrf_token' => $csrf,
                'currentPassword' => 'admin',
                'newPassword' => 'TestPass123!',
                'confirmPassword' => 'TestPass123!',
            ], $cookieJar);
            $ctx->assertTrue($cp['status'] === 200 && !empty($cp['json']['ok']), 'Default password changed via change_password');
            if (!empty($cp['json']['data']['csrfToken'])) {
                $csrf = (string) $cp['json']['data']['csrfToken'];
            }
        }

        // 7. list
        $list = httpJsonPost($baseUrl, ['action' => 'list', 'path' => '', 'showHidden' => false, 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertTrue($list['status'] === 200 && !empty($list['json']['ok']), 'HTTP list directory succeeds');

        // 8. mkdir
        $mkdir = httpJsonPost($baseUrl, ['action' => 'mkdir', 'path' => '', 'name' => 'e2e_dir', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertTrue($mkdir['status'] === 200 && !empty($mkdir['json']['ok']), 'HTTP mkdir succeeds');

        // 9. mkfile
        $mkfile = httpJsonPost($baseUrl, ['action' => 'mkfile', 'path' => 'e2e_dir', 'name' => 'note.txt', 'content' => 'initial', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertTrue($mkfile['status'] === 200 && !empty($mkfile['json']['ok']), 'HTTP mkfile succeeds');

        // 10. read
        $read = httpJsonPost($baseUrl, ['action' => 'read', 'path' => 'e2e_dir/note.txt', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertSame('initial', $read['json']['data']['content'] ?? null, 'HTTP read returns file content');

        // 11. save
        $save = httpJsonPost($baseUrl, ['action' => 'save', 'path' => 'e2e_dir/note.txt', 'content' => 'saved-v2', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertTrue($save['status'] === 200 && !empty($save['json']['ok']), 'HTTP save updates file content');

        // 12. upload
        $uploadSrc = $tempDir . DIRECTORY_SEPARATOR . 'upload_sample.txt';
        file_put_contents($uploadSrc, 'uploaded-payload');
        $upload = httpMultipartPost($baseUrl, [
            'action' => 'upload',
            'path' => 'e2e_dir',
            'overwrite' => '1',
            'csrf_token' => $csrf,
            'files[0]' => new \CURLFile($uploadSrc, 'text/plain', 'uploaded.txt'),
        ], $cookieJar);
        $ctx->assertTrue($upload['status'] === 200 && !empty($upload['json']['ok']), 'HTTP upload succeeds');

        // 13. download
        $dl = httpRequest('GET', $baseUrl . '?action=download&path=' . rawurlencode('e2e_dir/note.txt'), null, $cookieJar);
        $ctx->assertSame(200, $dl['status'], 'HTTP download returns 200');
        $ctx->assertSame('saved-v2', $dl['body'], 'HTTP download streams updated content');
        $ctx->assertContains('nosniff', strtolower($dl['headers']), 'HTTP download sends X-Content-Type-Options: nosniff');

        // 14. preview (image/svg+xml -> 200 with CSP + nosniff; .txt -> 415)
        $svgContent = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';
        httpJsonPost($baseUrl, ['action' => 'mkfile', 'path' => 'e2e_dir', 'name' => 'icon.svg', 'content' => $svgContent, 'csrf_token' => $csrf], $cookieJar);
        $prevSvg = httpRequest('GET', $baseUrl . '?action=preview&path=' . rawurlencode('e2e_dir/icon.svg'), null, $cookieJar);
        $ctx->assertSame(200, $prevSvg['status'], 'HTTP preview of SVG returns 200');
        $ctx->assertSame($svgContent, $prevSvg['body'], 'HTTP preview streams SVG content');
        $ctx->assertContains('content-security-policy:', strtolower($prevSvg['headers']), 'HTTP SVG preview sends Content-Security-Policy');
        $ctx->assertContains('nosniff', strtolower($prevSvg['headers']), 'HTTP SVG preview sends X-Content-Type-Options: nosniff');
        $prevTxt = httpRequest('GET', $baseUrl . '?action=preview&path=' . rawurlencode('e2e_dir/note.txt'), null, $cookieJar);
        $ctx->assertSame(415, $prevTxt['status'], 'HTTP preview rejects non-image .txt with 415');

        // 15. chmod
        $chmod = httpJsonPost($baseUrl, ['action' => 'chmod', 'path' => 'e2e_dir/note.txt', 'mode' => '0640', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertSame('0640', $chmod['json']['data']['permsOctal'] ?? null, 'HTTP chmod sets 0640');

        // 16. copy
        $copy = httpJsonPost($baseUrl, ['action' => 'copy', 'paths' => ['e2e_dir/note.txt'], 'destination' => '', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertTrue($copy['status'] === 200 && !empty($copy['json']['ok']), 'HTTP copy succeeds');

        // 17. move
        httpJsonPost($baseUrl, ['action' => 'mkdir', 'path' => '', 'name' => 'move_box', 'csrf_token' => $csrf], $cookieJar);
        $move = httpJsonPost($baseUrl, ['action' => 'move', 'paths' => ['note.txt'], 'destination' => 'move_box', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertTrue($move['status'] === 200 && !empty($move['json']['ok']), 'HTTP move succeeds');

        // 18. rename
        $rename = httpJsonPost($baseUrl, ['action' => 'rename', 'path' => 'move_box/note.txt', 'newName' => 'renamed.txt', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertTrue($rename['status'] === 200 && !empty($rename['json']['ok']), 'HTTP rename succeeds');

        // 19. zip
        $zip = httpJsonPost($baseUrl, ['action' => 'zip', 'paths' => ['e2e_dir'], 'destination' => '', 'name' => 'e2e.zip', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertTrue($zip['status'] === 200 && !empty($zip['json']['ok']), 'HTTP zip succeeds');

        // 20. unzip
        httpJsonPost($baseUrl, ['action' => 'mkdir', 'path' => '', 'name' => 'unzipped', 'csrf_token' => $csrf], $cookieJar);
        $unzip = httpJsonPost($baseUrl, ['action' => 'unzip', 'path' => 'e2e.zip', 'destination' => 'unzipped', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertTrue($unzip['status'] === 200 && !empty($unzip['json']['ok']), 'HTTP unzip succeeds');

        // 21. server_info
        $info = httpJsonPost($baseUrl, ['action' => 'server_info', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertTrue($info['status'] === 200 && !empty($info['json']['data']['phpVersion']), 'HTTP server_info returns PHP diagnostics');

        // 22. Traversal rejection via HTTP
        $travPost = httpJsonPost($baseUrl, ['action' => 'read', 'path' => '../../etc/passwd', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertTrue($travPost['status'] === 400 && empty($travPost['json']['ok']), 'HTTP POST traversal rejected with 400');

        $travGet = httpRequest('GET', $baseUrl . '?action=download&path=' . rawurlencode('../../etc/passwd'), null, $cookieJar);
        $ctx->assertSame(403, $travGet['status'], 'HTTP GET download traversal rejected with 403');

        // 23. logout
        $logout = httpJsonPost($baseUrl, ['action' => 'logout', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertTrue($logout['status'] === 200 && !empty($logout['json']['data']['loggedOut']), 'HTTP logout succeeds');

        $postLogout = httpJsonPost($baseUrl, ['action' => 'list', 'csrf_token' => $csrf], $cookieJar);
        $ctx->assertSame(401, $postLogout['status'], 'Requests after logout are rejected with 401');
    } finally {
        @proc_terminate($proc);
        @proc_close($proc);
    }
}

/**
 * @return array{status:int, headers:string, body:string}
 */
function httpRequest(string $method, string $url, $body, string $cookieJar, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if (!is_string($raw)) {
        return ['status' => 0, 'headers' => '', 'body' => ''];
    }

    return [
        'status' => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
    ];
}

/**
 * @return array{status:int, headers:string, body:string, json:array<string,mixed>|null}
 */
function httpJsonPost(string $url, array $payload, string $cookieJar): array
{
    $resp = httpRequest(
        'POST',
        $url,
        json_encode($payload),
        $cookieJar,
        ['Content-Type: application/json']
    );
    $decoded = json_decode($resp['body'], true);
    $resp['json'] = is_array($decoded) ? $decoded : null;
    return $resp;
}

/**
 * @return array{status:int, headers:string, body:string}
 */
function httpMultipartPost(string $url, array $fields, string $cookieJar): array
{
    $resp = httpRequest('POST', $url, $fields, $cookieJar);
    $decoded = json_decode($resp['body'], true);
    $resp['json'] = is_array($decoded) ? $decoded : null;
    return $resp;
}

/**
 * @return array{status:int, headers:string, body:string}
 */
function httpFormPost(string $url, array $fields, string $cookieJar): array
{
    return httpRequest('POST', $url, http_build_query($fields), $cookieJar);
}
