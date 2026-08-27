<?php
// =========================================================================
// SECURITY CONFIGURATION
// =========================================================================
define('AUTH_PASSCODE', '123456'); // 🔑 CHANGE ACCESS PASSCODE HERE
define('ENABLE_SHELL_TERMINAL', true); // ⚠️ Set 'true' ONLY if you want to enable RCE Terminal

// Secure session cookie configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

// Tự động chuyển hướng từ 0.0.0.0 sang localhost để đảm bảo Secure Context cho PWA
if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], '0.0.0.0') !== false) {
    $redirectUrl = 'http://localhost' . (isset($_SERVER['SERVER_PORT']) ? ':' . $_SERVER['SERVER_PORT'] : '') . $_SERVER['REQUEST_URI'];
    header("Location: $redirectUrl", true, 301);
    exit;
}

session_start();

// 1. AUTHENTICATION CHECK
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    header('Content-Type: application/json');
    $pass = $_POST['passcode'] ?? '';
    if (hash_equals(AUTH_PASSCODE, $pass)) {
        $_SESSION['authenticated'] = true;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        echo json_encode(['status' => 'success', 'message' => 'Login successful!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Incorrect passcode!']);
    }
    exit;
}

// Block all access if not authenticated
$isAuthenticated = !empty($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// 2. BLOCK AJAX REQUEST IF UNAUTHENTICATED OR INVALID CSRF TOKEN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    if (!$isAuthenticated) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'SECURITY ERROR: Unauthorized!']);
        exit;
    }

    // CSRF Protection Check
    $clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $clientCsrf)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'SECURITY ERROR: Invalid CSRF Token!']);
        exit;
    }
}

// Path & Environment
$binDir = __DIR__ . '/bin';
$logFile = $binDir . '/mpv.log';
$cookieFile = __DIR__ . '/cookies.txt';
$socketFile = '/tmp/mpv-socket';

if (!file_exists($binDir)) {
    mkdir($binDir, 0755, true);
}
putenv("PATH=$binDir:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin");

// Helper Functions
function queryMpvStatus() {
    global $socketFile;
    $result = [
        'time_pos' => 0,
        'duration' => 0,
        'paused' => false,
        'volume' => 100,
        'title' => 'Loading...',
        'playlist' => []
    ];
    if (!file_exists($socketFile)) return $result;

    $socket = @stream_socket_client("unix://$socketFile", $errno, $errstr, 0.2);
    if (!$socket) return $result;

    stream_set_timeout($socket, 0, 100000); // 100ms max timeout per read
    $cmds = [
        json_encode(['command' => ['get_property', 'time-pos']]),
        json_encode(['command' => ['get_property', 'duration']]),
        json_encode(['command' => ['get_property', 'pause']]),
        json_encode(['command' => ['get_property', 'volume']]),
        json_encode(['command' => ['get_property', 'media-title']]),
        json_encode(['command' => ['get_property', 'playlist']])
    ];

    @fwrite($socket, implode("\n", $cmds) . "\n");
    $responses = [];
    for ($i = 0; $i < 6; $i++) {
        $line = @fgets($socket);
        if ($line !== false) $responses[] = json_decode(trim($line), true);
    }
    @fclose($socket);

    if (isset($responses[0]['data'])) $result['time_pos'] = (float)$responses[0]['data'];
    if (isset($responses[1]['data'])) $result['duration'] = (float)$responses[1]['data'];
    if (isset($responses[2]['data'])) $result['paused'] = (bool)$responses[2]['data'];
    if (isset($responses[3]['data'])) $result['volume'] = (int)$responses[3]['data'];
    if (isset($responses[4]['data'])) $result['title'] = (string)$responses[4]['data'];
    if (isset($responses[5]['data']) && is_array($responses[5]['data'])) $result['playlist'] = $responses[5]['data'];

    return $result;
}

