<?php
require_once __DIR__ . '/../core/JWT.php';

/**
 * Chính sách truy cập tập trung cho toàn bộ API.
 *
 * Quy tắc (khớp theo thứ tự, quy tắc đầu tiên khớp sẽ được áp dụng):
 *   - 'public'          : không cần token.
 *   - mảng vai trò      : cần token hợp lệ và vai trò nằm trong mảng.
 *   - không khớp quy tắc: cần token hợp lệ, vai trò bất kỳ trong ROLES (mặc định).
 *
 * Lưu ý: đây là kiểm tra theo NHÓM route + VAI TRÒ. Chưa kiểm tra "quyền sở hữu"
 * (ví dụ phụ huynh A đọc dữ liệu của phụ huynh B bằng cách đổi id) - cần làm riêng
 * trong từng controller.
 */
class RoutePolicy
{
    private const ROLES = ['admin', 'gia_su', 'phu_huynh'];

    /** @var array|null Payload JWT của người dùng hiện tại (sau khi enforce) */
    public static $user = null;

    /**
     * [METHOD, mẫu đường dẫn, 'public' | [vai trò...]]
     * Dùng '*' cho METHOD để khớp mọi phương thức.
     */
    private static function rules(): array
    {
        return [
            // ----- Xác thực & OTP (các controller tự kiểm tra token khi cần) -----
            ['POST', '/auth/login', 'public'],
            ['POST', '/auth/register', 'public'],
            ['POST', '/auth/refresh', 'public'],
            ['POST', '/auth/logout', 'public'],
            ['GET',  '/auth/me', 'public'],
            ['POST', '/otp/send', 'public'],
            ['POST', '/otp/verify', 'public'],
            ['POST', '/admin/login', 'public'],

            // ----- Dữ liệu công khai cho trang chủ / lớp hiện có / gia sư / đăng ký học thử -----
            ['GET',  '/giasu/dashboard', ['admin', 'gia_su']], // phải đứng trước /giasu/{id}
            ['GET',  '/lophoc', 'public'],
            ['GET',  '/lophoc/{id}', 'public'],
            ['GET',  '/monhoc', 'public'],
            ['GET',  '/monhoc/{id}', 'public'],
            ['GET',  '/giasu', 'public'],
            ['GET',  '/giasu/{id}', 'public'],
            ['GET',  '/giasu/media/{type}/{filename}', 'public'],
            ['POST', '/yeucau/create-guest', 'public'],
            ['GET',  '/danhgia/giasu/{gia_su_id}', 'public'],
            ['GET',  '/danhgia/trungbinh/{gia_su_id}', 'public'],

            // ----- Chỉ admin -----
            ['*',    '/doanhthu', ['admin']],
            ['*',    '/doanhthu/{x}', ['admin']],
            ['*',    '/doanhthu/{x}/{y}', ['admin']],
            ['*',    '/hocphi', ['admin']],
            ['*',    '/hocphi/{x}', ['admin']],
            ['*',    '/hocphi/{x}/{y}', ['admin']],
            ['GET',  '/luonggiasu/giasu/{id}', ['admin', 'gia_su']],
            ['GET',  '/luonggiasu/group/{gia_su_id}/{thang}/{nam}', ['admin', 'gia_su']],
            ['*',    '/luonggiasu', ['admin']],
            ['*',    '/luonggiasu/{x}', ['admin']],
            ['*',    '/luonggiasu/{x}/{y}', ['admin']],
            ['*',    '/luonggiasu/{x}/{y}/{z}/{w}', ['admin']],
            ['GET',  '/admin', ['admin']],
            ['GET',  '/admin/{id}', ['admin']],
            ['POST', '/admin/create', ['admin']],
            ['PUT',  '/admin/update/{id}', ['admin']],
            ['DELETE', '/admin/delete/{id}', ['admin']],
            ['POST', '/giasu/create', ['admin']],
            ['PUT',  '/giasu/approve/{id}', ['admin']],
            ['DELETE', '/giasu/delete/{id}', ['admin']],
            ['PUT',  '/giasu/update/{id}', ['admin', 'gia_su']],
            ['POST', '/monhoc/create', ['admin']],
            ['PUT',  '/monhoc/update/{id}', ['admin']],
            ['DELETE', '/monhoc/delete/{id}', ['admin']],
            ['POST', '/lophoc/create', ['admin']],
            ['PUT',  '/lophoc/update/{id}', ['admin']],
            ['PUT',  '/lophoc/status/{id}', ['admin']],
            ['DELETE', '/lophoc/delete/{id}', ['admin']],
            ['POST', '/lophoc/{id}/add-student', ['admin']],
            ['DELETE', '/lophoc/{id}/remove-student/{studentId}', ['admin']],
        ];
    }

    private static function match(string $pattern, string $uri): bool
    {
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '[^/]+', $pattern);
        return (bool)preg_match('#^' . $regex . '$#', $uri);
    }

    /**
     * Quyết định chính sách cho (method, uri) - tách riêng để dễ kiểm thử.
     *
     * @return string|array 'public' | 'authenticated' | mảng vai trò được phép
     */
    public static function resolve(string $method, string $uri)
    {
        foreach (self::rules() as [$m, $pattern, $access]) {
            if (($m === '*' || $m === $method) && self::match($pattern, $uri)) {
                return $access;
            }
        }
        return 'authenticated';
    }

    /**
     * Gọi trước khi chạy handler. Dừng request bằng 401/403 nếu không đủ quyền.
     */
    public static function enforce(string $method, string $uri): void
    {
        $access = self::resolve($method, $uri);

        if ($access === 'public') {
            return;
        }

        $token = JWT::getTokenFromHeader();
        $payload = $token ? JWT::decode($token) : false;

        // Token OTP cũng được ký bằng cùng khóa nhưng không có user_id/role -> không dùng làm token đăng nhập
        if (!is_array($payload) || empty($payload['user_id']) || !in_array($payload['role'] ?? '', self::ROLES, true)) {
            self::deny(401, 'Vui lòng đăng nhập để tiếp tục');
        }

        self::$user = $payload;

        if (is_array($access) && !in_array($payload['role'], $access, true)) {
            self::deny(403, 'Bạn không có quyền thực hiện thao tác này');
        }
    }

    private static function deny(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'message' => $message
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
}
