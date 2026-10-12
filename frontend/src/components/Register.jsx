import { useState, useEffect } from 'react'
import { authAPI, otpAPI } from '../api/authApi'
import { monHocAPI } from '../api/monhocApi'

const defaultFormData = {
  name: '',
  email: '',
  phone: '',
  password: '',
  confirmPassword: '',
  role: 'phu_huynh',
  // Phụ huynh fields
  studentName: '',
  studentBirthday: '',
  studentGrade: '1',
  // Gia sư fields
  birthday: '',
  gender: '',
  address: '',
  degree: '',
  subjectIds: [],
  introduction: '',
  experience: '',
  bankAccount: '',
  bankName: ''
}

export default function Register({ onSwitchToLogin, onRegisterSuccess, onClose }) {
  const [step, setStep] = useState(() => {
    const saved = sessionStorage.getItem('registerStep')
    return saved ? parseInt(saved) : 1
  })
  const [formData, setFormData] = useState(() => {
    const saved = sessionStorage.getItem('registerForm')
    if (!saved) return defaultFormData
    const parsed = JSON.parse(saved)
    return {
      ...defaultFormData,
      ...parsed,
      subjectIds: Array.isArray(parsed?.subjectIds) ? parsed.subjectIds : []
    }
  })
  const [otp, setOtp] = useState('')
  const [otpToken, setOtpToken] = useState(() => sessionStorage.getItem('otpToken') || '')
  const [loading, setLoading] = useState(false)
  const [sendingOtp, setSendingOtp] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [countdown, setCountdown] = useState(0)
  const [subjects, setSubjects] = useState([])

  useEffect(() => {
    sessionStorage.setItem('registerForm', JSON.stringify(formData))
  }, [formData])

  useEffect(() => {
    sessionStorage.setItem('registerStep', step.toString())
  }, [step])

  useEffect(() => {
    if (otpToken) sessionStorage.setItem('otpToken', otpToken)
  }, [otpToken])

  useEffect(() => {
    const fetchSubjects = async () => {
      try {
        const res = await monHocAPI.getAll()
        setSubjects(res?.data || [])
      } catch {
        setSubjects([])
      }
    }

    fetchSubjects()
  }, [])

  const clearFormData = () => {
    sessionStorage.removeItem('registerForm')
    sessionStorage.removeItem('registerStep')
    sessionStorage.removeItem('otpToken')
  }

  const handleChange = (e) => {
    setFormData({ ...formData, [e.target.name]: e.target.value })
    setError('')
    setSuccess('')
  }

  const startCountdown = () => {
    setCountdown(60)
    const timer = setInterval(() => {
      setCountdown(prev => {
        if (prev <= 1) { clearInterval(timer); return 0 }
        return prev - 1
      })
    }, 1000)
  }

  const handleSendOtp = async () => {
    if (!formData.email) {
      setError('Vui lòng nhập email')
      return
    }
    setSendingOtp(true)
    setError('')
    setSuccess('')

    try {
      const data = await otpAPI.send(formData.email)
      if (data.status === 'success') {
        setOtpToken(data.data.token)
        setStep(2)
        startCountdown()
      } else {
        setError(data.message || 'Không thể gửi OTP')
      }
    } catch (err) {
      setError(err.message || 'Lỗi gửi OTP')
    } finally {
      setSendingOtp(false)
    }
  }

  const handleResendOtp = async () => {
    if (countdown > 0) return
    await handleSendOtp()
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setError('')
    setSuccess('')

    if (formData.password !== formData.confirmPassword) {
      setError('Mật khẩu xác nhận không khớp')
      setLoading(false)
      return
    }

    if (formData.password.length < 6) {
      setError('Mật khẩu phải có ít nhất 6 ký tự')
      setLoading(false)
      return
    }

    if (step === 1) {
      setLoading(false)
      await handleSendOtp()
      return
    }

    if (!otp || otp.length !== 6) {
      setError('Vui lòng nhập mã OTP 6 số')
      setLoading(false)
      return
    }

    try {
      const otpResult = await otpAPI.verify(otpToken, otp)
      if (otpResult.status !== 'success') {
        setError(otpResult.message || 'Mã OTP không đúng')
        setLoading(false)
        return
      }

      let payload
      if (formData.role === 'gia_su') {
        payload = {
          name: formData.name,
          email: formData.email,
          phone: formData.phone,
          password: formData.password,
          role: formData.role,
          otp_token: otpToken,
          otp,
          tutor: {
            birthday: formData.birthday,
            gender: formData.gender,
            address: formData.address,
            degree: formData.degree,
            subjectIds: formData.subjectIds || [],
            introduction: formData.introduction,
            experience: formData.experience,
            bankAccount: formData.bankAccount,
            bankName: formData.bankName,
          },
        }
      } else {
        payload = {
          name: formData.name,
          email: formData.email,
          phone: formData.phone,
          password: formData.password,
          role: formData.role,
          otp_token: otpToken,
          otp,
          student: {
            name: formData.studentName,
            birthday: formData.studentBirthday,
            grade: formData.studentGrade
          }
        }
      }

      const data = await authAPI.register(payload)

      if (data.status === 'success') {
        clearFormData()

        const token = data?.data?.token
        const user = data?.data?.user
        const requiresApproval = Boolean(data?.data?.requires_approval)

        if (token && user) {
          localStorage.setItem('token', token)
          localStorage.setItem('user', JSON.stringify(user))
          sessionStorage.setItem('auth_session_active', '1')
          onRegisterSuccess?.(data.data)
        } else if (requiresApproval) {
          setOtp('')
          setOtpToken('')
          setStep(1)
          setFormData(defaultFormData)
          setSuccess('Đăng ký thành công. Hồ sơ gia sư của bạn đang chờ Admin duyệt. Vui lòng đăng nhập lại sau khi được duyệt.')
          onSwitchToLogin?.()
        } else {
          setSuccess(data.message || 'Đăng ký thành công')
          onSwitchToLogin?.()
        }
      } else {
        setError(data.message || 'Đăng ký thất bại')
      }
    } catch (err) {
      setError(err.message || 'Không thể kết nối đến server')
    } finally {
      setLoading(false)
    }
  }

  const grades = [
    { value: 'mam_non', label: 'Mầm non' },
    { value: '1', label: 'Lớp 1' },
    { value: '2', label: 'Lớp 2' },
    { value: '3', label: 'Lớp 3' },
    { value: '4', label: 'Lớp 4' },
    { value: '5', label: 'Lớp 5' },
    { value: '6', label: 'Lớp 6' },
    { value: '7', label: 'Lớp 7' },
    { value: '8', label: 'Lớp 8' },
    { value: '9', label: 'Lớp 9' },
    { value: '10', label: 'Lớp 10' },
    { value: '11', label: 'Lớp 11' },
    { value: '12', label: 'Lớp 12' },
    { value: 'dai_hoc', label: 'Đại học' },
    { value: 'khac', label: 'Khác' }
  ]

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div className="relative bg-white rounded-xl shadow-xl w-full max-w-3xl p-6 max-h-[90vh] overflow-y-auto">
        {/* Close button */}
        {onClose && (
          <button
            onClick={onClose}
            className="absolute top-3 right-3 w-10 h-10 flex items-center justify-center rounded-full text-red-400 hover:text-red-600 hover:bg-red-50 text-4xl border-2 border-red-200 hover:border-red-300 transition-all"
          >
            ×
          </button>
        )}

        {/* Header */}
        <div className="mb-4">
          <h2 className="text-xl font-bold text-gray-900">
            {step === 1 ? 'Đăng ký tài khoản' : 'Xác thực Email'}
          </h2>
          <p className="text-gray-500 text-sm mt-1">
            {step === 1 ? 'Tạo tài khoản để sử dụng dịch vụ' : `Nhập mã OTP đã gửi đến ${formData.email}`}
          </p>
        </div>

        {step === 2 ? (
          /* OTP Form */
          <form onSubmit={handleSubmit} className="space-y-4 max-w-sm mx-auto">
            <div>
              <label className="block text-gray-700 text-sm font-medium mb-1.5">Mã OTP</label>
              <input
                type="text"
                value={otp}
                onChange={(e) => {
                  setOtp(e.target.value.replace(/\D/g, '').slice(0, 6))
                  setError('')
                }}
                placeholder="Nhập mã 6 số"
                className="w-full px-3 py-3 border-2 border-gray-200 rounded-lg text-center text-xl tracking-[0.5em] font-mono focus:border-red-500 focus:ring-0 outline-none text-gray-900 bg-white"
                maxLength={6}
                autoFocus
              />
            </div>

            <div className="text-center text-sm">
              {countdown > 0 ? (
                <span className="text-gray-500">Gửi lại sau <span className="text-red-600 font-semibold">{countdown}s</span></span>
              ) : (
                <button type="button" onClick={handleResendOtp} disabled={sendingOtp} className="text-red-600 hover:underline font-medium">
                  {sendingOtp ? 'Đang gửi...' : 'Gửi lại mã OTP'}
                </button>
              )}
            </div>

            {error && <p className="text-red-500 text-center text-sm">{error}</p>}
            {success && <p className="text-green-600 text-center text-sm">{success}</p>}

            <div className="flex gap-3 pt-1">
              <button
                type="button"
                onClick={() => { setStep(1); setOtp(''); setError('') }}
                className="flex-1 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition text-sm"
              >
                Quay lại
              </button>
              <button
                type="submit"
                disabled={loading || otp.length !== 6}
                className="flex-1 py-2.5 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 disabled:opacity-50 transition text-sm"
              >
                {loading ? 'Đang xử lý...' : 'Xác nhận'}
              </button>
            </div>
          </form>
        ) : (
          /* Register Form - Horizontal Layout */
          <form onSubmit={handleSubmit}>
            <div className="flex gap-6">
              {/* Left Column - Account Info */}
              <div className="flex-1 space-y-3">
                <h3 className="font-semibold text-gray-800 text-sm border-b pb-2">Thông tin tài khoản</h3>
                
                {/* Role Selection */}
                <div>
                  <label className="block text-gray-700 text-sm font-medium mb-1.5">Bạn là</label>
                  <div className="grid grid-cols-2 gap-2">
                    <button
                      type="button"
                      onClick={() => setFormData({ ...formData, role: 'phu_huynh' })}
                      className={`py-2 px-3 rounded-lg text-sm font-medium border transition ${
                        formData.role === 'phu_huynh'
                          ? 'border-red-500 bg-red-50 text-red-700'
                          : 'border-gray-200 text-gray-600 hover:border-gray-300'
                      }`}
                    >
                      Phụ huynh
                    </button>
                    <button
                      type="button"
                      onClick={() => setFormData({ ...formData, role: 'gia_su' })}
                      className={`py-2 px-3 rounded-lg text-sm font-medium border transition ${
                        formData.role === 'gia_su'
                          ? 'border-red-500 bg-red-50 text-red-700'
                          : 'border-gray-200 text-gray-600 hover:border-gray-300'
                      }`}
                    >
                      Gia sư
                    </button>
                  </div>
                </div>

                {/* Name */}
                <div>
                  <label className="block text-gray-700 text-sm font-medium mb-1">Họ và tên</label>
                  <input
                    type="text"
                    name="name"
                    value={formData.name}
                    onChange={handleChange}
                    placeholder="Nguyễn Văn A"
                    className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                    required
                  />
                </div>

                {/* Email */}
                <div>
                  <label className="block text-gray-700 text-sm font-medium mb-1">Email</label>
                  <input
                    type="email"
                    name="email"
                    value={formData.email}
                    onChange={handleChange}
                    placeholder="email@gmail.com"
                    className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                    required
                  />
                </div>

                {/* Phone */}
                <div>
                  <label className="block text-gray-700 text-sm font-medium mb-1">Số điện thoại</label>
                  <input
                    type="tel"
                    name="phone"
                    value={formData.phone}
                    onChange={handleChange}
                    placeholder="0901234567"
                    className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                    required
                  />
                </div>

                {/* Password */}
                <div>
                  <label className="block text-gray-700 text-sm font-medium mb-1">Mật khẩu</label>
                  <input
                    type="password"
                    name="password"
                    value={formData.password}
                    onChange={handleChange}
                    placeholder="••••••••"
                    className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                    required
                  />
                </div>

                {/* Confirm Password */}
                <div>
                  <label className="block text-gray-700 text-sm font-medium mb-1">Xác nhận mật khẩu</label>
                  <input
                    type="password"
                    name="confirmPassword"
                    value={formData.confirmPassword}
                    onChange={handleChange}
                    placeholder="••••••••"
                    className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                    required
                  />
                </div>
              </div>

              {/* Right Column - Student Info (only for phu_huynh) OR Tutor Info (for gia_su) */}
              {formData.role === 'phu_huynh' ? (
                <div className="flex-1 space-y-3">
                  <h3 className="font-semibold text-gray-800 text-sm border-b pb-2">Thông tin học sinh</h3>
                  
                  {/* Student Name */}
                  <div>
                    <label className="block text-gray-700 text-sm font-medium mb-1">Tên học sinh</label>
                    <input
                      type="text"
                      name="studentName"
                      value={formData.studentName}
                      onChange={handleChange}
                      placeholder="Nguyễn Văn B"
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                      required
                    />
                  </div>

                  {/* Student Birthday */}
                  <div>
                    <label className="block text-gray-700 text-sm font-medium mb-1">Ngày sinh</label>
                    <input
                      type="date"
                      name="studentBirthday"
                      value={formData.studentBirthday}
                      onChange={handleChange}
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                      required
                    />
                  </div>

                  {/* Student Grade */}
                  <div>
                    <label className="block text-gray-700 text-sm font-medium mb-1">Khối lớp</label>
                    <select
                      name="studentGrade"
                      value={formData.studentGrade}
                      onChange={handleChange}
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                    >
                      {grades.map(g => <option key={g.value} value={g.value}>{g.label}</option>)}
                    </select>
                  </div>
                </div>
              ) : formData.role === 'gia_su' ? (
                <div className="flex-1 space-y-3 max-h-[400px] overflow-y-auto pr-2">
                  <h3 className="font-semibold text-gray-800 text-sm border-b pb-2 sticky top-0 bg-white">Thông tin gia sư</h3>
                  
                  {/* Birthday */}
                  <div>
                    <label className="block text-gray-700 text-sm font-medium mb-1">Ngày sinh</label>
                    <input
                      type="date"
                      name="birthday"
                      value={formData.birthday}
                      onChange={handleChange}
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                      required
                    />
                  </div>

                  {/* Gender */}
                  <div>
                    <label className="block text-gray-700 text-sm font-medium mb-1">Giới tính</label>
                    <select
                      name="gender"
                      value={formData.gender}
                      onChange={handleChange}
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                      required
                    >
                      <option value="">Chọn giới tính</option>
                      <option value="male">Nam</option>
                      <option value="female">Nữ</option>
                      <option value="other">Khác</option>
                    </select>
                  </div>

                  {/* Address */}
                  <div>
                    <label className="block text-gray-700 text-sm font-medium mb-1">Địa chỉ</label>
                    <input
                      type="text"
                      name="address"
                      value={formData.address}
                      onChange={handleChange}
                      placeholder="123 Đường ABC, Quận X, TP.HCM"
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                      required
                    />
                  </div>

                  {/* Degree */}
                  <div>
                    <label className="block text-gray-700 text-sm font-medium mb-1">Bằng cấp</label>
                    <input
                      type="text"
                      name="degree"
                      value={formData.degree}
                      onChange={handleChange}
                      placeholder="Cử nhân Toán học, Đại học ABC"
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-gray-700 text-sm font-medium mb-1">Môn có thể dạy (chọn nhiều)</label>
                    <div className="max-h-48 overflow-y-auto border border-gray-200 rounded-lg p-2 bg-white space-y-1">
                      {subjects.length === 0 ? (
                        <p className="text-sm text-gray-500 px-2 py-1">Chưa tải được danh sách môn học</p>
                      ) : (
                        subjects.map((subject) => {
                          const checked = formData.subjectIds.includes(String(subject.mon_hoc_id))
                          return (
                            <label key={subject.mon_hoc_id} className="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-gray-50 cursor-pointer">
                              <input
                                type="checkbox"
                                checked={checked}
                                onChange={(e) => {
                                  const id = String(subject.mon_hoc_id)
                                  if (e.target.checked) {
                                    setFormData((prev) => ({
                                      ...prev,
                                      subjectIds: [...prev.subjectIds, id],
                                    }))
                                  } else {
                                    setFormData((prev) => ({
                                      ...prev,
                                      subjectIds: prev.subjectIds.filter((item) => item !== id),
                                    }))
                                  }
                                }}
                              />
                              <span className="text-sm text-gray-800">{subject.ten_mon_hoc}</span>
                            </label>
                          )
                        })
                      )}
                    </div>
                  </div>

                  {/* Introduction */}
                  <div>
                    <label className="block text-gray-700 text-sm font-medium mb-1">Giới thiệu bản thân</label>
                    <textarea
                      name="introduction"
                      value={formData.introduction}
                      onChange={handleChange}
                      placeholder="Giới thiệu về bản thân, phương pháp giảng dạy..."
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                      rows="3"
                      required
                    />
                  </div>

                  {/* Experience */}
                  <div>
                    <label className="block text-gray-700 text-sm font-medium mb-1">Kinh nghiệm</label>
                    <textarea
                      name="experience"
                      value={formData.experience}
                      onChange={handleChange}
                      placeholder="Mô tả kinh nghiệm giảng dạy..."
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                      rows="2"
                      required
                    />
                  </div>

                  {/* Bank Account */}
                  <div>
                    <label className="block text-gray-700 text-sm font-medium mb-1">Số tài khoản ngân hàng</label>
                    <input
                      type="text"
                      name="bankAccount"
                      value={formData.bankAccount}
                      onChange={handleChange}
                      placeholder="0123456789"
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                      required
                    />
                  </div>

                  {/* Bank Name */}
                  <div>
                    <label className="block text-gray-700 text-sm font-medium mb-1">Tên ngân hàng</label>
                    <input
                      type="text"
                      name="bankName"
                      value={formData.bankName}
                      onChange={handleChange}
                      placeholder="Vietcombank"
                      className="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-red-500 outline-none transition text-sm text-gray-900"
                      required
                    />
                  </div>
                </div>
              ) : null}
            </div>

            {error && <p className="text-red-500 text-sm mt-3">{error}</p>}
            {success && <p className="text-green-600 text-sm mt-3">{success}</p>}

            {/* Submit */}
            <button
              type="submit"
              disabled={loading || sendingOtp}
              className="w-full py-2.5 mt-4 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 disabled:opacity-50 transition text-sm"
            >
              {loading || sendingOtp ? 'Đang xử lý...' : 'Tiếp tục'}
            </button>
          </form>
        )}

        {/* Footer */}
        <p className="text-center text-gray-500 text-sm mt-4">
          Đã có tài khoản?{' '}
          <button onClick={onSwitchToLogin} className="text-red-600 font-medium hover:underline">
            Đăng nhập
          </button>
        </p>
      </div>
    </div>
  )
}
