<?php
// Chạy AuthController::register với dữ liệu multipart giả (các ca bị OTP từ chối không chạm DB).
// Dùng: php register_child.php <email_dang_ky> <email_cua_otp> <otp_nhap|RIGHT> <ok|expired|garbage|none>
putenv('JWT_SECRET_KEY=test-secret-only-for-tests-0123456789abcdef');
$_ENV['JWT_SECRET_KEY'] = 'test-secret-only-for-tests-0123456789abcdef';
require __DIR__ . '/../../app/core/JWT.php';
require __DIR__ . '/../../app/services/OTPService.php';
require __DIR__ . '/../../app/controllers/AuthController.php';

[, $regEmail, $otpEmail, $otpInput, $kind] = $argv;
$gen = OTPService::generate($otpEmail);
$code = $gen['otp'];
$token = $gen['token'];
if ($kind === 'expired') {
    $token = JWT::encode(['email' => $otpEmail, 'otp_hash' => JWT::hmac('otp|' . strtolower(trim($otpEmail)) . '|' . $code), 'exp' => time() - 10]);
} elseif ($kind === 'garbage') {
    $token = 'abc.def.ghi';
}
$entered = $otpInput === 'RIGHT' ? $code : $otpInput;

$_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
$_POST = ['name' => 'Test', 'email' => $regEmail, 'phone' => '0900000000', 'password' => 'abcdef', 'role' => 'phu_huynh'];
if ($kind !== 'none') { $_POST['otp_token'] = $token; $_POST['otp'] = $entered; }
AuthController::register();
