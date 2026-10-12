<?php
require_once __DIR__ . '/../core/Database.php';

class DangKyLop {

    public static function create($data) {
        $sqlCheckParent = "SELECT ph.trang_thai
                           FROM hoc_sinh hs
                           INNER JOIN phu_huynh ph ON hs.phu_huynh_id = ph.phu_huynh_id
                           WHERE hs.hoc_sinh_id = :hs_id
                           LIMIT 1";
        $parent = Database::query($sqlCheckParent, [':hs_id' => $data['hoc_sinh_id']]);

        if (!$parent) {
            throw new Exception("Không tìm thấy học sinh hoặc phụ huynh!");
        }

        if (($parent[0]['trang_thai'] ?? '') === 'khoa') {
            throw new Exception("Phụ huynh của học sinh này đang bị khóa, không thể đăng ký lớp học.");
        }

        $sqlCheckExist = "SELECT dang_ky_id, trang_thai FROM dang_ky_lop WHERE hoc_sinh_id = :hs_id AND lop_hoc_id = :lop_id";
        $exist = Database::query($sqlCheckExist, [
            ':hs_id' => $data['hoc_sinh_id'], 
            ':lop_id' => $data['lop_hoc_id']
        ]);
        
        $sqlCheckCap = "SELECT so_luong_hien_tai, so_luong_toi_da, trang_thai FROM lop_hoc WHERE lop_hoc_id = :lop_hoc_id";
        $lop = Database::query($sqlCheckCap, [':lop_hoc_id' => $data['lop_hoc_id']]);

        if (!$lop) {
            throw new Exception("Không tìm thấy lớp học này!");
        }
        if (($lop[0]['trang_thai'] ?? '') === 'dong') {
            throw new Exception("Lớp học này đã bị khóa, không thể đăng ký.");
        }
        if ($lop[0]['so_luong_hien_tai'] >= $lop[0]['so_luong_toi_da']) {
            throw new Exception("Từ chối: Lớp học này đã đủ số lượng tối đa!");
        }

        if ($exist) {
            $trang_thai_cu = $exist[0]['trang_thai'];
            $dang_ky_id = $exist[0]['dang_ky_id'];
            if (in_array($trang_thai_cu, ['cho_duyet', 'da_duyet'])) {
                throw new Exception("Học sinh này đã đăng ký hoặc đang chờ duyệt cho lớp này rồi!");
            } else {
                $sqlUpdate = "UPDATE dang_ky_lop 
                              SET trang_thai = 'cho_duyet', ngay_dang_ky = CURRENT_TIMESTAMP 
                              WHERE dang_ky_id = :id";
                return Database::execute($sqlUpdate, [':id' => $dang_ky_id]);
            }
        }
   
        $sqlInsert = "INSERT INTO dang_ky_lop (hoc_sinh_id, lop_hoc_id, trang_thai) 
                      VALUES (:hoc_sinh_id, :lop_hoc_id, 'cho_duyet')";
        
        return Database::execute($sqlInsert, [
            ':hoc_sinh_id' => $data['hoc_sinh_id'],
            ':lop_hoc_id' => $data['lop_hoc_id']
        ]);
    }

