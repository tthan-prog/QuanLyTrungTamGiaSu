<?php
require_once __DIR__ . '/../core/JWT.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../models/Admin.php';
require_once __DIR__ . '/../models/GiaSu.php';
require_once __DIR__ . '/../models/GiaSuMonHoc.php';
require_once __DIR__ . '/../models/PhuHuynh.php';
require_once __DIR__ . '/../models/HocSinh.php';
require_once __DIR__ . '/../models/ThongBaoModel.php';
require_once __DIR__ . '/../services/OTPService.php';

class AuthController
{
    private static function isLockedStatus(?string $status): bool
    {
        if ($status === null) {
            return false;
        }

        $normalized = strtolower(trim($status));
        return in_array($normalized, ['bi_khoa', 'khoa', 'inactive', 'locked', 'dong'], true);
    }

    private static function isTutorApproved(?string $status): bool
    {
        if ($status === null) {
            return false;
        }

        $normalized = strtolower(trim($status));
        return in_array($normalized, ['da_duyet', 'hoat_dong', 'active'], true);
    }

    private static function normalizeGender(?string $gender): ?string
    {
        if (!$gender) {
            return null;
        }

        $value = strtolower(trim($gender));
        if ($value === 'male') {
            return 'Nam';
        }
        if ($value === 'female') {
            return 'Nu';
        }
        if ($value === 'other') {
            return 'Khac';
        }

        return $gender;
    }

    private static function buildFileToken(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim((string)$value, '-');

        if ($value === '') {
            return 'unknown';
        }

        return substr($value, 0, 40);
    }

