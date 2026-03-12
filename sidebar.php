<?php
$user_ID = $_SESSION['u_ID'] ?? null;
$user_img = $_SESSION['u_img'] ?? null;
$user_name = $_SESSION['u_name'] ?? null;
$role_name = $_SESSION['role_name'] ?? null;
$role_ID = $_SESSION['role_ID'] ?? null;
$isAdmin = in_array($role_ID, [1]);

if (!isset($_SESSION['u_ID'])) {
  echo "<script>alert('請先登入!');location.href='index.php';</script>";
  exit;
}
?>

<style>
  /* ===== 下拉選單樣式（效能優化版）===== */

  .dropdown-submenu {
    position: relative;
    margin: 0;
  }

  /* 群組標題 - 無額外槓線，與其他項目一致 */
  .dropdown-submenu>.nav-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    user-select: none;
    position: relative;
    padding: 0.5rem 1rem;
    margin: 0.25rem 0;
    color: #6b7280 !important;
    font-size: 1rem;
    font-weight: 700;
    background: transparent !important;
    border-radius: 0;
  }

  .dropdown-submenu>.nav-link i {
    opacity: 0.7;
    color: #6b7280 !important;
  }

  .admin-sidebar .dropdown-submenu>.nav-link {
    color: rgba(255, 255, 255, 0.6) !important;
  }

  .admin-sidebar .dropdown-submenu>.nav-link i {
    color: rgba(255, 255, 255, 0.6) !important;
  }

  /* 箭頭 */
  .dropdown-submenu>.nav-link::after {
    content: '\f107';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    margin-left: auto;
    font-size: 11px;
    opacity: 0.6;
  }

  .dropdown-submenu.active>.nav-link::after {
    transform: rotate(180deg);
  }

  /* 一格一格的線區隔：每個選單項目上方加分隔線（第一個除外） */
  .sb-sidenav-menu>.nav-link:not(:first-child) {
    border-top: 1px solid #e5e7eb;
    margin-top: 0;
    padding-top: 0.5rem;
  }

  .sb-sidenav-menu>.dropdown-submenu:not(:first-child)>.nav-link {
    border-top: 1px solid #e5e7eb;
  }

  .admin-sidebar .sb-sidenav-menu>.nav-link:not(:first-child) {
    border-top-color: rgba(255, 255, 255, 0.15);
  }

  .admin-sidebar .sb-sidenav-menu>.dropdown-submenu:not(:first-child)>.nav-link {
    border-top-color: rgba(255, 255, 255, 0.15);
  }

  /* 子選單 - 樸素簡單，無 card 感 */
  .dropdown-submenu .dropdown-menu {
    position: relative;
    top: 0;
    left: 0;
    width: 100%;
    margin: 0;
    min-width: auto;
    background: transparent !important;
    border: none !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    padding: 2px 0;
    z-index: 1050;
    display: none;
    overflow: hidden;
  }

  .dropdown-submenu.active .dropdown-menu {
    display: block;
  }

  /* 子選單項目 - 樸素、無塊狀 */
  .dropdown-submenu .dropdown-menu .nav-link {
    padding: 0.5rem 1rem 0.5rem 2rem;
    color: #555;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 10px;
    white-space: normal;
    word-break: keep-all;
    border-left: 3px solid transparent;
    font-size: 1.05rem;
    font-weight: 700;
    position: relative;
    margin: 0;
    border-radius: 0;
  }

  .dropdown-submenu .dropdown-menu .nav-link i {
    width: 18px;
    text-align: center;
    font-size: 14px;
    opacity: 0.8;
  }

  /* 子選單 hover - 輕微 */
  .dropdown-submenu .dropdown-menu .nav-link:hover {
    background-color: #eee;
    color: #333;
    border-left-color: #999;
  }

  .dropdown-submenu .dropdown-menu .nav-link:hover i {
    opacity: 1;
  }

  /* 管理員子選單 - 樸素 */
  .admin-sidebar .dropdown-submenu .dropdown-menu {
    background: transparent !important;
    border: none;
    border-left: 1px solid rgba(255, 255, 255, 0.15);
    margin-left: 0.5rem;
    border-radius: 0 !important;
    box-shadow: none !important;
  }

  .admin-sidebar .dropdown-submenu .dropdown-menu .nav-link {
    color: rgba(255, 255, 255, 0.85);
    padding-left: 2rem;
    font-size: 1.05rem;
    font-weight: 700;
  }

  .admin-sidebar .dropdown-submenu .dropdown-menu .nav-link:hover {
    background-color: rgba(255, 255, 255, 0.08);
    color: #fff;
    border-left-color: rgba(255, 255, 255, 0.4);
  }

  .admin-sidebar .dropdown-submenu .dropdown-menu .nav-link i {
    color: rgba(255, 255, 255, 0.8);
  }

  body.sb-sidenav-toggled .dropdown-submenu .dropdown-menu {
    display: none !important;
  }

  /* 活動中的主選單 - 樸素 */
  .dropdown-submenu.active>.nav-link {
    background-color: transparent;
  }

  .admin-sidebar .dropdown-submenu.active>.nav-link {
    background-color: transparent;
  }

  /* 子選單 active - 左邊線 + 淡底 */
  .dropdown-submenu .dropdown-menu .nav-link.active {
    background-color: #e0e0e0 !important;
    color: #222 !important;
    border-left-color: #666 !important;
    font-weight: 600 !important;
  }

  .admin-sidebar .dropdown-submenu .dropdown-menu .nav-link.active {
    background-color: rgba(255, 255, 255, 0.12) !important;
    color: #fff !important;
    border-left-color: rgba(255, 255, 255, 0.6) !important;
  }

  /* 淺色模式：子選單 active 與其他使用者一致，使用灰色 */
  body.sidebar-light-mode .admin-sidebar .dropdown-submenu .dropdown-menu .nav-link.active {
    background: #e0e0e0 !important;
    color: #222 !important;
    border-left-color: #666 !important;
  }

  body.sidebar-light-mode .admin-sidebar .dropdown-submenu .dropdown-menu .nav-link.active i {
    color: #222 !important;
  }

  /* 側邊欄 active - 樸素：左邊線 + 淡底，無陰影無漸層 */
  .sb-sidenav-menu .nav-link.active {
    background: #e0e0e0 !important;
    color: #222 !important;
    font-weight: 600 !important;
    border-left-color: #666 !important;
  }

  .sb-sidenav-menu .nav-link.active i {
    color: #222 !important;
    opacity: 1 !important;
  }

  .admin-sidebar .sb-sidenav-menu .nav-link.active {
    background: rgba(255, 255, 255, 0.12) !important;
    color: #fff !important;
    border-left-color: rgba(255, 255, 255, 0.6) !important;
  }

  .admin-sidebar .sb-sidenav-menu .nav-link.active i {
    color: #fff !important;
    opacity: 1 !important;
  }

  /* 子選單 active */
  .dropdown-submenu .dropdown-menu .nav-link.active {
    background: #e0e0e0 !important;
    color: #222 !important;
    border-left-color: #666 !important;
  }

  .admin-sidebar .dropdown-submenu .dropdown-menu .nav-link.active {
    background: rgba(255, 255, 255, 0.12) !important;
    color: #fff !important;
    border-left-color: rgba(255, 255, 255, 0.6) !important;
  }

  /* 深色/浅色模式切换按钮样式 */
  .sidebar-theme-toggle {
    padding: 1rem 0.75rem;
    border-top: 1px solid rgba(0, 0, 0, 0.1);
    margin-top: auto;
    position: sticky;
    bottom: 0;
    background: inherit;
    z-index: 10;
  }

  .admin-sidebar .sidebar-theme-toggle {
    border-top-color: rgba(255, 255, 255, 0.15);
  }

  .theme-toggle-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.65rem 1rem;
    background: transparent;
    border: 1px solid #ddd;
    border-radius: 0;
    color: #555;
    font-size: 1.05rem;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
  }

  .theme-toggle-btn:hover {
    background: #eee;
  }

  .theme-toggle-btn i {
    font-size: 1.1rem;
  }

  /* 管理員切換按鈕 - 樸素，保留外框線條 */
  .admin-sidebar .theme-toggle-btn {
    background: transparent;
    border: 1px solid rgba(255, 255, 255, 0.4);
    color: rgba(255, 255, 255, 0.9);
  }

  .admin-sidebar .theme-toggle-btn:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.5);
  }

  /* 收合状态下的切换按钮 */
  body.sb-sidenav-toggled .theme-toggle-text {
    display: none;
  }

  body.sb-sidenav-toggled .theme-toggle-btn {
    justify-content: center;
    padding: 0.75rem;
  }

  /* 淺色模式：主題切換按鈕文字必須為深色，並加上外框線條 */
  body.sidebar-light-mode .admin-sidebar .theme-toggle-btn,
  body.sidebar-light-mode .admin-sidebar .theme-toggle-text {
    color: #333 !important;
  }

  body.sidebar-light-mode .admin-sidebar .theme-toggle-btn {
    border: 1px solid rgba(0, 0, 0, 0.2) !important;
  }

  body.sidebar-light-mode .admin-sidebar .theme-toggle-btn:hover {
    border-color: rgba(0, 0, 0, 0.3) !important;
  }

  body.sidebar-light-mode .admin-sidebar .theme-toggle-btn i {
    color: #333 !important;
  }

  /* 深色模式 - 只改变侧边栏背景颜色，使用更高优先级 */
  body.sidebar-dark-mode #layoutSidenav_nav,
  body.sidebar-dark-mode #layoutSidenav_nav.admin-sidenav-container,
  body.sidebar-dark-mode #sidenavAccordion,
  body.sidebar-dark-mode #sidenavAccordion.sb-sidenav-dark,
  body.sidebar-dark-mode #sidenavAccordion.admin-sidenav,
  body.sidebar-dark-mode .admin-sidenav-container,
  body.sidebar-dark-mode .admin-sidenav-container .sb-sidenav,
  body.sidebar-dark-mode .admin-sidenav {
    background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%) !important;
  }

  /* 浅色模式 - 只改变侧边栏背景颜色，使用更高优先级 */
  body.sidebar-light-mode #layoutSidenav_nav,
  body.sidebar-light-mode #layoutSidenav_nav.admin-sidenav-container,
  body.sidebar-light-mode #sidenavAccordion,
  body.sidebar-light-mode #sidenavAccordion.sb-sidenav-dark,
  body.sidebar-light-mode #sidenavAccordion.admin-sidenav,
  body.sidebar-light-mode #sidenavAccordion.sb-sidenav-light,
  body.sidebar-light-mode .admin-sidenav-container,
  body.sidebar-light-mode .admin-sidenav-container .sb-sidenav,
  body.sidebar-light-mode .admin-sidenav,
  body.sidebar-light-mode .admin-sidenav-container.sb-sidenav,
  body.sidebar-light-mode .admin-sidenav-container .admin-sidenav {
    background-color: #ffffff !important;
    background-image: none !important;
    background: #ffffff !important;
  }
