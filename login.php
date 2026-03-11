<!DOCTYPE html>

<html lang="zh-Hant">
<?php
session_start();
$_SESSION = [];
?>

<head name="app-base" content="/myprojecteverydaysforlasttest/">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>專題總彙 - 登入</title>

  <?php include "head.php"; ?>
  <link rel="stylesheet" href="css/login.css?v=<?= time() ?>" />
  <style>
    /* 登入表單區域 */
    .login-content {
      position: relative;
      width: 100%;
      min-height: auto;
      display: flex !important;
      align-items: center;
      justify-content: center;
      padding: 2rem;
      z-index: 10;
    }

    /* 登入表單樣式 */
    .login-form-wrapper {
      width: 100%;
      max-width: 420px;
      margin: 0 auto;
    }

    .login-form-inline {
      background: rgba(255, 255, 255, 0.08);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .login-form-inline::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
      transition: left 0.5s;
    }

    .login-form-inline:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 40px rgba(74, 144, 226, 0.3);
      border-color: rgba(74, 144, 226, 0.4);
    }

    .login-form-inline:hover::before {
      left: 100%;
    }

    .login-form-inline .title {
      text-align: center;
      font-size: 1.75rem;
      margin-bottom: 1.25rem;
      color: #fff;
      letter-spacing: 0.12em;
    }

    .back-to-home {
      text-align: center;
      margin-top: 1.5rem;
    }

    .back-to-home-btn {
      color: rgba(255, 255, 255, 0.8);
      text-decoration: none;
      font-size: 0.9rem;
      transition: color 0.3s;
    }

    .back-to-home-btn:hover {
      color: #93c5fd;
      text-decoration: underline;
    }

    /* 表單元素的依次出現動畫 */
    .login-form-inline .title {
      animation: fadeInDown 0.5s ease-out 0.3s both;
    }

    .login-form-inline .input-group {
      animation: fadeInUp 0.5s ease-out both;
    }

    .login-form-inline .input-group:nth-child(2) {
      animation-delay: 0.4s;
    }

    .login-form-inline .input-group:nth-child(3) {
      animation-delay: 0.5s;
    }

    .login-form-inline .submit-btn {
      animation: fadeInUp 0.5s ease-out 0.6s both;
    }

    .login-form-inline .forgot {
      animation: fadeIn 0.5s ease-out 0.7s both;
    }

    .login-form-inline .back-to-home {
      animation: fadeIn 0.5s ease-out 0.8s both;
    }

    @keyframes fadeInDown {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
      }

      to {
        opacity: 1;
      }
    }
  </style>
</head>

