<?php
require_once __DIR__ . '/Env.php';

class JWT
{
    private static $secretKey = null;
    private static $expireTime = 3600;
    private static $initialized = false;

    private static function init(): void
    {
        if (self::$initialized) return;

        self::$secretKey = (string)Env::get('JWT_SECRET_KEY', Env::get('JWT_SECRET', bin2hex(random_bytes(32))));
        self::$expireTime = (int)Env::get('JWT_EXPIRE_TIME', Env::get('JWT_EXPIRY', 86400));
        self::$initialized = true;
    }

    public static function encode(array $payload): string
    {
        self::init();
        
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        $payload['iat'] = time();
        // Giữ nguyên 'exp' nếu nơi gọi tự đặt (ví dụ token OTP 5 phút); mặc định theo cấu hình.
        if (!isset($payload['exp'])) {
            $payload['exp'] = time() + self::$expireTime;
        }

        $base64Header = self::base64UrlEncode(json_encode($header));
        $base64Payload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac(
            'sha256',
            $base64Header . '.' . $base64Payload,
            self::$secretKey,
            true
        );
        $base64Signature = self::base64UrlEncode($signature);

        return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
    }

    public static function decode(string $token)
    {
        self::init();
        
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }

        [$base64Header, $base64Payload, $base64Signature] = $parts;

        $signature = hash_hmac(
            'sha256',
            $base64Header . '.' . $base64Payload,
            self::$secretKey,
            true
        );
        $validSignature = self::base64UrlEncode($signature);

        // So sánh thời gian hằng số, tránh tấn công đo thời gian
        if (!hash_equals($validSignature, $base64Signature)) {
            return false;
        }

        $payload = json_decode(self::base64UrlDecode($base64Payload), true);

        if (!is_array($payload)) {
            return false;
        }

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }

        return $payload;
    }

    /**
     * HMAC-SHA256 (hex) của một chuỗi bằng khóa bí mật của ứng dụng.
     * Dùng để lưu "dấu vân tay" của OTP thay vì lưu OTP gốc.
     */
    public static function hmac(string $data): string
    {
        self::init();
        return hash_hmac('sha256', $data, self::$secretKey);
    }

    public static function getTokenFromHeader(): ?string
    {
        $authHeader = '';

        // Try getallheaders() first (available in most server configs)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? '';
        }

        // Fallback to $_SERVER if getallheaders() not available or Authorization not found
        if (!$authHeader) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        }

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function setSecretKey(string $key): void
    {
        self::$secretKey = $key;
    }

    public static function setExpireTime(int $seconds): void
    {
        self::$expireTime = $seconds;
    }
}
