<?php
require_once __DIR__ . '/../core/JWT.php';
require_once __DIR__ . '/EmailService.php';

class OTPService
{
    private static $expireMinutes = 5;
    private static $otpLength = 6;

    public static function generate(string $email): array
    {
        $otp = str_pad(random_int(0, 999999), self::$otpLength, '0', STR_PAD_LEFT);
        
        // Chỉ lưu HMAC của (email|otp) trong token. Payload JWT đọc được bởi bất kỳ ai
        // (chỉ base64), nên KHÔNG đặt OTP gốc vào đó.
        $token = JWT::encode([
            'email' => $email,
            'otp_hash' => JWT::hmac(self::fingerprintInput($email, $otp)),
            'exp' => time() + (self::$expireMinutes * 60)
        ]);
        
        return [
            'otp' => $otp,
            'token' => $token
        ];
    }

    private static function fingerprintInput(string $email, string $otp): string
    {
        return 'otp|' . strtolower(trim($email)) . '|' . $otp;
    }

    public static function sendEmail(string $email, string $otp): bool
    {
        return EmailService::sendOTP($email, $otp);
    }

    public static function verify(string $token, string $inputOtp): array
    {
        $decoded = JWT::decode($token);
        
        if (!$decoded) {
            return ['valid' => false, 'message' => 'Token không hợp lệ'];
        }
        
        if (($decoded['exp'] ?? 0) < time()) {
            return ['valid' => false, 'message' => 'Mã OTP đã hết hạn'];
        }

        if (empty($decoded['otp_hash']) || empty($decoded['email'])) {
            return ['valid' => false, 'message' => 'Token không hợp lệ'];
        }

        $expected = JWT::hmac(self::fingerprintInput($decoded['email'], $inputOtp));
        if (!hash_equals($decoded['otp_hash'], $expected)) {
            return ['valid' => false, 'message' => 'Mã OTP không đúng'];
        }
        
        return [
            'valid' => true,
            'email' => $decoded['email'],
            'message' => 'Xác minh thành công'
        ];
    }
}
