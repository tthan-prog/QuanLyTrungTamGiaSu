#!/bin/sh
# Chạy toàn bộ test backend: sh backend/tests/run_all.sh
cd "$(dirname "$0")" || exit 1
rc=0
php RoutePolicyTest.php || rc=1
php DangKyLopTest.php || rc=1
php AuthRegisterOtpTest.php || rc=1
exit $rc
