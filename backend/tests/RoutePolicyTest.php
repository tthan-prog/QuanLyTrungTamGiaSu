<?php
// Chạy: php backend/tests/RoutePolicyTest.php
require __DIR__ . '/support/assert.php';
putenv('JWT_SECRET_KEY=test-secret-only-for-tests-0123456789abcdef');
$_ENV['JWT_SECRET_KEY'] = 'test-secret-only-for-tests-0123456789abcdef';
require __DIR__ . '/../app/core/JWT.php';
require __DIR__ . '/../app/middleware/RoutePolicy.php';

echo "== resolve(): bảng chính sách ==\n";
$public = [
    ['POST', '/auth/login'], ['POST', '/auth/register'], ['POST', '/otp/send'], ['POST', '/otp/verify'],
    ['POST', '/admin/login'], ['GET', '/lophoc'], ['GET', '/lophoc/5'], ['GET', '/monhoc'], ['GET', '/giasu'],
    ['GET', '/giasu/3'], ['GET', '/giasu/media/avatar/a.jpg'], ['POST', '/yeucau/create-guest'],
    ['GET', '/danhgia/giasu/2'], ['GET', '/danhgia/trungbinh/2'],
];
foreach ($public as [$m, $u]) check("public  $m $u", RoutePolicy::resolve($m, $u), 'public');

$adminOnly = [
    ['GET', '/doanhthu'], ['GET', '/doanhthu/thang/2025'], ['GET', '/hocphi'], ['PUT', '/hocphi/3'],
    ['GET', '/luonggiasu'], ['POST', '/luonggiasu'], ['GET', '/admin'], ['POST', '/admin/create'],
    ['POST', '/giasu/create'], ['PUT', '/giasu/approve/4'], ['DELETE', '/giasu/delete/4'],
    ['POST', '/monhoc/create'], ['PUT', '/monhoc/update/1'], ['DELETE', '/monhoc/delete/1'],
    ['POST', '/lophoc/create'], ['PUT', '/lophoc/update/1'], ['PUT', '/lophoc/status/1'],
    ['DELETE', '/lophoc/delete/1'], ['POST', '/lophoc/1/add-student'], ['DELETE', '/lophoc/1/remove-student/9'],
];
foreach ($adminOnly as [$m, $u]) check("admin   $m $u", RoutePolicy::resolve($m, $u), ['admin']);

check('admin+gia_su GET /giasu/dashboard (đứng trước /giasu/{id})', RoutePolicy::resolve('GET', '/giasu/dashboard'), ['admin', 'gia_su']);
check('admin+gia_su PUT /giasu/update/4', RoutePolicy::resolve('PUT', '/giasu/update/4'), ['admin', 'gia_su']);
check('admin+gia_su GET /luonggiasu/giasu/4', RoutePolicy::resolve('GET', '/luonggiasu/giasu/4'), ['admin', 'gia_su']);
check('admin+gia_su GET /luonggiasu/group/4/9/2025', RoutePolicy::resolve('GET', '/luonggiasu/group/4/9/2025'), ['admin', 'gia_su']);
foreach ([['GET', '/dangkylop'], ['PUT', '/dangkylop/status/3'], ['GET', '/thongbao/user/1'], ['GET', '/lichhoc'], ['GET', '/lophoc/1/hocsinh']] as [$m, $u]) {
    check("mặc định (cần đăng nhập) $m $u", RoutePolicy::resolve($m, $u), 'authenticated');
}

echo "== enforce(): token thật, tiến trình riêng ==\n";
function enforceOf(string $method, string $uri, string $who): string
{
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/support/enforce_child.php') . ' ' . escapeshellarg($method) . ' ' . escapeshellarg($uri) . ' ' . escapeshellarg($who);
    $out = (string)shell_exec($cmd . ' 2>/dev/null');
    if ($out === 'ALLOWED') return 'ALLOWED';
    $j = json_decode($out, true);
    return is_array($j) ? $j['message'] : 'LỖI: ' . $out;
}
$login = 'Vui lòng đăng nhập để tiếp tục';
$forbid = 'Bạn không có quyền thực hiện thao tác này';
check('khách gọi route công khai',                      enforceOf('GET', '/lophoc', 'none'), 'ALLOWED');
check('khách gọi route cần đăng nhập → 401',            enforceOf('GET', '/dangkylop', 'none'), $login);
check('token rác → 401',                                enforceOf('GET', '/dangkylop', 'bad'), $login);
check('token OTP (không có user_id/role) → 401',        enforceOf('GET', '/dangkylop', 'otp'), $login);
check('token OTP vào route admin → 401',                enforceOf('GET', '/doanhthu', 'otp'), $login);
check('phụ huynh vào /doanhthu → 403',                  enforceOf('GET', '/doanhthu', 'phu_huynh'), $forbid);
check('gia sư vào /hocphi → 403',                       enforceOf('GET', '/hocphi', 'gia_su'), $forbid);
check('gia sư xóa môn học → 403',                       enforceOf('DELETE', '/monhoc/delete/1', 'gia_su'), $forbid);
check('phụ huynh tạo lớp → 403',                        enforceOf('POST', '/lophoc/create', 'phu_huynh'), $forbid);
check('admin vào /doanhthu → cho phép',                 enforceOf('GET', '/doanhthu', 'admin'), 'ALLOWED');
check('gia sư xem lương của mình → cho phép',           enforceOf('GET', '/luonggiasu/giasu/4', 'gia_su'), 'ALLOWED');
check('phụ huynh xem lương gia sư → 403',               enforceOf('GET', '/luonggiasu/giasu/4', 'phu_huynh'), $forbid);
check('phụ huynh vào dashboard gia sư → 403',           enforceOf('GET', '/giasu/dashboard', 'phu_huynh'), $forbid);
check('phụ huynh gọi route mặc định → cho phép',        enforceOf('GET', '/dangkylop', 'phu_huynh'), 'ALLOWED');

finish('RoutePolicyTest');