// STRICT URL & DOMAIN VALIDATOR (Chống SSRF và Arbitrary Protocol Injection)
function isValidYouTubeUrl($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parsedUrl = parse_url($url);
    if (!$parsedUrl || empty($parsedUrl['scheme']) || empty($parsedUrl['host'])) {
        return false;
    }

    // 1. Chỉ chấp nhận HTTP/HTTPS scheme
    $scheme = strtolower($parsedUrl['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    // 2. Tách Hostname và kiểm tra Whitelist chuẩn xác
    $host = strtolower($parsedUrl['host']);
    $allowedHosts = [
        'youtube.com',
        'www.youtube.com',
        'music.youtube.com',
        'm.youtube.com',
        'youtu.be'
    ];

    if (in_array($host, $allowedHosts, true)) {
        return true;
    }

    // Cho phép các subdomain hợp lệ của youtube.com (ví dụ: www.music.youtube.com nếu có)
    if (substr($host, -12) === '.youtube.com') {
        return true;
    }

    return false;
}

function sendSingleIpc($command) {
    global $socketFile;
    if (!file_exists($socketFile)) return false;
    $socket = @stream_socket_client("unix://$socketFile", $errno, $errstr, 0.2);
    if (!$socket) return false;

    stream_set_timeout($socket, 0, 100000);
    $written = @fwrite($socket, json_encode(['command' => $command]) . "\n");
    if ($written === false) {
        @fclose($socket);
        return false;
    }
    $res = @fgets($socket);
    @fclose($socket);
    if ($res === false) return false;
    $decoded = json_decode($res, true);
    return (isset($decoded['error']) && $decoded['error'] === 'success') ? $decoded : false;
}

function startMpvProcess($url, $binDir, $socketFile, $cookieFile, $logFile) {
    exec("killall -9 mpv > /dev/null 2>&1 || pkill -9 mpv > /dev/null 2>&1");
    if (file_exists($socketFile)) @unlink($socketFile);

    file_put_contents($logFile, "=== MPV SESSION LAUNCHED: " . date('Y-m-d H:i:s') . " ===\nURL: $url\n\n");

    // DYNAMIC AUDIO ENVIRONMENT DETECTION (Tự tương thích giữa DroidSpaces-OSS và openSUSE PC)
    $envPrefix = "";
    if (file_exists('/tmp/.pulse-socket')) {
        $envPrefix = "env PULSE_SERVER='unix:/tmp/.pulse-socket' XDG_RUNTIME_DIR='/run/user/0' ";
    }

    $mpvCmd = $envPrefix . "mpv --no-video "
            . "--ao=pulse,alsa,jack,openal,auto "
            . "--force-seekable=yes "
            . "--cache=yes "
            . "--demuxer-max-bytes=100M "
            . "--demuxer-readahead-secs=300 "
            . "--stream-lavf-o=reconnect=1,reconnect_streamed=1,reconnect_delay_max=5 "
            . "--input-ipc-server=" . escapeshellarg($socketFile);

    if (file_exists($binDir . '/yt-dlp')) {
        $mpvCmd .= " --script-opts=ytdl_hook-ytdl_path=" . escapeshellarg($binDir . "/yt-dlp");
    }

    $rawOpts = ['keep-fragments='];
    if (file_exists($cookieFile) && filesize($cookieFile) > 0) {
        $rawOpts[] = "cookies=" . $cookieFile;
    }

    $mpvCmd .= " --ytdl-raw-options=" . escapeshellarg(implode(',', $rawOpts));

    exec("nohup setsid $mpvCmd " . escapeshellarg($url) . " > " . escapeshellarg($logFile) . " 2>&1 &");
}

// 3. API ENDPOINTS (Protected via Auth & CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $action = $_POST['action'] ?? '';
    $ephemeralSudoPass = $_POST['sudo_pass'] ?? '';

    $response = ['status' => 'error', 'message' => 'Invalid action!'];

    if ($action === 'play' && !empty($_POST['url'])) {
        $raw_url = trim($_POST['url']);

        if (isValidYouTubeUrl($raw_url)) {
            startMpvProcess($raw_url, $binDir, $socketFile, $cookieFile, $logFile);
            $response = ['status' => 'success', 'message' => 'Playback started!'];
        } else {
            $response = ['status' => 'error', 'message' => 'SECURITY REJECTED: Invalid or Untrusted YouTube URL!'];
        }
    }

    elseif ($action === 'enqueue' && !empty($_POST['url'])) {
        $raw_url = trim($_POST['url']);

        if (isValidYouTubeUrl($raw_url)) {
            $runningMpv = trim(shell_exec("pgrep -a mpv 2>/dev/null || ps aux | grep [m]pv"));
            $ipcSuccess = false;

            if ($runningMpv && file_exists($socketFile)) {
                $ipcRes = sendSingleIpc(['loadfile', $raw_url, 'append']);
                if ($ipcRes !== false) {
                    $ipcSuccess = true;
                    $response = ['status' => 'success', 'message' => 'Track added to queue!'];
                }
            }

            // Fallback Recovery: Nếu MPV không chạy hoặc socket bị chết, tự động khởi động MPV mới
            if (!$ipcSuccess) {
                startMpvProcess($raw_url, $binDir, $socketFile, $cookieFile, $logFile);
                $response = ['status' => 'success', 'message' => 'MPV process recovered & started with track!'];
            }
        } else {
            $response = ['status' => 'error', 'message' => 'SECURITY REJECTED: Invalid or Untrusted YouTube URL!'];
        }
    }

    elseif ($action === 'next_track') {
        sendSingleIpc(['playlist-next']);
        $response = ['status' => 'success', 'message' => 'Skipped to next track!'];
    }

    elseif ($action === 'prev_track') {
        sendSingleIpc(['playlist-prev']);
        $response = ['status' => 'success', 'message' => 'Back to previous track!'];
    }

    elseif ($action === 'search_yt') {
        $query = trim($_POST['query'] ?? '');
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 10;
        $provider = trim($_POST['provider'] ?? 'ytsearch');

        if ($limit < 1) $limit = 5;
        if ($limit > 50) $limit = 50;

        if (empty($query)) {
            $response = ['status' => 'error', 'message' => 'Search query is empty!'];
        } else {
            $ytdlpBin = file_exists($binDir . '/yt-dlp') ? $binDir . '/yt-dlp' : 'yt-dlp';

            if ($provider === 'ytmsearch') {
                // YouTube Music Search qua URL Extractor
                $encodedQuery = urlencode($query);
                $targetUrl = "https://music.youtube.com/search?q={$encodedQuery}";
                $searchCmd = "$ytdlpBin " . escapeshellarg($targetUrl)
                            . " --playlist-end {$limit} --extractor-args \"youtube:player_client=web_music\" --dump-single-json --flat-playlist 2>/dev/null";
            } else {
                // YouTube Standard Search qua Prefix
                $searchCmd = "$ytdlpBin " . escapeshellarg("ytsearch{$limit}:$query")
                            . " --playlist-end {$limit} --dump-single-json --flat-playlist 2>/dev/null";
            }

            $jsonOutput = shell_exec($searchCmd);
            $parsed = json_decode($jsonOutput, true);

            if (isset($parsed['entries']) && is_array($parsed['entries'])) {
                $results = [];
                foreach ($parsed['entries'] as $entry) {
                    $itemUrl = $entry['url'] ?? '';
                    $videoId = $entry['id'] ?? '';

                    // Bỏ qua item là Browse Tab / Album không thể phát trực tiếp
                    if (($entry['ie_key'] ?? '') === 'YoutubeTab' || strpos($itemUrl, '/browse/') !== false) {
                        continue;
                    }

                    if (!empty($videoId) && (empty($itemUrl) || strpos($itemUrl, '/browse/') !== false)) {
                        $itemUrl = "https://www.youtube.com/watch?v=" . $videoId;
                    }

                    // Ưu tiên tiêu đề chuẩn
                    $title = $entry['title'] ?? ($entry['title_text'] ?? '');
                    if (empty($title)) continue; // Bỏ qua nếu rỗng title

                    // FALLBACK THUMBNAIL TỰ ĐỘNG
                    $thumb = '';
                    if (isset($entry['thumbnails'][0]['url']) && !empty($entry['thumbnails'][0]['url'])) {
                        $thumb = $entry['thumbnails'][0]['url'];
                    } elseif (!empty($videoId)) {
                        // Tự tạo link thumbnail chuẩn của YouTube CDN nếu YTM không trả về
                        $thumb = "https://i.ytimg.com/vi/{$videoId}/mqdefault.jpg";
                    }

                    $results[] = [
                        'id' => $videoId,
                        'url' => $itemUrl,
                        'title' => $title,
                        'duration' => $entry['duration'] ?? 0,
                        'uploader' => $entry['uploader'] ?? ($entry['channel'] ?? ($provider === 'ytmsearch' ? 'YouTube Music' : 'Unknown')),
                        'thumb' => $thumb
                    ];
                }
                $response = ['status' => 'success', 'results' => $results];
            } else {
                $response = ['status' => 'error', 'message' => 'No search results found! Check yt-dlp version or cookies.'];
            }
        }
    }

    elseif ($action === 'update_ytdlp' || $action === 'update_ytdlp_nightly') {
        $ytdlpPath = $binDir . '/yt-dlp';
        $downloadUrl = ($action === 'update_ytdlp_nightly')
            ? "https://github.com/yt-dlp/yt-dlp-nightly-builds/releases/latest/download/yt-dlp"
            : "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp";

        if (file_exists($ytdlpPath)) @unlink($ytdlpPath);

        $hasCurl = trim(shell_exec("which curl 2>/dev/null"));
        $dlCmd = !empty($hasCurl)
            ? "curl -L " . escapeshellarg($downloadUrl) . " -o " . escapeshellarg($ytdlpPath)
            : "wget -O " . escapeshellarg($ytdlpPath) . " " . escapeshellarg($downloadUrl);

        $output = shell_exec("$dlCmd && chmod +x " . escapeshellarg($ytdlpPath) . " 2>&1");

        if (file_exists($ytdlpPath) && filesize($ytdlpPath) > 0) {
            $ver = shell_exec("$ytdlpPath --version");
            $response = ['status' => 'success', 'message' => "yt-dlp updated successfully!\nVersion: " . trim($ver)];
        } else {
            $response = ['status' => 'error', 'message' => "Failed to download yt-dlp!\nLog: " . $output];
        }
    }

    elseif ($action === 'install_sys_pkg') {
        if (empty($ephemeralSudoPass)) {
            $response = ['status' => 'error', 'message' => 'ERROR: Sudo password is required!'];
        } else {
            $escapedPass = escapeshellarg($ephemeralSudoPass . "\n");
            $sysCmd = "echo $escapedPass | sudo -S -p '' apt-get update && echo $escapedPass | sudo -S -p '' apt-get install -y mpv ffmpeg procps psmisc python3 2>&1";
            $output = shell_exec($sysCmd);

            if (stristr($output, 'Permission denied') || stristr($output, 'incorrect password')) {
                $response = ['status' => 'error', 'message' => 'ERROR: Incorrect Sudo password!'];
            } else {
                $response = ['status' => 'success', 'message' => "Installation completed!\n" . $output];
            }
        }
    }

    elseif ($action === 'run_cmd') {
        if (!ENABLE_SHELL_TERMINAL) {
            $response = ['status' => 'terminal', 'output' => 'ERROR: Shell Terminal is disabled by security configuration! (ENABLE_SHELL_TERMINAL = false)'];
        } else {
            $userCmd = trim($_POST['cmd'] ?? '');
            if (!empty($userCmd)) {
                if (preg_match('/\bsudo\b/', $userCmd)) {
                    if (empty($ephemeralSudoPass)) {
                        $output = "ERROR: Sudo password is required!";
                    } else {
                        $escapedPass = escapeshellarg($ephemeralSudoPass . "\n");
                        $formattedCmd = preg_replace('/\bsudo\b/', "echo $escapedPass | sudo -S -p ''", $userCmd);
                        $output = shell_exec($formattedCmd . " 2>&1");
                    }
                } else {
                    $output = shell_exec($userCmd . " 2>&1");
                }
                $response = ['status' => 'terminal', 'output' => $output];
            }
        }
    }

    elseif ($action === 'save_cookies_text') {
        $cookieContent = $_POST['cookie_content'] ?? '';
        if (!empty(trim($cookieContent))) {
            file_put_contents($cookieFile, $cookieContent);
            $response = ['status' => 'success', 'message' => 'Saved cookies.txt successfully!'];
        } else {
            $response = ['status' => 'error', 'message' => 'Cookie content is empty!'];
        }
    }
    elseif ($action === 'upload_cookies' && isset($_FILES['cookie_file'])) {
        if ($_FILES['cookie_file']['error'] === UPLOAD_ERR_OK) {
            move_uploaded_file($_FILES['cookie_file']['tmp_name'], $cookieFile);
            $response = ['status' => 'success', 'message' => 'Uploaded cookies.txt successfully!'];
        } else {
            $response = ['status' => 'error', 'message' => 'Failed to upload cookies!'];
        }
    }
    elseif ($action === 'delete_cookies') {
        if (file_exists($cookieFile)) @unlink($cookieFile);
        $response = ['status' => 'success', 'message' => 'Deleted cookies.txt!'];
    }

    elseif ($action === 'stop') {
        exec("killall -9 mpv > /dev/null 2>&1 || pkill -9 mpv > /dev/null 2>&1");
        if (file_exists($socketFile)) @unlink($socketFile);
        file_put_contents($logFile, "[SYSTEM] Stopped MPV.\n", FILE_APPEND);
        $response = ['status' => 'error', 'message' => 'MPV process stopped!'];
    }

    elseif ($action === 'clear_log') {
        file_put_contents($logFile, "");
        $response = ['status' => 'success', 'message' => 'Cleared log!'];
    }

    elseif ($action === 'toggle_pause') {
        sendSingleIpc(['cycle', 'pause']);
        $response = ['status' => 'success', 'message' => 'Pause/Play toggled'];
    }

    elseif ($action === 'set_volume' && isset($_POST['vol'])) {
        $vol = (int)$_POST['vol'];
        sendSingleIpc(['set_property', 'volume', $vol]);
        $response = ['status' => 'success', 'message' => "Volume: {$vol}%"];
    }

    elseif ($action === 'get_status') {
        $runningMpv = trim(shell_exec("pgrep -a mpv 2>/dev/null || ps aux | grep [m]pv"));
        $logContent = file_exists($logFile) ? file_get_contents($logFile) : "No log available.";
        $hasCookie = file_exists($cookieFile) && filesize($cookieFile) > 0;
        $cookieSize = $hasCookie ? filesize($cookieFile) : 0;

        $mediaData = [
            'time_pos' => 0,
            'duration' => 0,
            'paused' => false,
            'volume' => 100,
            'title' => 'Loading...',
            'playlist' => []
        ];
        if ($runningMpv && file_exists($socketFile)) {
            $mediaData = queryMpvStatus();
        }

        $response = [
            'status' => 'ok',
            'runningMpv' => $runningMpv,
            'mpvLog' => $logContent,
            'hasCookie' => $hasCookie,
            'cookieSize' => $cookieSize,
            'media' => $mediaData
        ];
    }

    echo json_encode($response);
    exit;
}

function checkDependency($binary, $binDir) {
    $local = "$binDir/$binary";
    if (file_exists($local)) return "<span class='badge badge-local'>Local</span>";
    $sys = trim(shell_exec("which $binary 2>/dev/null"));
    if (!empty($sys)) return "<span class='badge badge-sys'>System</span>";
    return "<span class='badge badge-none'>Missing</span>";
}

$runningMpv = trim(shell_exec("pgrep -a mpv 2>/dev/null || ps aux | grep [m]pv"));
$hasCookie = file_exists($cookieFile) && filesize($cookieFile) > 0;
$initCookieText = $hasCookie ? file_get_contents($cookieFile) : "";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MPV Control Center</title>

    <!-- PWA Metadata -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0a0b0e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <!-- REGISTER SERVICE WORKER TRỰC TIẾP TRONG HEAD -->
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('./sw.js', { scope: './' })
                .then(reg => console.log('SW Registered successfully:', reg.scope))
                .catch(err => console.error('SW Register Error:', err));
        });
    }
    </script>

    <style>
        :root {
            --bg-primary: #0a0b0e;
            --bg-card: #12141a;
            --bg-input: #08090c;
            --border-color: #1e222d;
            --accent-blue: #38bdf8;
            --accent-green: #34d399;
            --accent-red: #f87171;
            --accent-yellow: #fbbf24;
            --text-main: #e2e8f0;
            --text-muted: #64748b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { width: 100%; overflow-x: hidden; background-color: var(--bg-primary); color: var(--text-main); font-family: 'JetBrains Mono', Consolas, Monaco, monospace; }
        body { padding: 12px; display: flex; justify-content: center; }

        .dashboard { width: 100%; max-width: 1000px; display: flex; flex-direction: column; gap: 14px; }
        .header { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 14px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .header h1 { font-size: 16px; color: var(--accent-blue); display: flex; align-items: center; gap: 6px; }
        .header .status-indicator { font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background-color: var(--accent-green); display: inline-block; }

        .login-box { background: var(--bg-card); border: 1px solid var(--border-color); padding: 24px; border-radius: 8px; max-width: 380px; margin: 60px auto; width: 100%; text-align: center; }
        .login-box h2 { font-size: 18px; color: var(--accent-blue); margin-bottom: 16px; }

        .panel { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; display: flex; flex-direction: column; gap: 14px; min-width: 0; }
        .panel-title { font-size: 11px; font-weight: bold; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; display: flex; justify-content: space-between; align-items: center; gap: 6px; flex-wrap: wrap; }

        .dep-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
        .dep-item { background-color: var(--bg-input); border: 1px solid var(--border-color); border-radius: 6px; padding: 10px 12px; display: flex; justify-content: space-between; align-items: center; font-size: 12px; }
        .dep-item span { color: var(--text-muted); }

        .badge { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-local { background: rgba(52, 211, 153, 0.15); color: var(--accent-green); border: 1px solid rgba(52, 211, 153, 0.3); }
        .badge-sys { background: rgba(56, 189, 248, 0.15); color: var(--accent-blue); border: 1px solid rgba(56, 189, 248, 0.3); }
        .badge-none { background: rgba(248, 113, 113, 0.15); color: var(--accent-red); border: 1px solid rgba(248, 113, 113, 0.3); }

        .main-grid { display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 14px; }

        input[type="text"], input[type="password"], textarea, select { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 6px; background-color: var(--bg-input); color: var(--accent-green); font-family: inherit; font-size: 13px; outline: none; word-break: break-all; }
        input[type="text"]:focus, input[type="password"]:focus, textarea:focus, select:focus { border-color: var(--accent-blue); }
        textarea { height: 90px; resize: vertical; color: var(--accent-blue); }

        .btn-group { display: flex; gap: 8px; flex-wrap: wrap; }
        button { min-height: 42px; padding: 10px 16px; border: none; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer; font-family: inherit; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap; }
        button:hover { opacity: 0.88; transform: translateY(-1px); }

        .btn-play { background-color: #dc2626; color: white; flex: 2 1 120px; }
        .btn-enqueue { background-color: #16a34a; color: white; flex: 1 1 90px; }
        .btn-stop { background-color: #1f2937; color: var(--accent-red); border: 1px solid var(--border-color); flex: 1 1 80px; }
        .btn-action { background-color: #0284c7; color: white; width: 100%; }
        .btn-sec { background-color: #1e293b; color: var(--text-main); border: 1px solid var(--border-color); flex: 1 1 auto; }
        .btn-sm { min-height: 32px; padding: 6px 12px; font-size: 11px; }

        .player-card { background-color: var(--bg-input); border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; display: flex; flex-direction: column; gap: 14px; min-width: 0; }
        .player-title { font-size: 13px; font-weight: bold; color: var(--accent-blue); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .progress-box { display: flex; align-items: center; gap: 10px; }
        .time-text { font-size: 11px; color: var(--text-muted); min-width: 40px; }
        .progress-bar-bg { width: 100%; height: 6px; background: #1e2029; border-radius: 3px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: var(--accent-blue); width: 0%; transition: width 0.3s; }

        .volume-row { display: flex; align-items: center; gap: 10px; background-color: var(--bg-card); padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-color); }
        input[type="range"].vol-slider { -webkit-appearance: none; width: 100%; height: 6px; background: #1e2029; border-radius: 3px; outline: none; }
        input[type="range"].vol-slider::-webkit-slider-thumb { -webkit-appearance: none; width: 16px; height: 16px; border-radius: 50%; background: var(--accent-blue); cursor: pointer; }

        /* Search UI */
        .search-item { display: flex; gap: 10px; background: var(--bg-input); padding: 8px; border-radius: 6px; border: 1px solid var(--border-color); align-items: center; }
        .search-thumb { width: 64px; height: 36px; border-radius: 4px; object-fit: cover; background: #000; }
        .search-info { flex: 1; min-width: 0; }
        .search-title { font-size: 12px; font-weight: bold; color: var(--accent-blue); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .search-sub { font-size: 10px; color: var(--text-muted); margin-top: 2px; }
        .search-btns { display: flex; gap: 4px; }

        /* Queue List UI */
        .queue-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 4px; font-size: 11px; }
        .queue-item.active { border-color: var(--accent-green); background: rgba(52, 211, 153, 0.05); }

        .log-box, .term-output { background-color: #000; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); font-size: 11px; white-space: pre-wrap; word-break: break-all; max-height: 160px; overflow-y: auto; overflow-x: hidden; }
        .log-box { color: var(--accent-yellow); }
        .term-output { color: var(--accent-green); display: none; }
        .proc-info { font-size: 11px; color: var(--accent-yellow); background-color: var(--bg-input); padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); word-break: break-all; max-height: 80px; overflow-y: auto; }

        .alert { padding: 10px 14px; border-radius: 6px; font-size: 12px; word-break: break-all; display: none; }
        .success { background: rgba(52, 211, 153, 0.15); color: var(--accent-green); border: 1px solid rgba(52, 211, 153, 0.3); }
        .error { background: rgba(248, 113, 113, 0.15); color: var(--accent-red); border: 1px solid rgba(248, 113, 113, 0.3); }

        @media (max-width: 768px) {
            body { padding: 8px; }
            .dep-grid { grid-template-columns: repeat(2, 1fr); }
            .main-grid { grid-template-columns: 1fr; }
            .btn-play, .btn-enqueue, .btn-stop { flex: 1 1 100%; }
        }
        @media (max-width: 420px) {
            .dep-grid { grid-template-columns: 1fr; }
            .header { flex-direction: column; align-items: flex-start; }
        }

        /* 3-STATE INTERACTIVE SCROLLBAR */
        html { color-scheme: dark !important; }
        ::-webkit-scrollbar { width: 8px !important; height: 8px !important; }
        ::-webkit-scrollbar-track { background: #08090c !important; border-radius: 4px !important; }
        ::-webkit-scrollbar-thumb { background: #475569 !important; border-radius: 4px !important; border: 2px solid #08090c !important; }
        ::-webkit-scrollbar-thumb:hover { background: #0284c7 !important; }
        ::-webkit-scrollbar-thumb:active { background: #38bdf8 !important; }

        @supports (scrollbar-color: auto) {
            @supports not (selector(::-webkit-scrollbar)) {
                * { scrollbar-width: thin; scrollbar-color: #475569 #08090c; }
            }
        }
    </style>
</head>
<body>

<?php if (!$isAuthenticated): ?>
<!-- SECURE LOGIN SCREEN -->
<div class="login-box">
    <h2>🔒 Access Control</h2>
    <input type="password" id="authPasscode" placeholder="Enter passcode..." style="margin-bottom: 12px;">
    <button type="button" class="btn-action" onclick="login()">Login</button>
    <div id="loginAlert" class="alert error" style="margin-top: 12px;"></div>
</div>

<script>
async function login() {
    const pass = document.getElementById('authPasscode').value;
    const formData = new FormData();
    formData.append('action', 'login');
    formData.append('passcode', pass);

    const res = await fetch(window.location.href, { method: 'POST', body: formData });
    const data = await res.json();
    if (data.status === 'success') {
        window.location.reload();
    } else {
        const alert = document.getElementById('loginAlert');
        alert.style.display = 'block';
        alert.textContent = data.message;
    }
}
document.getElementById('authPasscode').addEventListener('keypress', (e) => { if (e.key === 'Enter') login(); });
</script>

<?php else: ?>
<!-- MAIN DASHBOARD (AUTHENTICATED ONLY) -->
<div class="dashboard">
    <!-- Header -->
    <div class="header">
        <h1>⚡ Control Center</h1>
        <div class="status-indicator">
            <span class="dot"></span> Authenticated Session
            <a href="?logout=1" style="color: var(--accent-red); text-decoration: none; margin-left: 10px;">[Logout]</a>
        </div>
    </div>

    <!-- Binary Dependencies Panel -->
    <div class="panel" style="gap: 10px;">
        <div class="panel-title">Binary Dependencies</div>
        <div class="dep-grid">
            <div class="dep-item"><span>mpv:</span> <?php echo checkDependency('mpv', $binDir); ?></div>
            <div class="dep-item"><span>yt-dlp:</span> <?php echo checkDependency('yt-dlp', $binDir); ?></div>
            <div class="dep-item"><span>ffmpeg:</span> <?php echo checkDependency('ffmpeg', $binDir); ?></div>
            <div class="dep-item"><span>ffprobe:</span> <?php echo checkDependency('ffprobe', $binDir); ?></div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="main-grid">
        <!-- LEFT COLUMN: Media Controller & YouTube Search -->
        <div class="panel">
            <div class="panel-title">Media Launcher</div>

            <div style="display: flex; flex-direction: column; gap: 8px;">
                <input type="text" id="ytUrl" placeholder="Paste YouTube / YouTube Music link..." autocomplete="off">
                <div class="btn-group">
                    <button type="button" onclick="sendAction('play')" class="btn-play">▶ Play Now</button>
                    <button type="button" onclick="sendAction('enqueue')" class="btn-enqueue">+ Queue</button>
                    <button type="button" onclick="sendAction('stop')" class="btn-stop">⏹ Stop</button>
                </div>
            </div>

            <!-- Quick YouTube Search Box -->
            <div style="margin-top: 4px;">
                <div class="panel-title" style="margin-bottom: 8px;">🔎 Quick YouTube Search</div>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <input type="text" id="ytSearchInput" placeholder="Nhập tên bài hát, ca sĩ..." autocomplete="off" style="flex:2; min-width:140px;">

                    <!-- Combo Box Chọn Provider (YouTube vs YT Music) -->
                    <select id="ytSearchProvider" style="flex:1; min-width:95px; padding:0 6px; font-size:11px; cursor:pointer;">
                        <option value="ytsearch" selected>🎵 YouTube</option>
                        <option value="ytmsearch">🎧 YT Music</option>
                    </select>

                    <!-- Select Limit -->
                    <select id="ytSearchLimit" style="width:55px; padding:0 4px; font-size:11px; cursor:pointer;">
                        <option value="5">5</option>
                        <option value="10" selected>10</option>
                        <option value="15">15</option>
                        <option value="20">20</option>
                        <option value="30">30</option>
                    </select>

                    <button type="button" onclick="searchYoutube()" class="btn-action btn-sm" style="width: 70px;">Search</button>
                </div>
                <div id="searchResults" style="display:flex; flex-direction:column; gap:8px; margin-top:10px; width:100%; max-height:480px; overflow-y:auto; padding-right:4px;"></div>
            </div>

            <!-- Player Controller Card -->
            <div id="playerSection" style="<?php echo $runningMpv ? '' : 'display:none;'; ?>">
                <div class="panel-title" style="margin-bottom: 8px;">Now Playing</div>
                <div class="player-card">
                    <div class="player-title" id="mediaTitle">🎵 Loading info...</div>

                    <div class="progress-box">
                        <span class="time-text" id="timeCurrent">00:00</span>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" id="progressFill"></div>
                        </div>
                        <span class="time-text" id="timeTotal">00:00</span>
                    </div>

                    <div style="display:flex; gap:6px;">
                        <button type="button" class="btn-sec" style="flex:1;" onclick="sendAction('prev_track')">⏮ Prev</button>
                        <button type="button" id="pauseBtn" class="btn-action" style="flex:2;" onclick="sendAction('toggle_pause')">⏸ Pause</button>
                        <button type="button" class="btn-sec" style="flex:1;" onclick="sendAction('next_track')">⏭ Next</button>
                    </div>

                    <div class="volume-row">
                        <span style="font-size:12px; color:var(--text-muted);">🔊</span>
                        <input type="range" class="vol-slider" id="volumeSlider" min="0" max="100" value="100" oninput="setVolume(this.value)">
                        <span style="font-size:11px; color:var(--text-muted); min-width: 35px;" id="volText">100%</span>
                    </div>

                    <!-- Queue List -->
                    <div>
                        <div class="panel-title" style="margin-bottom: 6px; font-size:10px;">Current Playlist Queue</div>
                        <div id="queueList" style="display:flex; flex-direction:column; gap:4px; max-height:120px; overflow-y:auto;"></div>
                    </div>
                </div>
            </div>

            <!-- Active MPV Process Info -->
            <div id="procSection" style="<?php echo $runningMpv ? '' : 'display:none;'; ?>">
                <div class="panel-title" style="margin-bottom: 6px;">Active Process</div>
                <div class="proc-info" id="procBox"><?php echo htmlspecialchars($runningMpv); ?></div>
            </div>

            <!-- Log Viewer -->
            <div>
                <div class="panel-title" style="margin-bottom: 8px;">
                    <span>System Output Log</span>
                    <button type="button" class="btn-sec btn-sm" onclick="sendAction('clear_log')">Clear Log</button>
                </div>
                <div class="log-box" id="mpvLogBox">Waiting for log...</div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Tools, Cookies, Sudo & Terminal -->
        <div class="panel">
            <!-- Cookies Manager -->
            <div>
                <div class="panel-title" style="margin-bottom: 8px;">
                    <span>YouTube Cookies</span>
                    <span id="cookieBadge"></span>
                </div>
                <textarea id="cookieContent" placeholder="Paste cookies.txt content here (Netscape format)..."><?php echo htmlspecialchars($initCookieText); ?></textarea>
                <div class="btn-group" style="margin-top: 8px;">
                    <button type="button" onclick="sendAction('save_cookies_text')" class="btn-action btn-sm">Save Text</button>
                    <input type="file" id="cookieFile" style="display:none;" onchange="uploadCookieFile()">
                    <button type="button" onclick="document.getElementById('cookieFile').click()" class="btn-sec btn-sm">Upload File</button>
                    <button type="button" onclick="sendAction('delete_cookies')" class="btn-sec btn-sm" style="color:var(--accent-red);">Delete Cookie</button>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--border-color);">

            <!-- Sudo & Package Management -->
            <div>
                <div class="panel-title" style="margin-bottom: 8px; color: var(--accent-yellow);">Sudo & Maintenance</div>
                <input type="password" id="sudoPass" style="border-color: rgba(251, 191, 36, 0.3); background-color: rgba(251, 191, 36, 0.05);"
                       placeholder="🔑 Enter SUDO password (RAM Only)..." autocomplete="off">
                <div class="btn-group" style="margin-top: 8px;">
                    <button type="button" onclick="sendAction('update_ytdlp')" class="btn-sec btn-sm">yt-dlp Stable</button>
                    <button type="button" onclick="sendAction('update_ytdlp_nightly')" class="btn-sec btn-sm">yt-dlp Nightly</button>
                    <button type="button" onclick="sendAction('install_sys_pkg')" class="btn-sec btn-sm" style="color:var(--accent-yellow);">Install APT</button>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--border-color);">

            <!-- Terminal -->
            <div>
                <div class="panel-title" style="margin-bottom: 8px;">
                    <span>Shell Terminal</span>
                    <span style="font-size:9px; color: var(--accent-red);"><?php echo ENABLE_SHELL_TERMINAL ? 'ENABLED' : 'DISABLED'; ?></span>
                </div>
                <div style="display:flex; gap:8px;">
                    <input type="text" id="shellCmd" placeholder="<?php echo ENABLE_SHELL_TERMINAL ? 'Shell command...' : 'Terminal disabled in config'; ?>" <?php echo ENABLE_SHELL_TERMINAL ? '' : 'disabled'; ?> autocomplete="off">
                    <button type="button" onclick="sendAction('run_cmd')" class="btn-action btn-sm" style="width: 80px;" <?php echo ENABLE_SHELL_TERMINAL ? '' : 'disabled'; ?>>Exec</button>
                </div>
                <div class="term-output" id="termOutput" style="margin-top: 8px;"></div>
            </div>

            <div class="alert" id="alertBox"></div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = "<?php echo $_SESSION['csrf_token'] ?? ''; ?>";
let isCheckingStatus = false;
let statusTimer = null;

// SECURITY UTILITY: ESCAPE HTML ENTITIES
function escapeHtml(str) {
    if (typeof str !== 'string') return '';
    return str.replace(/[&<>"']/g, function(c) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[c];
    });
}

function formatTime(secs) {
    if (!secs || isNaN(secs) || secs <= 0) return "00:00";
    const totalSecs = Math.floor(parseFloat(secs));
    const m = Math.floor(totalSecs / 60);
    const s = totalSecs % 60;
    return (m < 10 ? "0" + m : m) + ":" + (s < 10 ? "0" + s : s);
}

// Fetch helper bọc AbortController chống treo UI
async function fetchWithTimeout(url, options = {}, timeoutMs = 8000) {
    const controller = new AbortController();
    const id = setTimeout(() => controller.abort(), timeoutMs);
    try {
        const response = await fetch(url, { ...options, signal: controller.signal });
        clearTimeout(id);
        return response;
    } catch (err) {
        clearTimeout(id);
        throw err;
    }
}

async function sendAction(actionName, extraData = {}) {
    const formData = new FormData();
    formData.append('action', actionName);

    let targetUrl = '';
    if (extraData && typeof extraData.url === 'string' && extraData.url.trim() !== '') {
        targetUrl = extraData.url.trim();
    } else {
        const inputElem = document.getElementById('ytUrl');
        if (inputElem) targetUrl = inputElem.value.trim();
    }

    formData.append('url', targetUrl);
    formData.append('sudo_pass', document.getElementById('sudoPass')?.value || '');
    formData.append('cmd', document.getElementById('shellCmd')?.value || '');
    formData.append('cookie_content', document.getElementById('cookieContent')?.value || '');

    for (let key in extraData) {
        if (key !== 'url') {
            formData.append(key, extraData[key]);
        }
    }

    const alertBox = document.getElementById('alertBox');
    const termOutput = document.getElementById('termOutput');

    try {
        const res = await fetchWithTimeout(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: formData
        }, 8000);

        if (res.status === 401 || res.status === 403) {
            window.location.reload();
            return;
        }

        const data = await res.json();

        if (data.status === 'terminal') {
            if (termOutput) {
                termOutput.style.display = 'block';
                termOutput.textContent = data.output || 'Command executed.';
            }
        } else if (data.message && actionName !== 'set_volume') {
            if (alertBox) {
                alertBox.style.display = 'block';
                alertBox.className = 'alert ' + (data.status === 'success' ? 'success' : 'error');
                alertBox.textContent = data.message;
            }
        }

        triggerStatusCheckNow();
    } catch (err) {
        if (alertBox) {
            alertBox.style.display = 'block';
            alertBox.className = 'alert error';
            alertBox.textContent = err.name === 'AbortError' ? 'Request Timeout!' : 'Server connection error or invalid CSRF token!';
        }
    }
}

// XSS-SAFE YT & YT MUSIC SEARCH (Xài data-url & Event Delegation)
async function searchYoutube() {
    const query = document.getElementById('ytSearchInput').value.trim();
    const limit = document.getElementById('ytSearchLimit').value;
    const provider = document.getElementById('ytSearchProvider').value;
    if (!query) return;

    const providerName = provider === 'ytmsearch' ? 'YouTube Music' : 'YouTube';
    const resultsBox = document.getElementById('searchResults');
    resultsBox.innerHTML = `<div style="font-size:11px; color:var(--text-muted);">Searching top ${limit} ${providerName} results...</div>`;

    const formData = new FormData();
    formData.append('action', 'search_yt');
    formData.append('query', query);
    formData.append('limit', limit);
    formData.append('provider', provider);

    try {
        const timeoutMs = limit > 15 ? 20000 : 12000;
        const res = await fetchWithTimeout(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF_TOKEN },
            body: formData
        }, timeoutMs);

        const data = await res.json();

        if (data.status === 'success' && data.results) {
            resultsBox.innerHTML = `<div style="font-size:10px; color:var(--accent-green); margin-bottom:4px;">✔ Found ${data.results.length} ${providerName} results</div>`;
            data.results.forEach(item => {
                const div = document.createElement('div');
                div.className = 'search-item';

                const safeTitle = escapeHtml(item.title);
                const safeUploader = escapeHtml(item.uploader);
                const safeThumb = escapeHtml(item.thumb);

                div.innerHTML = `
                    <img src="${safeThumb}" class="search-thumb" alt="thumb" onerror="this.style.display='none'">
                    <div class="search-info">
                        <div class="search-title" title="${safeTitle}">${safeTitle}</div>
                        <div class="search-sub">${safeUploader} • ${formatTime(item.duration)}</div>
                    </div>
                    <div class="search-btns">
                        <button type="button" class="btn-play btn-sm btn-play-direct">▶</button>
                        <button type="button" class="btn-enqueue btn-sm btn-enqueue-direct">+</button>
                    </div>
                `;

                // Safe event binding sử dụng dataset
                const playBtn = div.querySelector('.btn-play-direct');
                const enqueueBtn = div.querySelector('.btn-enqueue-direct');

                playBtn.dataset.url = item.url;
                enqueueBtn.dataset.url = item.url;

                playBtn.addEventListener('click', function() {
                    playDirect(this.dataset.url);
                });

                enqueueBtn.addEventListener('click', function() {
                    enqueueDirect(this.dataset.url);
                });

                resultsBox.appendChild(div);
            });
        } else {
            resultsBox.innerHTML = `<div style="font-size:11px; color:var(--accent-red);">${escapeHtml(data.message || 'No results found!')}</div>`;
        }
    } catch(e) {
        resultsBox.innerHTML = '<div style="font-size:11px; color:var(--accent-red);">Search failed or timed out!</div>';
    }
}

function playDirect(url) {
    if (!url) return;
    const inputElem = document.getElementById('ytUrl');
    if (inputElem) inputElem.value = url;
    sendAction('play', { url: url });
}

function enqueueDirect(url) {
    if (!url) return;
    sendAction('enqueue', { url: url });
}

function setVolume(val) {
    document.getElementById('volText').textContent = val + '%';
    sendAction('set_volume', { vol: val });
}

async function uploadCookieFile() {
    const fileInput = document.getElementById('cookieFile');
    if (!fileInput.files.length) return;

    const formData = new FormData();
    formData.append('action', 'upload_cookies');
    formData.append('cookie_file', fileInput.files[0]);

    try {
        const res = await fetchWithTimeout(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: formData
        }, 5000);
        const data = await res.json();
        alert(data.message);
        triggerStatusCheckNow();
    } catch(e) {
        alert("Upload failed or timed out.");
    }
}

document.getElementById('ytUrl').addEventListener('keypress', (e) => { if (e.key === 'Enter') sendAction('play'); });
document.getElementById('shellCmd').addEventListener('keypress', (e) => { if (e.key === 'Enter') sendAction('run_cmd'); });
document.getElementById('ytSearchInput').addEventListener('keypress', (e) => { if (e.key === 'Enter') searchYoutube(); });

async function checkStatus() {
    if (isCheckingStatus) return;
    isCheckingStatus = true;

    try {
        const formData = new FormData();
        formData.append('action', 'get_status');

        const res = await fetchWithTimeout(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: formData
        }, 3000);

        if (res.status === 401 || res.status === 403) {
            window.location.reload();
            return;
        }

        const data = await res.json();

        const procSection = document.getElementById('procSection');
        const playerSection = document.getElementById('playerSection');
        const procBox = document.getElementById('procBox');
        const mpvLogBox = document.getElementById('mpvLogBox');
        const cookieBadge = document.getElementById('cookieBadge');

        if (data.runningMpv) {
            procSection.style.display = 'block';
            playerSection.style.display = 'block';
            procBox.textContent = data.runningMpv;

            if (data.media) {
                const timePos = parseFloat(data.media.time_pos) || 0;
                const duration = parseFloat(data.media.duration) || 0;

                document.getElementById('mediaTitle').textContent = "🎵 " + (data.media.title || "Unknown Title");
                document.getElementById('timeCurrent').textContent = formatTime(timePos);
                document.getElementById('timeTotal').textContent = formatTime(duration);
                document.getElementById('pauseBtn').textContent = data.media.paused ? "▶ Play" : "⏸ Pause";

                if (duration > 0) {
                    let pct = (timePos / duration) * 100;
                    if (pct > 100) pct = 100;
                    if (pct < 0) pct = 0;
                    document.getElementById('progressFill').style.width = pct.toFixed(2) + '%';
                } else {
                    document.getElementById('progressFill').style.width = '0%';
                }

                // Render Queue Playlist Safe XSS
                const queueBox = document.getElementById('queueList');
                if (data.media.playlist && Array.isArray(data.media.playlist)) {
                    queueBox.innerHTML = '';
                    data.media.playlist.forEach((item, idx) => {
                        const qDiv = document.createElement('div');
                        qDiv.className = 'queue-item' + (item.current ? ' active' : '');
                        const rawTitle = item.title || item.filename || `Track #${idx + 1}`;
                        const prefix = item.current ? '▶ ' : '';

                        // Set text via textContent để chống XSS
                        const span = document.createElement('span');
                        span.textContent = `${prefix}${idx + 1}. ${rawTitle}`;
                        qDiv.appendChild(span);

                        queueBox.appendChild(qDiv);
                    });
                }
            }
        } else {
            procSection.style.display = 'none';
            playerSection.style.display = 'none';
        }

        if (data.hasCookie) {
            cookieBadge.innerHTML = `<span style="color:var(--accent-green); font-size:10px;">✔ Active (${data.cookieSize} B)</span>`;
        } else {
            cookieBadge.innerHTML = `<span style="color:var(--accent-red); font-size:10px;">✘ Empty</span>`;
        }

        if (data.mpvLog !== undefined) {
            const isScrolledToBottom = mpvLogBox.scrollHeight - mpvLogBox.clientHeight <= mpvLogBox.scrollTop + 50;
            mpvLogBox.textContent = data.mpvLog || 'No log data available.';
            if (isScrolledToBottom) {
                mpvLogBox.scrollTop = mpvLogBox.scrollHeight;
            }
        }
    } catch(e) {
        // Suppress errors during sleep
    } finally {
        isCheckingStatus = false;
        scheduleNextStatusCheck(1500);
    }
}

function scheduleNextStatusCheck(delay = 1500) {
    if (statusTimer) clearTimeout(statusTimer);
    statusTimer = setTimeout(checkStatus, delay);
}

function triggerStatusCheckNow() {
    if (statusTimer) clearTimeout(statusTimer);
    isCheckingStatus = false;
    checkStatus();
}

scheduleNextStatusCheck(500);

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        triggerStatusCheckNow();
    }
});

window.addEventListener('focus', () => {
    triggerStatusCheckNow();
});
</script>

<script>
// =========================================================================
// PWA SHARE TARGET & SHORTCUTS HANDLER
// =========================================================================
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);

    // 1. Xử lý khi nhận Share Target từ App YouTube / Browser (Nhận tham số ?url=... hoặc ?text=...)
    const sharedUrl = urlParams.get('url') || urlParams.get('text');
    if (sharedUrl) {
        // Tách lấy URL YouTube nếu chuỗi Share chứa cả Title
        const matched = sharedUrl.match(/(https?:\/\/[^\s]+)/g);
        const finalTargetUrl = matched ? matched[0] : sharedUrl;

        const ytInput = document.getElementById('ytUrl');
        if (ytInput) {
            ytInput.value = finalTargetUrl;
            // Tự động Play luôn khi nhận link từ nút Share!
            setTimeout(() => {
                sendAction('play', { url: finalTargetUrl });
            }, 300);
        }

        // Dọn sạch URL param để tránh lặp lại khi reload
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // 2. Xử lý App Shortcuts (Nhấn giữ icon ngoài Màn hình chính)
    const actionParam = urlParams.get('action');
    if (actionParam === 'focus_search') {
        const searchInput = document.getElementById('ytSearchInput');
        if (searchInput) searchInput.focus();
        window.history.replaceState({}, document.title, window.location.pathname);
    } else if (actionParam === 'stop_playback') {
        sendAction('stop');
        window.history.replaceState({}, document.title, window.location.pathname);
    }
});
</script>

<?php endif; ?>

</body>
</html>
