<?php
// Chạy RoutePolicy::enforce trong tiến trình riêng (vì deny() gọi exit).
// Dùng: php enforce_child.php <METHOD> <uri> <role|none|otp>
putenv('JWT_SECRET_KEY=test-secret-only-for-tests-0123456789abcdef');
$_ENV['JWT_SECRET_KEY'] = 'test-secret-only-for-tests-0123456789abcdef';
require __DIR__ . '/../../app/core/JWT.php';
require __DIR__ . '/../../app/middleware/RoutePolicy.php';

[, $method, $uri, $who] = $argv;
if ($who === 'otp') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . JWT::encode(['email' => 'a@b.c', 'otp_hash' => 'x']);
} elseif ($who === 'bad') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc.def.ghi';
} elseif ($who !== 'none') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . JWT::encode(['user_id' => 7, 'email' => 'x@y.z', 'role' => $who, 'name' => 'T']);
}
RoutePolicy::enforce($method, $uri);
echo 'ALLOWED';
