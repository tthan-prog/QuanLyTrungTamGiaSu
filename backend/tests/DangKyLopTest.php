<?php
// Chạy: php backend/tests/DangKyLopTest.php  (cần extension pdo_sqlite)
// Sao chép model thật vào cây thư mục tạm cùng Database giả, nên SQL thật của updateStatus được chạy trên SQLite.
require __DIR__ . '/support/assert.php';
if (!extension_loaded('pdo_sqlite')) { echo "Bỏ qua: thiếu pdo_sqlite\n"; exit(0); }

$tmp = sys_get_temp_dir() . '/dkl_test_' . getmypid();
@mkdir("$tmp/app/core", 0777, true);
@mkdir("$tmp/app/models", 0777, true);
copy(__DIR__ . '/support/Database.php', "$tmp/app/core/Database.php");
copy(__DIR__ . '/../app/models/DangKyLop.php', "$tmp/app/models/DangKyLop.php");
require "$tmp/app/models/DangKyLop.php";
register_shutdown_function(function () use ($tmp) {
    @unlink("$tmp/app/core/Database.php"); @unlink("$tmp/app/models/DangKyLop.php");
    @rmdir("$tmp/app/core"); @rmdir("$tmp/app/models"); @rmdir("$tmp/app"); @rmdir($tmp);
});

$db = Database::pdo();
$db->exec("CREATE TABLE lop_hoc(lop_hoc_id INTEGER PRIMARY KEY, trang_thai TEXT, so_luong_hien_tai INT, so_luong_toi_da INT)");
$db->exec("CREATE TABLE dang_ky_lop(dang_ky_id INTEGER PRIMARY KEY, lop_hoc_id INT, trang_thai TEXT, ngay_duyet TEXT)");
$db->exec("INSERT INTO lop_hoc VALUES (1,'mo',0,1),(2,'dong',0,5)");
$db->exec("INSERT INTO dang_ky_lop VALUES (1,1,'cho_duyet',NULL),(2,1,'cho_duyet',NULL),(3,2,'cho_duyet',NULL)");

function siso(): int { return (int)Database::query("SELECT so_luong_hien_tai c FROM lop_hoc WHERE lop_hoc_id=1")[0]['c']; }
function tt(int $id): string { return Database::query("SELECT trang_thai t FROM dang_ky_lop WHERE dang_ky_id=$id")[0]['t']; }
function thu(callable $f): string { try { $f(); return 'OK'; } catch (Throwable $e) { return 'EX'; } }

echo "== DangKyLop::updateStatus ==\n";
thu(fn() => DangKyLop::updateStatus(1, 'da_duyet'));
check('duyệt đơn 1 khi còn chỗ: sĩ số tăng 0→1', siso(), 1);
check('duyệt đơn 1: trạng thái = da_duyet', tt(1), 'da_duyet');

thu(fn() => DangKyLop::updateStatus(1, 'da_duyet'));
check('duyệt lại đơn 1: sĩ số KHÔNG tăng thêm', siso(), 1);

thu(fn() => DangKyLop::updateStatus(2, 'da_duyet'));
check('duyệt đơn 2 khi lớp đã đầy: sĩ số vẫn 1', siso(), 1);
check('duyệt đơn 2 khi lớp đầy: đơn vẫn cho_duyet', tt(2), 'cho_duyet');

thu(fn() => DangKyLop::updateStatus(3, 'da_duyet'));
check('duyệt đơn vào lớp đã đóng: đơn vẫn cho_duyet', tt(3), 'cho_duyet');

thu(fn() => DangKyLop::updateStatus(1, 'da_huy'));
check('hủy đơn đã duyệt: sĩ số giảm về 0', siso(), 0);
check('hủy đơn đã duyệt: trạng thái = da_huy', tt(1), 'da_huy');

thu(fn() => DangKyLop::updateStatus(1, 'da_huy'));
check('hủy lần 2: sĩ số không âm', siso(), 0);

thu(fn() => DangKyLop::updateStatus(2, 'da_duyet'));
check('sau khi có chỗ, duyệt đơn 2 được: sĩ số = 1', siso(), 1);
check('đơn 2 = da_duyet', tt(2), 'da_duyet');

check('đơn không tồn tại không làm hỏng dữ liệu', thu(fn() => DangKyLop::updateStatus(99, 'da_duyet')) !== '' && siso() === 1, true);
finish('DangKyLopTest');
