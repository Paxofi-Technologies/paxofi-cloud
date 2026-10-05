<?php
// Semgrep rule tests for paxoficloud.yaml. Not application code: excluded
// from scans by .semgrepignore and never loaded by the autoloader.

// ruleid: pc-shell-execution
shell_exec('whois ' . $domain);
// ruleid: pc-shell-execution
exec('ls', $out);
// ruleid: pc-shell-execution
proc_open('cmd', [], $pipes);
// ok: pc-shell-execution
$executor->execute($job);

// ruleid: pc-dynamic-code
eval($code);

// ruleid: pc-unserialize
$data = unserialize($payload);
// ok: pc-unserialize
$data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

// ruleid: pc-password-hash-not-argon2id
password_hash($password, PASSWORD_DEFAULT);
// ruleid: pc-password-hash-not-argon2id
password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
// ok: pc-password-hash-not-argon2id
password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1]);

// ruleid: pc-insecure-random, pc-weak-hash
$token = md5(uniqid());
// ruleid: pc-insecure-random
$code = mt_rand(100000, 999999);
// ok: pc-insecure-random
$code = random_int(100000, 999999);

// ruleid: pc-weak-hash
$digest = sha1($body);
// ok: pc-weak-hash
$digest = hash('sha256', $body);

// ruleid: pc-tls-verification-disabled
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// ruleid: pc-tls-verification-disabled
curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_SSL_VERIFYPEER => false]);
// ok: pc-tls-verification-disabled
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

// ruleid: pc-debug-output
var_dump($session);
// ruleid: pc-debug-output
print_r($request);
// ok: pc-debug-output
$text = print_r($request, true);

// ruleid: pc-sql-concatenation
$repository->fetchAll('SELECT * FROM invoices WHERE id = ' . $id);
// ruleid: pc-sql-concatenation
$connection->execute($base . ' WHERE tenant_id = :t', ['t' => $tenant]);
// ruleid: pc-sql-concatenation
$repository->fetchAll(sprintf('SELECT * FROM %s', $table));
// ok: pc-sql-concatenation
$repository->fetchAll('SELECT * FROM invoices WHERE id = :id', ['id' => $id]);
// ok: pc-sql-concatenation
$connection->execute('INSERT INTO ' . self::TABLE . ' (version) VALUES (:v)', ['v' => $v]);