    private static function saveUploadedImage(array $file, string $subFolder, string $ownerKey = 'unknown'): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Exception('Tep tai len khong hop le.');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Khong the xac minh tep tai len.');
        }

        $allowedMime = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif'
        ];

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!isset($allowedMime[$mimeType])) {
            throw new Exception('Chi ho tro dinh dang anh JPG, PNG, WEBP, GIF.');
        }

        $maxBytes = 5 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxBytes) {
            throw new Exception('Kich thuoc anh khong duoc vuot qua 5MB.');
        }

        $baseUploadDir = realpath(__DIR__ . '/../../public');
        if (!$baseUploadDir) {
            throw new Exception('Khong tim thay thu muc public de luu tep.');
        }

        $targetDir = $baseUploadDir . '/uploads/tutors/' . $subFolder;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new Exception('Khong the tao thu muc luu tep.');
        }

        $ownerToken = self::buildFileToken($ownerKey);
        $fileTypeToken = $subFolder === 'avatar' ? 'avatar' : 'certificate';
        $timestamp = date('Ymd_His');
        $randomSuffix = bin2hex(random_bytes(3));
        $fileName = $fileTypeToken . '_' . $ownerToken . '_' . $timestamp . '_' . $randomSuffix . '.' . $allowedMime[$mimeType];
        $targetPath = $targetDir . '/' . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Khong the luu tep tai len.');
        }

        return [
            'file_name' => $fileName,
            'original_name' => $file['name'],
            'mime_type' => $mimeType,
            'size' => (int)($file['size'] ?? 0)
        ];
    }

    public static function login(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);

        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $password = $input['password'] ?? '';

        if ((empty($email) && empty($phone)) || empty($password)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Email/Số điện thoại và mật khẩu không được để trống'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $user = null;
        if (!empty($email)) {
            $user = self::findUserByEmailForLogin($email);
        }
        if (!$user && !empty($phone)) {
            $user = self::findUserByPhone($phone);
        }

        if (!$user) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Tài khoản không tồn tại'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $passwordValid = password_verify($password, $user['password']) || $password === $user['password'];
        
        if (!$passwordValid) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Mật khẩu không đúng'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (self::isLockedStatus($user['trang_thai'] ?? null)) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin để được hỗ trợ.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (($user['role'] ?? '') === 'gia_su' && !self::isTutorApproved($user['trang_thai'] ?? null)) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Tài khoản gia sư đang chờ duyệt. Vui lòng đợi Admin duyệt hồ sơ.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $token = JWT::encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'name' => $user['name']
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Đăng nhập thành công',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['name'],
                    'role' => $user['role']
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function register(): void
    {
        $isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos((string)$_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;
        $input = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);

        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $password = $input['password'] ?? '';
        $role = $input['role'] ?? 'phu_huynh';
        $student = $input['student'] ?? null;
        if ($isMultipart && is_string($student) && $student !== '') {
            $student = json_decode($student, true);
        }

        $tutor = $input['tutor'] ?? null;
        if ($isMultipart && is_string($tutor) && $tutor !== '') {
            $tutor = json_decode($tutor, true);
        }

        if (empty($name) || empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Vui lòng điền đầy đủ thông tin'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Bắt buộc xác minh OTP email ở máy chủ: không tin việc frontend "đã gọi /otp/verify".
        $otpToken = trim((string)($input['otp_token'] ?? ''));
        $otpCode = trim((string)($input['otp'] ?? ''));
        $otpCheck = ($otpToken !== '' && $otpCode !== '')
            ? OTPService::verify($otpToken, $otpCode)
            : ['valid' => false, 'message' => 'Vui lòng xác minh mã OTP gửi qua email'];

        if (!$otpCheck['valid'] || strtolower(trim($otpCheck['email'] ?? '')) !== strtolower($email)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => $otpCheck['valid'] ? 'Mã OTP không khớp với email đăng ký' : $otpCheck['message']
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (self::findUserByEmail($email)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Email đã được sử dụng'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            Database::beginTransaction();

            $newUser = null;

            if ($role === 'phu_huynh') {
                $phuHuynhId = PhuHuynh::create([
                    'ho_ten' => $name,
                    'so_dien_thoai' => $phone,
                    'email' => $email,
                    'mat_khau' => $password
                ]);

                if ($student && !empty($student['name'])) {
                    HocSinh::create([
                        'phu_huynh_id' => $phuHuynhId,
                        'ho_ten' => $student['name'],
                        'ngay_sinh' => $student['birthday'] ?? null,
                        'khoi_lop' => $student['grade'] ?? 6
                    ]);
                }

                $newUser = [
                    'id' => $phuHuynhId,
                    'name' => $name,
                    'email' => $email,
                    'role' => 'phu_huynh'
                ];

            } elseif ($role === 'gia_su') {
                $avatarPayload = null;
                $ownerKey = $email !== '' ? $email : $name;

                if ($isMultipart && isset($_FILES['avatar']) && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $avatarPayload = self::saveUploadedImage($_FILES['avatar'], 'avatar', $ownerKey);
                }

                $certificates = [];
                if ($isMultipart && isset($_FILES['certificates'])) {
                    $certFileSet = $_FILES['certificates'];
                    if (is_array($certFileSet['name'])) {
                        $total = count($certFileSet['name']);
                        for ($i = 0; $i < $total; $i++) {
                            if (($certFileSet['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                                continue;
                            }
                            $certFile = [
                                'name' => $certFileSet['name'][$i],
                                'type' => $certFileSet['type'][$i],
                                'tmp_name' => $certFileSet['tmp_name'][$i],
                                'error' => $certFileSet['error'][$i],
                                'size' => $certFileSet['size'][$i],
                            ];
                            $certificates[] = self::saveUploadedImage($certFile, 'certificates', $ownerKey);
                        }
                    }
                }

                $giaSuId = GiaSu::create([
                    'ho_ten' => $name,
                    'email' => $email,
                    'mat_khau' => $password,
                    'so_dien_thoai' => $phone,
                    'ngay_sinh' => $tutor['birthday'] ?? null,
                    'gioi_tinh' => self::normalizeGender($tutor['gender'] ?? null),
                    'dia_chi' => $tutor['address'] ?? null,
                    'bang_cap' => $tutor['degree'] ?? null,
                    'kinh_nghiem' => $tutor['experience'] ?? null,
                    'gioi_thieu' => $tutor['introduction'] ?? null,
                    'so_tai_khoan_ngan_hang' => trim(($tutor['bankAccount'] ?? '') . (empty($tutor['bankName']) ? '' : ' - ' . $tutor['bankName'])),
                    'anh_dai_dien' => $avatarPayload['file_name'] ?? null,
                    'chung_chi' => $certificates ? json_encode($certificates, JSON_UNESCAPED_UNICODE) : null
                ]);

                $subjectIds = [];
                if (is_array($tutor) && isset($tutor['subjectIds']) && is_array($tutor['subjectIds'])) {
                    foreach ($tutor['subjectIds'] as $subjectId) {
                        if (is_numeric($subjectId) && (int)$subjectId > 0) {
                            $subjectIds[] = (int)$subjectId;
                        }
                    }
                }
                $subjectIds = array_values(array_unique($subjectIds));

                foreach ($subjectIds as $subjectId) {
                    GiaSuMonHoc::create([
                        'gia_su_id' => $giaSuId,
                        'mon_hoc_id' => $subjectId,
                    ]);
                }

                $newUser = [
                    'id' => $giaSuId,
                    'name' => $name,
                    'email' => $email,
                    'role' => 'gia_su'
                ];
            }

            Database::commit();

            // Gửi thông báo cho Admin về thành viên mới
            ThongBaoModel::guiThongBao(
                1, // Admin ID
                'admin',
                'Thành viên mới đăng ký',
                "Một {$role} mới đã đăng ký: {$name} ({$email}).",
                'he_thong'
            );

            $isTutorAwaitingApproval = $newUser['role'] === 'gia_su';

            $responseData = [
                'user' => $newUser,
                'requires_approval' => $isTutorAwaitingApproval
            ];

            if (!$isTutorAwaitingApproval) {
                $token = JWT::encode([
                    'user_id' => $newUser['id'],
                    'email' => $newUser['email'],
                    'role' => $newUser['role'],
                    'name' => $newUser['name']
                ]);
                $responseData['token'] = $token;
            }

            http_response_code(201);
            echo json_encode([
                'status' => 'success',
                'message' => $isTutorAwaitingApproval
                    ? 'Đăng ký thành công. Hồ sơ gia sư đang chờ duyệt.'
                    : 'Đăng ký thành công',
                'data' => $responseData
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            Database::rollback();
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Lỗi đăng ký: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public static function me(): void
    {
        require_once __DIR__ . '/../middleware/AuthMiddleware.php';

        $user = AuthMiddleware::authenticate();

        if (!$user) {
            return;
        }

        $details = self::getUserDetails($user['user_id'], $user['role']);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'user_id' => $user['user_id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role'],
                'details' => $details
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function refresh(): void
    {
        require_once __DIR__ . '/../middleware/AuthMiddleware.php';

        $user = AuthMiddleware::authenticate();

        if (!$user) {
            return;
        }

        $newToken = JWT::encode([
            'user_id' => $user['user_id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'name' => $user['name']
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Token refreshed',
            'data' => [
                'token' => $newToken
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function logout(): void
    {
        echo json_encode([
            'status' => 'success',
            'message' => 'Đăng xuất thành công'
        ], JSON_UNESCAPED_UNICODE);
    }

    private static function findUserByPhone(string $phone): ?array
    {
        $admin = Admin::findByPhone($phone);
        if ($admin) return $admin;

        $tutor = GiaSu::findByPhone($phone);
        if ($tutor) return $tutor;

        $parent = PhuHuynh::findByPhone($phone);
        if ($parent) return $parent;

        return null;
    }

    private static function findUserByEmailForLogin(string $email): ?array
    {
        $admin = Admin::findByEmailForLogin($email);
        if ($admin) return $admin;

        $tutor = GiaSu::findByEmailForLogin($email);
        if ($tutor) return $tutor;

        $parent = PhuHuynh::findByEmailForLogin($email);
        if ($parent) return $parent;

        return null;
    }

    private static function findUserByEmail(string $email): ?array
    {
        $admin = Admin::findByEmail($email);
        if ($admin) return $admin;

        $tutor = GiaSu::findByEmail($email);
        if ($tutor) return $tutor;

        $parent = PhuHuynh::findByEmail($email);
        if ($parent) return $parent;

        return null;
    }

    private static function getUserDetails(int $userId, string $role): ?array
    {
        switch ($role) {
            case 'admin':
                return Admin::getDetails($userId);

            case 'gia_su':
                return GiaSu::getDetails($userId);

            case 'phu_huynh':
                return PhuHuynh::getDetails($userId);

            default:
                return null;
        }
    }
}
