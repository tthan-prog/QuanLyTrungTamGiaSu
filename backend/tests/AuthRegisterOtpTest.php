<?php
// Chạy: php backend/tests/AuthRegisterOtpTest.php
// Kiểm tra register từ chối khi thiếu/sai/hết hạn/lệch email OTP, và vượt qua bước OTP khi đúng.
require __DIR__ . '/support/assert.php';

function reg(string $regEmail, string $otpEmail, string $otpInput, string $kind): string
{
    $args = [__DIR__ . '/support/register_child.php', $regEmail, $otpEmail, $otpInput, $kind];
    return (string)shell_exec('php ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null');
}
function msg(string $out): string
{
    $j = json_decode($out, true);
    return is_array($j) ? (string)($j['message'] ?? '?') : 'KHÔNG-PHẢI-JSON: ' . substr($out, 0, 80);
}

echo "== AuthController::register + OTP ==\n";
check('thiếu OTP → từ chối',                     msg(reg('a@x.vn', 'a@x.vn', '000000', 'none')), 'Vui lòng xác minh mã OTP gửi qua email');
check('mã OTP sai → "Mã OTP không đúng"',        msg(reg('a@x.vn', 'a@x.vn', 'WRONG1', 'ok')), 'Mã OTP không đúng');
check('token OTP rác → "Token không hợp lệ"',    msg(reg('a@x.vn', 'a@x.vn', '123456', 'garbage')), 'Token không hợp lệ');
// JWT::decode đã loại token hết hạn nên thông báo là "Token không hợp lệ" (vẫn bị từ chối).
check('token OTP hết hạn → bị từ chối',          msg(reg('a@x.vn', 'a@x.vn', 'RIGHT', 'expired')), 'Token không hợp lệ');
check('OTP đúng nhưng của email KHÁC → từ chối', msg(reg('victim@x.vn', 'attacker@x.vn', 'RIGHT', 'ok')), 'Mã OTP không khớp với email đăng ký');

$passed = msg(reg('A@X.vn ', 'a@x.vn', 'RIGHT', 'ok'));
$otpErrors = ['Mã OTP không đúng', 'Mã OTP không khớp với email đăng ký', 'Token không hợp lệ', 'Mã OTP đã hết hạn', 'Vui lòng xác minh mã OTP gửi qua email'];
check('OTP đúng, email khác hoa/thường/khoảng trắng → qua bước OTP (lỗi nếu có là lỗi DB, không phải OTP)', in_array($passed, $otpErrors, true), false);

finish('AuthRegisterOtpTest');
