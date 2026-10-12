<?php
require_once __DIR__ . '/../models/DangKyLop.php';
require_once __DIR__ . '/../models/ThongBaoModel.php';

class DangKyLopController {

    public function create() {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (empty($data['hoc_sinh_id']) || empty($data['lop_hoc_id'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Thiếu ID học sinh hoặc lớp học"]);
            return;
        }

        try {
            $result = DangKyLop::create($data);
            if ($result) {
                // Gửi thông báo cho Admin về đăng ký mới
                ThongBaoModel::guiThongBao(
                    1, // Admin ID (mặc định là 1)
                    'admin',
                    'Đăng ký lớp học mới',
                    "Có một yêu cầu đăng ký lớp học mới đang chờ duyệt. Vui lòng kiểm tra và xử lý.",
                    'dang_ky'
                );
                
                http_response_code(201);
                echo json_encode(["status" => "success", "message" => "Gửi yêu cầu đăng ký thành công, vui lòng chờ duyệt!"]);
            }
        } catch (Exception $e) {
            http_response_code(400); 
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    public function updateStatus($id) {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (empty($data['trang_thai'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Vui lòng cung cấp trạng thái mới (da_duyet, tu_choi, da_huy)"]);
            return;
        }

        // Chỉ admin được duyệt/từ chối; các vai trò khác chỉ được hủy đơn (da_huy).
        $role = RoutePolicy::$user['role'] ?? null;
        if ($role !== 'admin' && $data['trang_thai'] !== 'da_huy') {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Bạn không có quyền duyệt hoặc từ chối đơn đăng ký"]);
            return;
        }

        try {
            // Lấy thông tin đăng ký từ DB để biết phụ huynh
            $dangKy = DangKyLop::getById($id);
            
            $result = DangKyLop::updateStatus($id, $data['trang_thai']);
            if ($result) {
                // Thông báo cho phụ huynh về kết quả duyệt
                if ($dangKy && !empty($dangKy['phu_huynh_id'])) {
                    $trangThaiText = $data['trang_thai'] === 'da_duyet' ? 'được duyệt' : 'bị từ chối';
                    ThongBaoModel::guiThongBao(
                        $dangKy['phu_huynh_id'],
                        'phu_huynh',
                        'Cập nhật đăng ký lớp học',
                        "Yêu cầu đăng ký lớp '{$dangKy['ten_lop']}' cho học sinh {$dangKy['ten_hoc_sinh']} đã {$trangThaiText}.",
                        'dang_ky'
                    );
                }
                
                echo json_encode(["status" => "success", "message" => "Đã cập nhật trạng thái đơn đăng ký!"]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Lỗi: " . $e->getMessage()]);
        }
    }

    public function getByLop($lop_hoc_id) {
        $data = DangKyLop::getByLop($lop_hoc_id);
        echo json_encode(["status" => "success", "data" => $data]);
    }

    public function getHocSinhDaDuyetByLop($lop_hoc_id) {
        $data = DangKyLop::getHocSinhDaDuyetByLop($lop_hoc_id);
        echo json_encode(["status" => "success", "data" => $data]);
    }

    public function getAll() {
        $data = DangKyLop::getAll();
        echo json_encode(["status" => "success", "data" => $data]);
    }

    public function getByPhuHuynh($phu_huynh_id) {
        $data = DangKyLop::getByPhuHuynh($phu_huynh_id);
        echo json_encode(["status" => "success", "data" => $data]);
    }
}