</style>

<script>
  /* ===== 下拉選單邏輯（簡化＋高效版）===== */
  (function () {
    'use strict';

    window.addEventListener('DOMContentLoaded', function () {
      const sidebar = document.querySelector('.sb-sidenav-menu');
      if (!sidebar) return;

      // 在側邊欄裡用事件委派處理所有 dropdown-submenu
      sidebar.addEventListener('click', function (e) {
        const toggleLink = e.target.closest('.dropdown-submenu > .nav-link');
        if (!toggleLink) return;

        e.preventDefault();
        e.stopPropagation();

        const item = toggleLink.closest('.dropdown-submenu');
        if (!item) return;

        // 允許多個同時展開：只切換自己
        item.classList.toggle('active');
      });

      // 不再在點擊頁面其他地方時自動收合子選單，讓使用者點擊主選單標題時才切換
    });
  })();

  /* ===== 側邊欄深色/淺色模式切換（只改變背景顏色）===== */
  (function () {
    'use strict';

    const STORAGE_KEY = 'sidebarThemeMode';
    const DARK_MODE_CLASS = 'sidebar-dark-mode';
    const LIGHT_MODE_CLASS = 'sidebar-light-mode';

    function initThemeToggle() {
      const body = document.body;
      const toggleBtn = document.getElementById('sidebarThemeToggle');
      const themeIcon = document.getElementById('themeIcon');
      const themeText = document.querySelector('.theme-toggle-text');

      if (!toggleBtn) return;

      // 從 localStorage 讀取保存的主題
      const savedTheme = localStorage.getItem(STORAGE_KEY);
      // 如果有保存的主題，使用保存的；否則根據當前類判斷初始主題
      let isDarkMode;
      if (savedTheme === 'dark') {
        isDarkMode = true;
      } else if (savedTheme === 'light') {
        isDarkMode = false;
      } else {
        // 如果沒有保存的主題，根據當前類判斷（管理員默認深色，普通用戶默認淺色）
        const sidenav = document.querySelector('#sidenavAccordion');
        isDarkMode = sidenav && (sidenav.classList.contains('sb-sidenav-dark') ||
          sidenav.classList.contains('admin-sidenav'));
        // 保存初始主題到 localStorage
        localStorage.setItem(STORAGE_KEY, isDarkMode ? 'dark' : 'light');
      }

      // 應用主題（包括圖標顏色）
      applyTheme(body, isDarkMode, themeIcon, themeText);

      // 初始化時設置圖標顏色
      setTimeout(function () {
        const lordIcons = document.querySelectorAll('lord-icon');
        lordIcons.forEach(function (icon) {
          if (isDarkMode) {
            icon.setAttribute('colors', 'primary:#ffffff');
          } else {
            icon.setAttribute('colors', 'primary:#000000');
          }
        });
      }, 100);

      // 綁定切換按鈕事件
      toggleBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();

        const currentIsDark = body.classList.contains(DARK_MODE_CLASS);
        const newIsDark = !currentIsDark;

        console.log('切換主題:', newIsDark ? '深色' : '淺色');
        applyTheme(body, newIsDark, themeIcon, themeText);
        localStorage.setItem(STORAGE_KEY, newIsDark ? 'dark' : 'light');
      });
    }

    function applyTheme(body, isDark, icon, text) {
      console.log('applyTheme 被調用, isDark:', isDark);

      // 移除所有主題類
      body.classList.remove(DARK_MODE_CLASS, LIGHT_MODE_CLASS);

      if (isDark) {
        // 切換到深色模式
        body.classList.add(DARK_MODE_CLASS);

        if (icon) {
          icon.className = 'fa-solid fa-sun';
        }
        if (text) {
          text.textContent = '淺色模式';
        }
      } else {
        // 切換到淺色模式
        body.classList.add(LIGHT_MODE_CLASS);

        if (icon) {
          icon.className = 'fa-solid fa-moon';
        }
        if (text) {
          text.textContent = '深色模式';
        }
      }

      // 強制應用樣式到側邊欄元素
      const layoutNav = document.getElementById('layoutSidenav_nav');
      const sidenav = document.getElementById('sidenavAccordion');
      const adminContainer = document.querySelector('.admin-sidenav-container');

      console.log('找到的元素:', {
        layoutNav: !!layoutNav,
        sidenav: !!sidenav,
        adminContainer: !!adminContainer
      });

      // 設置樣式的輔助函數
      function setStyleImportant(element, property, value) {
        element.style.setProperty(property, value, 'important');
      }

      // 應用背景樣式的函數
      function applyBackground(element, isDark) {
        if (isDark) {
          // 深色模式：使用漸變背景
          element.style.cssText += 'background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%) !important; background-color: #1a1a2e !important;';
        } else {
          // 淺色模式：純白色背景
          // 先移除所有背景相關的樣式
          const currentStyle = element.style.cssText || '';
          const cleanedStyle = currentStyle.replace(/background[^;]*;?/gi, '').trim();
          element.style.cssText = cleanedStyle + ' background-color: #ffffff !important; background: #ffffff !important;';
        }
      }

      if (layoutNav) {
        applyBackground(layoutNav, isDark);
        console.log('layoutNav 樣式已設置 - background:', layoutNav.style.background, 'background-color:', layoutNav.style.backgroundColor);
      }

      if (sidenav) {
        applyBackground(sidenav, isDark);
        console.log('sidenav 樣式已設置 - background:', sidenav.style.background, 'background-color:', sidenav.style.backgroundColor);
      }

      // 處理管理員側邊欄容器
      if (adminContainer) {
        applyBackground(adminContainer, isDark);
        console.log('adminContainer 樣式已設置 - background:', adminContainer.style.background, 'background-color:', adminContainer.style.backgroundColor);
      }

      // 也處理所有可能的子元素，包括 .sb-sidenav-menu 和 .admin-sidebar
      const allSidenavElements = document.querySelectorAll('#layoutSidenav_nav, #sidenavAccordion, .admin-sidenav-container, .admin-sidenav-container .sb-sidenav, .admin-sidenav, .sb-sidenav-menu, .admin-sidebar');
      allSidenavElements.forEach(function (el) {
        applyBackground(el, isDark);
      });
      console.log('已處理', allSidenavElements.length, '個側邊欄元素');

      // 特別處理 .admin-sidebar，因為它有獨立的背景樣式和文字顏色
      const adminSidebarMenu = document.querySelector('.admin-sidebar');
      if (adminSidebarMenu) {
        applyBackground(adminSidebarMenu, isDark);
        // 設置文字顏色
        if (isDark) {
          setStyleImportant(adminSidebarMenu, 'color', '#fff');
        } else {
          setStyleImportant(adminSidebarMenu, 'color', '#333');
        }
        console.log('adminSidebarMenu 樣式已設置 - background:', adminSidebarMenu.style.background, 'color:', adminSidebarMenu.style.color);
      }

      // 設置所有 nav-link 的文字顏色（包括普通用戶和管理員）
      const navLinks = document.querySelectorAll('.admin-sidebar .nav-link, .sb-sidenav-menu .nav-link');
      navLinks.forEach(function (link) {
        const isActive = link.classList.contains('active');
        if (isDark) {
          setStyleImportant(link, 'color', isActive ? '#ffffff' : 'rgba(255, 255, 255, 0.85)');
          const icon = link.querySelector('i');
          if (icon) {
            setStyleImportant(icon, 'color', '#ffffff');
          }
        } else {
          // 淺色模式：active 與其他使用者一致用灰色，其餘用深灰
          setStyleImportant(link, 'color', isActive ? '#222' : '#333');
          const icon = link.querySelector('i');
          if (icon) {
            setStyleImportant(icon, 'color', isActive ? '#222' : '#333');
          }
        }
      });
      console.log('已設置', navLinks.length, '個導航連結的文字顏色');

      // 明確設置主題切換按鈕圖標與文字顏色（避免淺色模式白字消失）
      const themeToggleBtn = document.querySelector('.theme-toggle-btn');
      const themeToggleText = document.querySelector('.theme-toggle-text');
      if (icon) {
        setStyleImportant(icon, 'color', isDark ? '#ffffff' : '#333');
      }
      if (themeToggleBtn) {
        setStyleImportant(themeToggleBtn, 'color', isDark ? 'rgba(255,255,255,0.9)' : '#333');
      }
      if (themeToggleText) {
        setStyleImportant(themeToggleText, 'color', isDark ? 'rgba(255,255,255,0.9)' : '#333');
      }

      // 設置導航欄中的 lord-icon 顏色
      const lordIcons = document.querySelectorAll('lord-icon');
      lordIcons.forEach(function (icon) {
        if (isDark) {
          icon.setAttribute('colors', 'primary:#ffffff');
        } else {
          icon.setAttribute('colors', 'primary:#000000');
        }
      });
      console.log('已設置', lordIcons.length, '個 lord-icon 的顏色');

      // 設置導航欄中其他圖標的顏色（包括下拉菜單中的圖標）
      const navbarIcons = document.querySelectorAll('.navbar i, .navbar .fa-solid, .navbar .fas');
      navbarIcons.forEach(function (icon) {
        if (isDark) {
          setStyleImportant(icon, 'color', '#ffffff');
        } else {
          setStyleImportant(icon, 'color', '#333');
        }
      });
      console.log('已設置', navbarIcons.length, '個導航欄圖標的顏色');

      // 設置下拉菜單中的圖標顏色（除了角色切換按鈕外，其他圖標一律為黑色，不受深淺色模式影響）
      // 排除：角色切換按鈕、以及側邊欄的子選單（sidebar 子列表需隨主題切換顏色）
      const dropdownIcons = document.querySelectorAll('.dropdown-menu i, .dropdown-menu .fa-solid, .dropdown-menu .fas, .dropdown-menu .fa, .dropdown-item i, .dropdown-item .fa-solid, .dropdown-item .fas, .dropdown-submenu .dropdown-item i, .dropend .dropdown-item i, .dropend .dropdown-toggle i');
      dropdownIcons.forEach(function (icon) {
        if (icon.closest('.role-switch-btn')) return;
        // 跳過側邊欄子選單的圖標，讓 navLinks 的設定生效（隨主題切換）
        if (icon.closest('#layoutSidenav_nav, #sidenavAccordion, .sb-sidenav, .dropdown-submenu')) return;
        setStyleImportant(icon, 'color', '#000000');
      });
      console.log('已設置', dropdownIcons.length, '個下拉菜單圖標的顏色');

      // 特別處理"說明"菜單項中的問號圖標（一律為黑色）
      const helpIcons = document.querySelectorAll('.dropdown-item .fa-circle-question, .dropend .dropdown-toggle .fa-circle-question');
      helpIcons.forEach(function (icon) {
        // 下拉菜單背景是白色，所以圖標一律為黑色，不受主題影響
        setStyleImportant(icon, 'color', '#000000');
      });
      console.log('已設置', helpIcons.length, '個"說明"圖標的顏色');

      // 設置角色切換按鈕中的圖標顏色（排除 active 狀態的按鈕，因為 active 狀態的圖標應該是白色）
      // 注意：由於下拉菜單背景始終是白色，所以非 active 狀態的圖標應該始終是深色（紫色）
      const roleSwitchIcons = document.querySelectorAll('.role-switch-btn:not(.active) i:first-child');
      roleSwitchIcons.forEach(function (icon) {
        // 下拉菜單背景是白色，所以圖標應該是深色（紫色），無論深色還是淺色模式
        setStyleImportant(icon, 'color', '#667eea');
      });
      console.log('已設置', roleSwitchIcons.length, '個角色切換按鈕圖標的顏色');

      // 確保下拉菜單的背景永遠是白色
      const dropdownMenus = document.querySelectorAll('.navbar .dropdown-menu, .admin-navbar .dropdown-menu');
      dropdownMenus.forEach(function (menu) {
        setStyleImportant(menu, 'background', '#ffffff');
        setStyleImportant(menu, 'background-color', '#ffffff');
        setStyleImportant(menu, 'color', '#212529');
      });
      console.log('已設置', dropdownMenus.length, '個下拉菜單背景為白色');

      // 設置導航欄的背景和文字顏色（包括管理員和普通用戶）
      const navbar = document.querySelector('.navbar.admin-navbar, .navbar.navbar-light.bg-light');
      if (navbar) {
        if (isDark) {
          navbar.style.cssText += 'background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%) !important; background-color: #1a1a2e !important; color: #ffffff !important;';
        } else {
          const currentStyle = navbar.style.cssText || '';
          const cleanedStyle = currentStyle.replace(/background[^;]*;?/gi, '').replace(/color[^;]*;?/gi, '').trim();
          navbar.style.cssText = cleanedStyle + ' background-color: #ffffff !important; background: #ffffff !important; color: #333 !important;';
        }

        // 設置導航欄內元素的顏色（包括所有用戶類型）
        const navbarElements = navbar.querySelectorAll('.navbar-brand, #sidebarToggle, .btn-link, .form-control, .dropdown-toggle');
        navbarElements.forEach(function (el) {
          if (isDark) {
            setStyleImportant(el, 'color', '#ffffff');
          } else {
            setStyleImportant(el, 'color', '#333');
          }
        });

        // 設置表單輸入框
        const formControls = navbar.querySelectorAll('.form-control');
        formControls.forEach(function (control) {
          if (isDark) {
            setStyleImportant(control, 'background-color', 'rgba(255, 255, 255, 0.1)');
            setStyleImportant(control, 'border-color', 'rgba(255, 255, 255, 0.3)');
            setStyleImportant(control, 'color', '#ffffff');
          } else {
            setStyleImportant(control, 'background-color', 'rgba(0, 0, 0, 0.05)');
            setStyleImportant(control, 'border-color', 'rgba(0, 0, 0, 0.2)');
            setStyleImportant(control, 'color', '#333');
          }
        });

        // 設置按鈕樣式
        const buttons = navbar.querySelectorAll('.btn');
        buttons.forEach(function (btn) {
          if (isDark) {
            if (btn.classList.contains('btn-warning')) {
              // 保持警告按鈕的黃色
              setStyleImportant(btn, 'background', 'linear-gradient(135deg, #ffc107 0%, #ff9800 100%)');
              setStyleImportant(btn, 'color', '#000');
            } else if (btn.classList.contains('btn-secondary')) {
              setStyleImportant(btn, 'background-color', 'rgba(255, 255, 255, 0.2)');
              setStyleImportant(btn, 'border-color', 'rgba(255, 255, 255, 0.3)');
              setStyleImportant(btn, 'color', '#ffffff');
            }
          } else {
            if (btn.classList.contains('btn-secondary')) {
              setStyleImportant(btn, 'background-color', '#6c757d');
              setStyleImportant(btn, 'border-color', '#6c757d');
              setStyleImportant(btn, 'color', '#ffffff');
            }
          }
        });

        console.log('導航欄樣式已設置 - background:', navbar.style.background, 'color:', navbar.style.color);
      }
    }

    // 頁面切換時重新套用 active 連結樣式（避免 loadSubpage 設定的 active 被舊的 inline 覆蓋）
    document.addEventListener('page:loaded', function () {
      const body = document.body;
      const isDark = body.classList.contains(DARK_MODE_CLASS);
      const navLinks = document.querySelectorAll('.admin-sidebar .nav-link, .sb-sidenav-menu .nav-link');
      navLinks.forEach(function (link) {
        const isActive = link.classList.contains('active');
        if (isDark) {
          link.style.setProperty('color', isActive ? '#ffffff' : 'rgba(255, 255, 255, 0.85)', 'important');
          const icon = link.querySelector('i');
          if (icon) icon.style.setProperty('color', '#ffffff', 'important');
        } else {
          link.style.setProperty('color', isActive ? '#222' : '#333', 'important');
          const icon = link.querySelector('i');
          if (icon) icon.style.setProperty('color', isActive ? '#222' : '#333', 'important');
        }
      });
    });

    // 頁面載入時初始化
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initThemeToggle);
    } else {
      initThemeToggle();
    }

    // 如果側邊欄是動態載入的，也監聽 AJAX 載入完成事件
    document.addEventListener('ajaxComplete', initThemeToggle);
  })();
