import { useState, useEffect, lazy, Suspense } from "react";
import {
  BrowserRouter,
  Route,
  Routes,
  Navigate,
  useNavigate,
} from "react-router-dom";
import Layouts from "./Layouts/Layouts";
import Homepage from "./pages/HomePage";
import Tutorpage from "./pages/TutorPage";
// import Introduce from "./pages/Introduce";
import FeesPage from "./pages/FeesPage";
import ContactPage from "./pages/ContactPage";
import AvailableClassPage from "./pages/AvailableClassPage";
import RegisterforATrialClass from "./pages/RegisterforATrialClass";
import AdminLoginPage from "./pages/AdminLoginPage";
import AuthModal from "./components/AuthModal";
import { Toaster } from "sonner";
import PhuHuynhPage from "./pages/PhuHuynhPage";
import ScrollToTop from "./components/ScrollToTop";

// Tách 3 dashboard thành các chunk riêng, chỉ tải khi người dùng đăng nhập vào dashboard.
const AdminDashboard = lazy(() => import("./pages/AdminDashboard"));
const GiaSuDashboard = lazy(() => import("./pages/GiaSuDashboard"));
const PhuHuynhDashboard = lazy(() => import("./pages/PhuHuynhDashboard"));

const PageSpinner = () => (
  <div className="min-h-screen flex items-center justify-center bg-gray-50">
    <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
  </div>
);

// Component chính được wrap trong BrowserRouter
function AppContent() {
  const AUTH_SESSION_KEY = "auth_session_active";
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [showAuthModal, setShowAuthModal] = useState(false);
  const [authTab, setAuthTab] = useState("login");
  const navigate = useNavigate();

  // Khôi phục phiên đăng nhập đã lưu để F5 không bị đăng xuất.
  useEffect(() => {
    const token = localStorage.getItem("token");
    const savedUser = localStorage.getItem("user");
    const isCurrentTabSession =
      sessionStorage.getItem(AUTH_SESSION_KEY) === "1";

    if (token && savedUser && isCurrentTabSession) {
      try {
        setUser(JSON.parse(savedUser));
      } catch (error) {
        console.error("Không thể parse user từ localStorage:", error);
        localStorage.removeItem("token");
        localStorage.removeItem("user");
        sessionStorage.removeItem(AUTH_SESSION_KEY);
      }
    } else if (token || savedUser) {
      // Không tự dùng phiên cũ từ localStorage nếu tab hiện tại chưa đăng nhập.
      localStorage.removeItem("token");
      localStorage.removeItem("user");
      sessionStorage.removeItem(AUTH_SESSION_KEY);
    }

    setLoading(false);
  }, []);

  const handleAuthSuccess = (data) => {
    setUser(data.user);
    sessionStorage.setItem(AUTH_SESSION_KEY, "1");
    setShowAuthModal(false);

    // Chuyển đến dashboard sau khi đăng nhập thành công
    navigate("/dashboard");
  };

  const handleLogout = () => {
    console.log("=== LOGOUT ===");
    localStorage.removeItem("token");
    localStorage.removeItem("user");
    localStorage.removeItem("admin_active_item");
    localStorage.removeItem("giasu_active_item");
    localStorage.removeItem("phuhuynh_active_item");
    sessionStorage.removeItem(AUTH_SESSION_KEY);
    setUser(null);
    setShowAuthModal(false);
    navigate("/");
  };

  const openLogin = () => {
    setAuthTab("login");
    setShowAuthModal(true);
  };

  const openRegister = () => {
    setAuthTab("register");
    setShowAuthModal(true);
  };

  // Component để bảo vệ route dashboard
  const DashboardRoute = () => {
    // Đợi load user từ localStorage
    if (loading) {
      return (
        <div className="min-h-screen flex items-center justify-center bg-gray-50">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
        </div>
      );
    }

    if (!user) {
      return <Navigate to="/" replace />;
    }

    switch (user.role) {
      case "admin":
        return <AdminDashboard user={user} onLogout={handleLogout} />;
      case "gia_su":
        return <GiaSuDashboard user={user} onLogout={handleLogout} />;
      case "phu_huynh":
        return <PhuHuynhDashboard user={user} onLogout={handleLogout} />;
      default:
        console.log("Unknown role:", user.role);
        return <Navigate to="/" replace />;
    }
  };

  return (
    <>
      <Routes>
        {/* Public routes với Layout */}
        <Route
          element={
            <Layouts
              user={user}
              onLogin={openLogin}
              onRegister={openRegister}
              onLogout={handleLogout}
            />
          }
        >
          <Route path="/" element={<Homepage />} />
          <Route path="/phu-huynh" element={<PhuHuynhPage />} />
          <Route path="/dich-vu-gia-su" element={<Tutorpage />} />
          {/* <Route path="/ve-chung-toi" element={<Introduce />} /> */}
          <Route path="/hoc-phi-gia-su" element={<FeesPage />} />
          <Route path="/lien-he" element={<ContactPage />} />
          <Route path="/lop-hien-co" element={<AvailableClassPage />} />
          <Route path="/dang-ky-hoc-thu" element={<RegisterforATrialClass />} />
        </Route>

        <Route
          path="/admin/login"
          element={<AdminLoginPage user={user} onAuthSuccess={handleAuthSuccess} />}
        />

        {/* Dashboard route - không dùng Layout */}
        <Route
          path="/dashboard/*"
          element={
            <Suspense fallback={<PageSpinner />}>
              <DashboardRoute />
            </Suspense>
          }
        />
      </Routes>

      {/* Auth Modal */}
      <AuthModal
        isOpen={showAuthModal}
        onClose={() => setShowAuthModal(false)}
        onAuthSuccess={handleAuthSuccess}
        defaultTab={authTab}
      />
    </>
  );
}

function App() {
  return (
    <>
      <Toaster richColors />
      <BrowserRouter>
        <ScrollToTop />
        <AppContent />
      </BrowserRouter>
    </>
  );
}

export default App;