    public static function updateStatus($id, $trang_thai_moi) {
        $sqlGet = "SELECT trang_thai, lop_hoc_id FROM dang_ky_lop WHERE dang_ky_id = :id";
        $dangKy = Database::query($sqlGet, [':id' => $id]);
        if (!$dangKy) throw new Exception("Không tìm thấy đơn đăng ký!");
        
        $trang_thai_cu = $dangKy[0]['trang_thai'];
        $lop_hoc_id = $dangKy[0]['lop_hoc_id'];
        
        if ($trang_thai_cu === $trang_thai_moi) return true; 

        $tangSiSo = ($trang_thai_cu !== 'da_duyet' && $trang_thai_moi === 'da_duyet');
        $giamSiSo = ($trang_thai_cu === 'da_duyet' && in_array($trang_thai_moi, ['tu_choi', 'da_huy']));

        // Giao dịch: kiểm tra sĩ số + tăng sĩ số + đổi trạng thái đơn là MỘT khối,
        // tránh hai lần duyệt đồng thời cùng vượt sĩ số tối đa.
        Database::beginTransaction();
        try {
            if ($tangSiSo) {
                $lop = Database::query("SELECT trang_thai FROM lop_hoc WHERE lop_hoc_id = :lop_hoc_id", [':lop_hoc_id' => $lop_hoc_id]);
                if (!$lop) {
                    throw new Exception("Không tìm thấy lớp học của đơn đăng ký!");
                }
                if (($lop[0]['trang_thai'] ?? '') === 'dong') {
                    throw new Exception("Không thể duyệt đơn! Lớp học này đã bị khóa.");
                }

                // Tăng sĩ số CÓ ĐIỀU KIỆN trong một câu lệnh duy nhất (nguyên tử).
                $daTang = Database::execute(
                    "UPDATE lop_hoc SET so_luong_hien_tai = so_luong_hien_tai + 1
                     WHERE lop_hoc_id = :lop_id AND so_luong_hien_tai < so_luong_toi_da",
                    [':lop_id' => $lop_hoc_id]
                );
                if ($daTang === 0) {
                    throw new Exception("Không thể duyệt đơn! Lớp học này đã đạt sĩ số tối đa.");
                }
            }

            $sql = "UPDATE dang_ky_lop SET trang_thai = :trang_thai";
            if ($trang_thai_moi === 'da_duyet') {
                $sql .= ", ngay_duyet = CURRENT_TIMESTAMP";
            }
            // Chỉ cập nhật nếu đơn vẫn ở trạng thái cũ (chống xử lý trùng làm lệch sĩ số).
            $sql .= " WHERE dang_ky_id = :id AND trang_thai = :trang_thai_cu";

            $result = Database::execute($sql, [
                ':trang_thai' => $trang_thai_moi,
                ':id' => $id,
                ':trang_thai_cu' => $trang_thai_cu
            ]);

            if ($result === 0) {
                throw new Exception("Đơn đăng ký vừa được xử lý ở thao tác khác, vui lòng tải lại trang.");
            }

            if ($giamSiSo) {
                Database::execute(
                    "UPDATE lop_hoc SET so_luong_hien_tai = so_luong_hien_tai - 1
                     WHERE lop_hoc_id = :lop_id AND so_luong_hien_tai > 0",
                    [':lop_id' => $lop_hoc_id]
                );
            }

            Database::commit();
            return true;
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }
    
    public static function getByLop($lop_hoc_id) {
        $sql = "SELECT dkl.*, hs.ho_ten AS ten_hoc_sinh 
                FROM dang_ky_lop dkl
                JOIN hoc_sinh hs ON dkl.hoc_sinh_id = hs.hoc_sinh_id
                WHERE dkl.lop_hoc_id = :lop_hoc_id
                ORDER BY dkl.ngay_dang_ky DESC";
        return Database::query($sql, [':lop_hoc_id' => $lop_hoc_id]);
    }

    public static function getHocSinhDaDuyetByLop($lop_hoc_id) {
        $sql = "SELECT dkl.*, hs.hoc_sinh_id, hs.ho_ten, hs.phu_huynh_id, ph.ho_ten AS ten_phu_huynh
                FROM dang_ky_lop dkl
                JOIN hoc_sinh hs ON dkl.hoc_sinh_id = hs.hoc_sinh_id
                LEFT JOIN phu_huynh ph ON hs.phu_huynh_id = ph.phu_huynh_id
                WHERE dkl.lop_hoc_id = :lop_hoc_id AND dkl.trang_thai IN ('da_duyet', 'da_duyet_truc_tiep')
                ORDER BY hs.ho_ten";
        return Database::query($sql, [':lop_hoc_id' => $lop_hoc_id]);
    }

    public static function getAll() {
        $sql = "SELECT dkl.dang_ky_id, dkl.hoc_sinh_id, dkl.lop_hoc_id, dkl.trang_thai, dkl.ngay_dang_ky, dkl.ngay_duyet,
                       hs.ho_ten AS ten_hoc_sinh, hs.phu_huynh_id, ph.ho_ten AS ten_phu_huynh,
                       lh.ten_lop, lh.gia_moi_buoi, lh.gia_toan_khoa, lh.so_buoi_hoc, lh.khoi_lop, lh.loai_chi_tra, lh.gia_tri_chi_tra, lh.ngay_ket_thuc,
                       gs.ho_ten AS ten_gia_su,
                       mh.ten_mon_hoc,
                       (SELECT GROUP_CONCAT(CONCAT(
                            CASE ldk.thu_trong_tuan
                                WHEN 2 THEN 'T2' WHEN 3 THEN 'T3' WHEN 4 THEN 'T4' 
                                WHEN 5 THEN 'T5' WHEN 6 THEN 'T6' WHEN 7 THEN 'T7' WHEN 8 THEN 'CN'
                            END,
                            ' (', TIME_FORMAT(ldk.gio_bat_dau, '%H:%i'), '-', TIME_FORMAT(ldk.gio_ket_thuc, '%H:%i'), ')'
                       ) ORDER BY ldk.thu_trong_tuan ASC SEPARATOR ', ') 
                       FROM lich_dinh_ky ldk WHERE ldk.lop_hoc_id = lh.lop_hoc_id AND ldk.trang_thai = 'hoat_dong') AS lich_hoc_du_kien,
                       (SELECT MIN(ngay_bat_dau) FROM lich_dinh_ky ldk WHERE ldk.lop_hoc_id = lh.lop_hoc_id AND ldk.trang_thai = 'hoat_dong') AS ngay_bat_dau
                FROM dang_ky_lop dkl
                JOIN hoc_sinh hs ON dkl.hoc_sinh_id = hs.hoc_sinh_id
                LEFT JOIN phu_huynh ph ON hs.phu_huynh_id = ph.phu_huynh_id
                JOIN lop_hoc lh ON dkl.lop_hoc_id = lh.lop_hoc_id
                LEFT JOIN mon_hoc mh ON lh.mon_hoc_id = mh.mon_hoc_id
                LEFT JOIN gia_su gs ON lh.gia_su_id = gs.gia_su_id
                WHERE dkl.trang_thai <> 'da_duyet_truc_tiep'
                ORDER BY dkl.trang_thai = 'cho_duyet' DESC, dkl.ngay_dang_ky DESC";
        return Database::query($sql);
    }

    public static function getByPhuHuynh($phu_huynh_id) {
        $sql = "SELECT dkl.dang_ky_id, dkl.hoc_sinh_id, dkl.lop_hoc_id, dkl.trang_thai, dkl.ngay_dang_ky, dkl.ngay_duyet,
                       hs.ho_ten AS ten_hoc_sinh, hs.phu_huynh_id, ph.ho_ten AS ten_phu_huynh,
                       lh.ten_lop, lh.gia_moi_buoi, lh.gia_toan_khoa, lh.so_buoi_hoc, lh.khoi_lop, lh.loai_chi_tra, lh.gia_tri_chi_tra, lh.ngay_ket_thuc,
                       gs.ho_ten AS ten_gia_su,
                       mh.ten_mon_hoc,
                       (SELECT GROUP_CONCAT(CONCAT(
                            CASE ldk.thu_trong_tuan
                                WHEN 2 THEN 'T2' WHEN 3 THEN 'T3' WHEN 4 THEN 'T4' 
                                WHEN 5 THEN 'T5' WHEN 6 THEN 'T6' WHEN 7 THEN 'T7' WHEN 8 THEN 'CN'
                            END,
                            ' (', TIME_FORMAT(ldk.gio_bat_dau, '%H:%i'), '-', TIME_FORMAT(ldk.gio_ket_thuc, '%H:%i'), ')'
                       ) ORDER BY ldk.thu_trong_tuan ASC SEPARATOR ', ') 
                       FROM lich_dinh_ky ldk WHERE ldk.lop_hoc_id = lh.lop_hoc_id AND ldk.trang_thai = 'hoat_dong') AS lich_hoc_du_kien,
                       (SELECT MIN(ngay_bat_dau) FROM lich_dinh_ky ldk WHERE ldk.lop_hoc_id = lh.lop_hoc_id AND ldk.trang_thai = 'hoat_dong') AS ngay_bat_dau
                FROM dang_ky_lop dkl
                JOIN hoc_sinh hs ON dkl.hoc_sinh_id = hs.hoc_sinh_id
                LEFT JOIN phu_huynh ph ON hs.phu_huynh_id = ph.phu_huynh_id
                JOIN lop_hoc lh ON dkl.lop_hoc_id = lh.lop_hoc_id
                LEFT JOIN mon_hoc mh ON lh.mon_hoc_id = mh.mon_hoc_id
                LEFT JOIN gia_su gs ON lh.gia_su_id = gs.gia_su_id
                WHERE hs.phu_huynh_id = :phu_huynh_id 
                                    AND lh.trang_thai <> 'dong'
                ORDER BY 
                    CASE dkl.trang_thai 
                        WHEN 'cho_duyet' THEN 1 
                        WHEN 'da_duyet' THEN 2 
                        ELSE 3 
                    END, 
                    dkl.ngay_dang_ky DESC";
        return Database::query($sql, [':phu_huynh_id' => $phu_huynh_id]);
    }

    public static function getById($id) {
        $sql = "SELECT dkl.*, hs.ho_ten AS ten_hoc_sinh, hs.phu_huynh_id, lh.ten_lop 
                FROM dang_ky_lop dkl
                JOIN hoc_sinh hs ON dkl.hoc_sinh_id = hs.hoc_sinh_id
                JOIN lop_hoc lh ON dkl.lop_hoc_id = lh.lop_hoc_id
                WHERE dkl.dang_ky_id = :id";
        $result = Database::query($sql, [':id' => $id]);
        return $result ? $result[0] : null;
    }
}