</script>

<div class="sb-sidenav-menu <?= $isAdmin ? 'admin-sidebar' : '' ?>">

  <?php if ($isAdmin): ?>

    <a class="nav-link ajax-link" href="pages/admin_usermanage.php">
      <i class="fa-solid fa-user-gear"></i><span>帳號管理</span>
    </a>

    <!-- 組別管理 -->
    <div class="dropdown-submenu">
      <a class="nav-link">
        <i class="fa-solid fa-users-rectangle"></i><span>組別管理</span>
      </a>
      <ul class="dropdown-menu">
        <li><a class="nav-link ajax-link" href="pages/team_manage.php">
            <i class="fa-solid fa-list"></i><span>組別列表</span>
          </a></li>

        <li> <a class="nav-link ajax-link" href="pages/team_change.php">
            <i class="fa-solid fa-clock-rotate-left"></i><span>組別異動紀錄</span>
          </a></li>

        <li> <a class="nav-link ajax-link" href="pages/team_apply_review.php">
            <i class="fa-solid fa-user-check"></i><span>專題指導申請審核</span>
          </a></li>
      </ul>
    </div>


    <!-- 專題相關 -->
    <!-- <div class="dropdown-submenu"> -->
      <!-- <a class="nav-link">
        <i class="fa-solid fa-graduation-cap"></i><span>歷屆資料管理</span>
      </a> -->
      <!-- <ul class="dropdown-menu"> -->
         <a class="nav-link ajax-link" href="pages/requirement.php">
            <i class="fa-solid fa-list-check"></i><span>最低專題要求</span>
          </a>
        <!--   <li><a class="nav-link ajax-link" href="pages/project_submission_review.php">
              <i class="fa-solid fa-clipboard-check"></i><span>專題提交審核</span>
            </a></li>
          <li><a class="nav-link ajax-link" href="pages/history_project.php">
              <i class="fa-solid fa-eye"></i><span>歷屆專題管理</span>
            </a></li>
          <li><a class="nav-link ajax-link" href="pages/history_project_file.php">
              <i class="fa-solid fa-folder-open"></i><span>歷屆成果管理</span>
            </a></li> -->
        <!-- <li><a class="nav-link ajax-link" href="pages/review_modify.php">
            <i class="fa-solid fa-clipboard-check"></i><span>審核修改申請</span>
          </a></li> -->
        <a class="nav-link ajax-link" href="pages/expected_show_all.php">
            <i class="fa-solid fa-chart-line"></i><span>預期成果評分總覽</span>
          </a>
        <a class="nav-link ajax-link" href="pages/project_browse.php">
            <i class="fa-solid fa-magnifying-glass"></i><span>歷屆專題瀏覽</span>
          </a>
      <!-- </ul> -->

    <!-- </div> -->

    <!-- <a class="nav-link ajax-link" href="pages/suggest.php">
        <i class="fa-solid fa-bullhorn"></i><span>專題報告建議</span>
      </a> -->

    <a class="nav-link ajax-link" href="pages/new.php">
      <i class="fa-solid fa-newspaper"></i><span>最新消息</span>
    </a>

    <!-- 科辦 系辦 顆半 -->
  <?php elseif ($role_ID == 2): ?>

    <a class="nav-link ajax-link" href="pages/team_timeline.php">
      <i class="fa-solid fa-timeline"></i><span>時間軸</span>
    </a>
    <div class="dropdown-submenu">
      <a class="nav-link">
        <i class="fa-solid fa-users-rectangle"></i><span>組別管理</span>
      </a>
      <ul class="dropdown-menu">
        <li><a class="nav-link ajax-link" href="pages/team_manage.php">
            <i class="fa-solid fa-list"></i><span>組別列表</span>
          </a></li>
        <li><a class="nav-link ajax-link" href="pages/team_change.php">
            <i class="fa-solid fa-clock-rotate-left"></i><span>組別異動紀錄</span>
          </a></li>
        <li><a class="nav-link ajax-link" href="pages/team_apply_review.php">
            <i class="fa-solid fa-user-check"></i><span>專題指導申請審核</span>
          </a></li>
        <!-- <li><a class="nav-link ajax-link" href="pages/team_apply_admin.php">
              <i class="fa-solid fa-sliders"></i><span>專題指導申請單設定</span>
            </a></li> -->
      </ul>
    </div>


    <!-- <a class="nav-link ajax-link" href="pages/schedule_manage.php">
        <i class="fa-solid fa-calendar-alt"></i><span>時程表管理</span>
      </a> -->
    <div class="dropdown-submenu">
      <a class="nav-link">
        <i class="fa-solid fa-database"></i><span>申請與繳交設定</span>
      </a>
      <ul class="dropdown-menu">
        <li><a class="nav-link ajax-link" href="pages/form_manage.php">
            <i class="fa-solid fa-table-list"></i><span>申請與收件管理</span>
          </a></li>
        <li><a class="nav-link ajax-link" href="pages/apply_preview_list.php">
            <i class="fa-solid fa-clipboard-check"></i><span>資料審核</span>
          </a></li>

        <li><a class="nav-link ajax-link" href="pages/submission_view.php">
            <i class="fa-solid fa-chart-line"></i><span>收件狀況</span>
          </a></li>

      </ul>
    </div>
    <div class="dropdown-submenu">
      <a class="nav-link">
        <i class="fa-solid fa-graduation-cap"></i><span>歷屆資料管理</span>
      </a>
      <ul class="dropdown-menu">

        <!-- <li><a class="nav-link ajax-link" href="pages/requirement.php">
              <i class="fa-solid fa-star"></i><span>進度追蹤</span>
            </a></li> -->
        <li><a class="nav-link ajax-link" href="pages/history_project.php">
            <i class="fa-solid fa-folder-open"></i><span>歷屆專題管理</span>
          </a></li>
        <li><a class="nav-link ajax-link" href="pages/project_submission_review.php">
            <i class="fa-solid fa-clipboard-check"></i><span>專題提交審核</span>
          </a></li>
        <li><a class="nav-link ajax-link" href="pages/history_project_file.php">
            <i class="fa-solid fa-archive"></i><span>歷屆成果管理</span>
          </a></li>
        <li><a class="nav-link ajax-link" href="pages/project_browse.php">
            <i class="fa-solid fa-magnifying-glass"></i><span>歷屆專題瀏覽</span>
          </a></li>
        <!-- <li><a class="nav-link ajax-link" href="pages/review_modify.php">
            <i class="fa-solid fa-clipboard-check"></i><span>審核修改申請</span>
          </a></li> -->
      </ul>
    </div>

    <!-- <a class="nav-link ajax-link" href="pages/suggest.php">
        <i class="fa-solid fa-bullhorn"></i><span>專題報告建議</span>
      </a> -->
    <a class="nav-link ajax-link" href="pages/integrate.php">
      <i class="fa-solid fa-calendar-days"></i><span>建議表&時程表</span>
    </a>
    <a class="nav-link ajax-link" href="pages/requirement.php">
      <i class="fa-solid fa-list-check"></i><span>最低專題要求</span>
    </a>
    <a class="nav-link ajax-link" href="pages/expected_show_all.php">
      <i class="fa-solid fa-chart-line"></i><span>預期成果評分總覽</span>
    </a>
    <a class="nav-link ajax-link" href="pages/new.php">
      <i class="fa-solid fa-newspaper"></i><span>最新消息</span>
    </a>



    <!-- 6=學生 -->
  <?php elseif ($role_ID == 6): ?>
    <a class="nav-link ajax-link" href="pages/team_timeline.php">
      <i class="fa-solid fa-timeline"></i><span>時間軸</span>
    </a>
    <a class="nav-link ajax-link" href="pages/expected_outcome.php">
      <i class="fa-solid fa-chart-line"></i><span>預期成果</span>
    </a>
    <a class="nav-link ajax-link" href="pages/meeting_list.php">
      <i class="fa-solid fa-users"></i><span>會議紀錄</span>
    </a>
    <!-- <a class="nav-link ajax-link" href="pages/apply.php">
      <i class="fa-solid fa-file"></i><span>申請文件上傳</span>
    </a> -->
    <a class="nav-link ajax-link" href="pages/apply_test.php">
      <i class="fa-solid fa-file-import"></i><span>資料申請</span>
    </a>
    <!-- <a class="nav-link ajax-link" href="pages/student_review.php">
        <i class="fa-solid fa-star-half-stroke"></i><span>互評</span>
      </a> -->
    <a class="nav-link ajax-link" href="pages/work_form.php">
      <i class="fa-solid fa-book"></i><span>工作日誌</span>
    </a>

    <a class="nav-link ajax-link" href="pages/S_requirement_mailes_task.php">
      <i class="fa-solid fa-bullseye"></i><span>待辦事項</span>
    </a>
    <!-- 歷屆專題相關 -->


    <a class="nav-link ajax-link" href="pages/team_change.php">
      <i class="fa-solid fa-clock-rotate-left"></i><span>組別異動</span>
    </a>
    <a class="nav-link ajax-link" href="pages/new.php">
      <i class="fa-solid fa-newspaper"></i><span>最新消息</span>
    </a>
    <div class="dropdown-submenu">
      <a class="nav-link">
        <i class="fa-solid fa-graduation-cap"></i><span>歷屆資料管理</span>
      </a>
      <ul class="dropdown-menu">
        <li><a class="nav-link ajax-link" href="pages/project_upload.php">
            <i class="fa-solid fa-cloud-arrow-up"></i><span>上傳專題資料</span>
          </a></li>
        <li><a class="nav-link ajax-link" href="pages/project_browse.php">
            <i class="fa-solid fa-magnifying-glass"></i><span>歷屆專題瀏覽</span>
          </a></li>
      </ul>
    </div>
    <!-- 4=指導老師 -->
  <?php elseif ($role_ID == 4): ?>

    <a class="nav-link ajax-link" href="pages/team_timeline.php">
      <i class="fa-solid fa-timeline"></i><span>時間軸</span>
    </a>

    <!-- 互評管理 -->
    <!-- <div class="dropdown-submenu">
        <a class="nav-link">
          <i class="fa-solid fa-star"></i><span>互評管理</span>
        </a>
        <ul class="dropdown-menu">
          <li><a class="nav-link ajax-link" href="pages/checkreviewperiods.php">
              <i class="fa-solid fa-eye"></i><span>評分時段管理</span>
            </a></li>
          <li><a class="nav-link ajax-link" href="pages/teacher_review_status.php">
              <i class="fa-solid fa-file-pen"></i><span>互評結果查看</span>
            </a></li>
        </ul>
      </div> -->
    <a class="nav-link ajax-link" href="pages/meeting.php">
      <i class="fa-solid fa-users"></i><span>會議紀錄</span>
    </a>
    <a class="nav-link ajax-link" href="pages/teacher_online_scoring.php">
      <i class="fa-solid fa-comments"></i><span>審查建議</span>
    </a>
    <a class="nav-link ajax-link" href="pages/expected_teacher.php">
      <i class="fa-solid fa-chart-line"></i><span>預期成果</span>
    </a>
    <a class="nav-link ajax-link" href="pages/T_requirement_mailes_task.php">
      <i class="fa-solid fa-bullseye"></i><span>待辦事項</span>
    </a>
    <a class="nav-link ajax-link" href="pages/work_draft.php">
      <i class="fa-solid fa-book-open"></i><span>查看工作日誌</span>
    </a>
    <a class="nav-link ajax-link" href="pages/apply_preview_teacher_list.php">
      <i class="fa-solid fa-clipboard-check"></i><span>資料審核</span>
    </a>
    <a class="nav-link ajax-link" href="pages/submission_view.php">
      <i class="fa-solid fa-clipboard-list"></i><span>資料繳交狀況</span>
    </a>
    <!-- 暫時移除：查看團隊歷屆繳交 -->
    <!-- <a class="nav-link ajax-link" href="pages/teacher_project_submissions.php">
        <i class="fa-solid fa-file-alt"></i><span>查看團隊歷屆繳交</span>
      </a> -->

    <a class="nav-link ajax-link" href="pages/team_change.php">
      <i class="fa-solid fa-clock-rotate-left"></i><span>組別異動紀錄</span>
    </a>

    <a class="nav-link ajax-link" href="pages/project_browse.php">
      <i class="fa-solid fa-magnifying-glass"></i><span>歷屆專題瀏覽</span>
    </a>

    <a class="nav-link ajax-link" href="pages/new.php">
      <i class="fa-solid fa-newspaper"></i><span>最新消息</span>
    </a>
    <!-- 3=班導 -->
  <?php elseif ($role_ID == 3): ?>

    <a class="nav-link ajax-link" href="pages/team_timeline.php">
      <i class="fa-solid fa-timeline"></i><span>時間軸</span>
    </a>

    <!-- 互評管理 -->
    <!-- <div class="dropdown-submenu">
        <a class="nav-link">
          <i class="fa-solid fa-star"></i><span>互評管理</span>
        </a>
        <ul class="dropdown-menu">
          <li><a class="nav-link ajax-link" href="pages/checkreviewperiods.php">
              <i class="fa-solid fa-eye"></i><span>評分時段管理</span>
            </a></li>
          <li><a class="nav-link ajax-link" href="pages/teacher_review_status.php">
              <i class="fa-solid fa-file-pen"></i><span>互評結果查看</span>
            </a></li>
        </ul>
      </div> -->

    <a class="nav-link ajax-link" href="pages/submission_view.php">
      <i class="fa-solid fa-clipboard-list"></i><span>資料繳交狀況</span>
    </a>
    <!-- 暫時移除：查看班級歷屆繳交 -->
    <!-- <a class="nav-link ajax-link" href="pages/class_teacher_project_submissions.php">
        <i class="fa-solid fa-file-alt"></i><span>查看班級歷屆繳交</span>
      </a> -->

    <a class="nav-link ajax-link" href="pages/project_browse.php">
      <i class="fa-solid fa-magnifying-glass"></i><span>歷屆專題瀏覽</span>
    </a>
    <a class="nav-link ajax-link" href="pages/expected_show_all.php">
      <i class="fa-solid fa-chart-line"></i><span>預期成果評分總覽</span>
    </a>
    <a class="nav-link ajax-link" href="pages/new.php">
      <i class="fa-solid fa-newspaper"></i><span>最新消息</span>
    </a>
    <!-- 2=科辦 -->
  <?php elseif ($role_ID == 2): ?>

    <a class="nav-link ajax-link" href="pages/team_timeline.php">
      <i class="fa-solid fa-timeline"></i><span>時間軸</span>
    </a>
    <a class="nav-link ajax-link" href="pages/office.php">
      <i class="fa-solid fa-building-columns"></i><span>科辦管理</span>
    </a>

    <!-- 審核管理 -->
    <div class="dropdown-submenu">
      <a class="nav-link">
        <i class="fa-solid fa-scale-balanced"></i><span>審核管理</span>
      </a>
      <ul class="dropdown-menu">
        <li><a class="nav-link ajax-link" href="pages/team_apply_review.php">
            <i class="fa-solid fa-user-check"></i><span>專題指導申請審核</span>
          </a></li>
        <li><a class="nav-link ajax-link" href="pages/team_change.php">
            <i class="fa-solid fa-clock-rotate-left"></i><span>組別異動紀錄</span>
          </a></li>
      </ul>
    </div>

    <a class="nav-link ajax-link" href="pages/schedule_manage.php">
      <i class="fa-solid fa-calendar-days"></i><span>時程表管理</span>
    </a>

    <!-- 專題相關 -->
    <div class="dropdown-submenu">
      <a class="nav-link">
        <i class="fa-solid fa-graduation-cap"></i><span>歷屆資料管理</span>
      </a>
      <ul class="dropdown-menu">
        <!-- <li><a class="nav-link ajax-link" href="pages/requirement.php">
              <i class="fa-solid fa-star"></i><span>進度追蹤</span>
            </a></li> -->
        <li><a class="nav-link ajax-link" href="pages/history_project.php">
            <i class="fa-solid fa-folder-open"></i><span>歷屆專題管理</span>
          </a></li>
        <li><a class="nav-link ajax-link" href="pages/project_submission_review.php">
            <i class="fa-solid fa-clipboard-check"></i><span>專題提交審核</span>
          </a></li>
        <li><a class="nav-link ajax-link" href="pages/history_project_file.php">
            <i class="fa-solid fa-archive"></i><span>歷屆成果管理</span>
          </a></li>
        <li><a class="nav-link ajax-link" href="pages/project_browse.php">
            <i class="fa-solid fa-magnifying-glass"></i><span>歷屆專題瀏覽</span>
          </a></li>
        <li><a class="nav-link ajax-link" href="pages/expected_show_all.php">
            <i class="fa-solid fa-chart-line"></i><span>預期成果評分總覽</span>
          </a></li>
      </ul>
    </div>

    <!-- 文件與表單 -->
    <div class="dropdown-submenu">
      <a class="nav-link">
        <i class="fa-solid fa-database"></i><span>資料</span>
      </a>
      <ul class="dropdown-menu">
        <a class="nav-link ajax-link" href="pages/submission_view.php">
          <i class="fa-solid fa-clipboard-list"></i><span>資料繳交狀況</span>
        </a></li>
        <li><a class="nav-link ajax-link" href="pages/admin_form_manage.php">
            <i class="fa-solid fa-file-lines"></i><span>表單管理</span>
          </a></li>
        <!-- <li><a class="nav-link ajax-link" href="pages/admin_form_flow_manage.php">
              <i class="fa-solid fa-sitemap"></i><span>表單流程管理</span>
            </a></li> -->
        <li><a class="nav-link ajax-link" href="pages/form_review.php">
            <i class="fa-solid fa-clipboard-check"></i><span>表單審核</span>
          </a></li>

      </ul>
    </div>

    <a class="nav-link ajax-link" href="pages/suggest.php">
      <i class="fa-solid fa-lightbulb"></i><span>專題報告建議</span>
    </a>
    <a class="nav-link ajax-link" href="pages/work_draft.php">
      <i class="fa-solid fa-book-open"></i><span>工作日誌查看</span>
    </a>

    <a class="nav-link ajax-link" href="pages/new.php">
      <i class="fa-solid fa-newspaper"></i><span>最新消息</span>
    </a>
    <!-- 7=召集人 -->
  <?php elseif ($role_ID == 7): ?>

    <a class="nav-link ajax-link" href="pages/team_timeline.php">
      <i class="fa-solid fa-timeline"></i><span>時間軸</span>
    </a>

    <a class="nav-link ajax-link" href="pages/integrate.php">
      <i class="fa-solid fa-file-lines"></i><span>建議表</span>
    </a>
    <a class="nav-link ajax-link" href="pages/expected_show_all.php">
      <i class="fa-solid fa-chart-line"></i><span>預期成果評分總覽</span>
    </a>
    <a class="nav-link ajax-link" href="pages/project_browse.php">
      <i class="fa-solid fa-magnifying-glass"></i><span>歷屆專題瀏覽</span>
    </a>
    <a class="nav-link ajax-link" href="pages/new.php">
      <i class="fa-solid fa-newspaper"></i><span>最新消息</span>
    </a>
  <?php endif; ?>

  <!-- 深色/浅色模式切换按钮 -->
  <div class="sidebar-theme-toggle">
    <button id="sidebarThemeToggle" class="theme-toggle-btn" title="切換深色/淺色模式">
      <i class="fa-solid fa-moon" id="themeIcon"></i>
      <span class="theme-toggle-text">深色模式</span>
    </button>
  </div>

</div>