<body style="background: transparent; margin: 0; padding: 0; overflow: hidden;">
  <div id="loginApp">
    <div class="login-content">
      <div class="login-form-wrapper">
        <form class="login-form-inline" @submit.prevent="loginSubmit">
          <h1 class="title">專題日總彙</h1>

          <div class="input-group">
            <input id="acc" v-model.trim="login.acc" type="text" inputmode="text"
              autocomplete="off" placeholder="請輸入帳號" @keyup.enter="focusPassword" />
          </div>

          <div class="input-group" v-if="hasAccount">
            <input :type="showPassword ? 'text':'password'"
              id="pas" v-model.trim="login.pas" autocomplete="off"
              placeholder="請輸入密碼" @keyup.enter="loginSubmit" />
            <i v-if="login.pas"
              class="fa-solid toggle-eye"
              :class="showPassword ? 'fa-eye-slash' : 'fa-eye'"
              @click="showPassword = !showPassword"></i>
          </div>

          <button class="btn btn-info submit-btn" type="submit" :disabled="loading">
            {{ loading ? '登入中…' : '登入' }}
          </button>

          <p v-if="error" class="error">{{ error }}</p>
          <p class="forgot">忘記密碼？ <a href="#" @click.prevent="openForgot">重設</a></p>

          <div class="back-to-home">
            <a href="#" class="back-to-home-btn" @click.prevent="closeLogin">
              <i class="fa-solid fa-arrow-left me-2"></i>返回首頁
            </a>
          </div>
        </form>
      </div>
    </div>

    <!-- 忘記密碼 Modal -->
    <div class="modal fade" id="forgotModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-body text-center">
            <h5 class="mb-3">忘記密碼</h5>
            <input v-model.trim="forgotAccount" type="text" class="form-control mb-3" placeholder="請輸入帳號">
            <div class="d-grid gap-2">
              <button class="btn btn-info" @click="sendForgot">送出</button>
              <button class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- <script src="js/login.js"></script> -->
  <script>
    // 確保 Vue 已載入
    let vueRetryCount = 0;
    const MAX_VUE_RETRIES = 50; // 最多重試 50 次（5 秒）

    function initLoginApp() {
      // 檢查是否已標記為載入失敗
      if (window.VueLoadFailed) {
        console.error('Vue.js 載入失敗，請檢查網路連線或 CDN 是否可訪問');
        if (document.getElementById('loginApp')) {
          document.getElementById('loginApp').innerHTML =
            '<div style="text-align: center; padding: 50px; color: #fff;">' +
            '<h2>載入失敗</h2>' +
            '<p>無法載入必要的資源，請重新整理頁面</p>' +
            '<button onclick="location.reload()" style="padding: 10px 20px; margin-top: 20px; cursor: pointer;">重新載入</button>' +
            '</div>';
        }
        return;
      }

      if (typeof Vue === 'undefined') {
        vueRetryCount++;
        if (vueRetryCount < MAX_VUE_RETRIES) {
          setTimeout(initLoginApp, 100);
        } else {
          console.error('Vue.js 載入超時，請檢查網路連線或 CDN 是否可訪問');
          // 顯示用戶友好的錯誤訊息
          if (document.getElementById('loginApp')) {
            document.getElementById('loginApp').innerHTML =
              '<div style="text-align: center; padding: 50px; color: #fff;">' +
              '<h2>載入失敗</h2>' +
              '<p>無法載入必要的資源，請重新整理頁面</p>' +
              '<button onclick="location.reload()" style="padding: 10px 20px; margin-top: 20px; cursor: pointer;">重新載入</button>' +
              '</div>';
          }
        }
        return;
      }

      const {
        createApp,
        ref,
        reactive,
        computed,
        onMounted
      } = Vue;

      createApp({
        setup() {
          const loading = ref(false);
          const error = ref('');
          const showPassword = ref(false);
          const login = reactive({
            acc: '',
            pas: ''
          });
          const hasAccount = computed(() => !!login.acc);
          const forgotAccount = ref('');
          let modalForgot = null;

          onMounted(() => {
            const el = document.getElementById('forgotModal');
            if (window.bootstrap && el) {
              modalForgot = new bootstrap.Modal(el);
            }

            // 通知父頁面調整 iframe 高度
            const notifyHeight = () => {
              if (window.parent && window.parent.postMessage) {
                const height = document.documentElement.scrollHeight || document.body.scrollHeight;
                window.parent.postMessage({
                  type: 'resizeIframe',
                  height: height
                }, '*');
              }
            };

            // 初始通知
            setTimeout(notifyHeight, 100);

            // 監聽內容變化
            if (document.body && document.body instanceof Node) {
              try {
                const observer = new MutationObserver(notifyHeight);
                observer.observe(document.body, {
                  childList: true,
                  subtree: true,
                  attributes: true
                });
              } catch (e) {
                console.warn('MutationObserver failed:', e);
              }
            } else {
              // 如果 body 還不存在，稍後再試
              setTimeout(() => {
                if (document.body && document.body instanceof Node) {
                  try {
                    const observer = new MutationObserver(notifyHeight);
                    observer.observe(document.body, {
                      childList: true,
                      subtree: true,
                      attributes: true
                    });
                  } catch (e) {
                    console.warn('MutationObserver failed:', e);
                  }
                }
              }, 100);
            }
          });

          const focusAccount = () => document.getElementById('acc')?.focus();
          const focusPassword = () => document.getElementById('pas')?.focus();

          const openForgot = () => {
            forgotAccount.value = login.acc || '';
            modalForgot?.show();
          };

          const closeLogin = () => {
            // 通知父頁面關閉登入表單
            if (window.parent && window.parent.postMessage) {
              window.parent.postMessage({
                type: 'closeLogin'
              }, '*');
            }
          };

          const sendForgot = async () => {
            const acc = forgotAccount.value.trim();
            if (!acc) {
              setError('請先輸入帳號再送出。');
              return;
            }
            try {
              clearError();
              loading.value = true;
              const res = await fetch('forgot_password2.php', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: new URLSearchParams({
                  account: acc,
                  ajax: '1'
                })
              });

              const data = await res.json().catch(() => null);
              if (!res.ok || !data) {
                throw new Error(data?.message || '伺服器回應格式錯誤');
              }

              if (data.success) {
                modalForgot?.hide();
                notify('已送出', data.message || '請至註冊信箱確認郵件。', 'success');
              } else {
                setError(data.message || '寄送失敗，請稍後再試。');
              }
            } catch (e) {
              setError('送出失敗：' + (e.message || '請稍後再試。'));
            } finally {
              loading.value = false;
            }
          };
     const loginSubmit = async () => {
          clearError();
          if (!login.acc) {
            setError('請先輸入帳號');
            focusAccount();
            return;
          }
          if (!login.pas) {
            setError('請輸入密碼');
            focusPassword();
            return;
          }

          loading.value = true;
          try {
            const res = await fetch('api.php?do=login_sub', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
              body: new URLSearchParams({ acc: login.acc, pas: login.pas })
            });
            const data = await res.json();

            if (!res.ok || !data.ok) {
              if (data.code === 'ACCOUNT_NOT_FOUND') focusAccount();
              if (data.code === 'WRONG_PASSWORD') focusPassword();
              return setError(data.msg || '登入失敗');
            }

            // 如果有多個角色，跳轉到角色選擇頁面
            if (data.code === 'MULTI_ROLE') {
              window.top.location.href = 'pages/role_select.php';
              return;
            }

            // 登入成功，根據角色決定跳轉頁面
            const role_ID = parseInt(data.role_ID, 10) || data.role_ID;
            let redirectUrl = 'main.php';
            
            if (role_ID == 3 || role_ID === 3 || data.role_ID == 3 || data.role_ID === '3') {
              redirectUrl = 'main.php#pages/class.php';
            } else if (role_ID == 6 || role_ID === 6 || data.role_ID == 6 || data.role_ID === '6') {
              redirectUrl = 'main.php#pages/student.php';
            } else if (role_ID == 4 || role_ID === 4 || data.role_ID == 4 || data.role_ID === '4') {
              redirectUrl = 'main.php#pages/teamteacher.php';
            } else {
              redirectUrl = 'main.php#pages/new.php';
            }
            
            window.top.location.href = redirectUrl;
          } catch (e) {
            setError('伺服器錯誤，請稍後再試');
          } finally {
            loading.value = false;
          }
        };

          // const USE_RENDER_API = true;
          // const API_BASE = "https://projecteveryday-api.onrender.com";
          // const API_KEY = "pe_demo_2026_key";

          // const loginSubmit = async () => {
          //   clearError();

          //   if (!login.acc) {
          //     setError('請先輸入帳號');
          //     focusAccount();
          //     return;
          //   }
          //   if (!login.pas) {
          //     setError('請輸入密碼');
          //     focusPassword();
          //     return;
          //   }

          //   loading.value = true;

          //   try {
          //     let data = null;

          //     if (USE_RENDER_API) {
          //       const res = await fetch(`${API_BASE}/auth/login`, {
          //         method: 'POST',
          //         headers: {
          //           'Content-Type': 'application/json',
          //           'x-api-key': API_KEY,
          //         },
          //         body: JSON.stringify({
          //           account: login.acc,
          //           password: login.pas
          //         })
          //       });

          //       data = await res.json().catch(() => null);

          //       if (!res.ok || !data || !data.ok) {
          //         return setError(data?.msg || '登入失敗（Render API）');
          //       }

          //       // ✅ 存 token / user
          //       if (data.token) localStorage.setItem('pe_token', data.token);
          //       if (data.user) localStorage.setItem('pe_user', JSON.stringify(data.user));

          //       // ✅ 導頁（依 role）
          //       const role_ID = parseInt(data?.user?.role_ID, 10);

          //       let redirectUrl = 'main.php#pages/new.php';
          //       if (role_ID === 3) redirectUrl = 'main.php#pages/class.php';
          //       else if (role_ID === 6) redirectUrl = 'main.php#pages/student.php';
          //       else if (role_ID === 4) redirectUrl = 'main.php#pages/teamteacher.php';

          //       // 用 top：因為你是 iframe
          //       window.top.location.href = redirectUrl;
          //       return;
          //     }

          //     // ===== 如果要切回 PHP 才會走這裡 =====
          //     const res = await fetch('api.php?do=login_sub', {
          //       method: 'POST',
          //       headers: {
          //         'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
          //       },
          //       body: new URLSearchParams({
          //         acc: login.acc,
          //         pas: login.pas
          //       })
          //     });
          //     data = await res.json().catch(() => null);

          //     if (!res.ok || !data || !data.ok) return setError(data?.msg || '登入失敗（PHP API）');

          //     // PHP 登入成功導頁
          //     const role_ID = parseInt(data?.role_ID, 10);
          //     let redirectUrl = 'main.php#pages/new.php';
          //     if (role_ID === 3) redirectUrl = 'main.php#pages/class.php';
          //     else if (role_ID === 6) redirectUrl = 'main.php#pages/student.php';
          //     else if (role_ID === 4) redirectUrl = 'main.php#pages/teamteacher.php';

          //     window.top.location.href = redirectUrl;

          //   } catch (e) {
          //     console.error(e);
          //     setError('伺服器錯誤，請稍後再試');
          //   } finally {
          //     loading.value = false;
          //   }
          // };

          // 小工具
          const setError = (msg) => {
            error.value = msg;
            if (!window.Swal) return;
            Swal.fire('提醒', msg, 'warning');
          };
          const clearError = () => error.value = '';
          const notify = (title, text, icon = 'info') => {
            if (window.Swal) Swal.fire(title, text, icon);
            else alert(`${title}\n${text}`);
          };

          return {
            loading,
            error,
            showPassword,
            login,
            hasAccount,
            forgotAccount,
            focusAccount,
            focusPassword,
            openForgot,
            sendForgot,
            loginSubmit,
            closeLogin
          };
        }
      }).mount('#loginApp');
    }

    // 當 DOM 載入完成後初始化 Vue
    // Vue.js 應該已經在 head.php 中同步載入
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function() {
        // 給 Vue.js 一點時間載入
        setTimeout(initLoginApp, 50);
      });
    } else {
      // DOM 已經載入完成，給 Vue.js 一點時間載入
      setTimeout(initLoginApp, 50);
    }
  </script>
</body>

</html>