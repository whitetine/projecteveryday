// js/login.js
// 需求：head.php 應該已載入 Vue 3 (global) 與 Bootstrap 5 JS；Font Awesome/SweetAlert2 可選
const { createApp, ref, reactive, computed, onMounted } = Vue;

const tpl = `
  <div class="login-container">
    <!-- 額外波浪 + 粒子層（與 techbg-host 疊加） -->
    <div class="fx-background">
      <div class="wave wave1"></div>
      <div class="wave wave2"></div>
      <div class="wave wave3"></div>
      <div class="particles">
        <div class="particle"
             v-for="i in 24" :key="i"
             :style="{ left: (Math.random()*100)+'%', animationDelay: (Math.random()*5)+'s' }"></div>
      </div>
    </div>

    <div class="form-overlay">
      <Transition name="fade-slide">
        <form v-if="showForm" class="login-form" @submit.prevent="loginSubmit">
          <h1 class="title">專題日總彙</h1>

          <div class="input-group">
            <!-- <label for="acc">帳號</label> -->
            <input id="acc" v-model.trim="login.acc" type="text" inputmode="text"
                   autocomplete="off" placeholder="請輸入帳號" @keyup.enter="focusPassword" />
          </div>

          <div class="input-group" v-if="hasAccount">
            <!-- <label for="pas">密碼</label> -->
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
        </form>
      </Transition>
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
`;

createApp({
  template: tpl,
  setup(){
    const showForm = ref(true);
    const loading  = ref(false);
    const error    = ref('');
    const showPassword = ref(false);

    const login = reactive({ acc:'', pas:'' });
    const hasAccount = computed(()=> !!login.acc);

    const forgotAccount = ref('');
    let modalForgot = null;

    onMounted(()=>{
      const el = document.getElementById('forgotModal');
      if (window.bootstrap && el) modalForgot = new bootstrap.Modal(el);
      
      // 每次進入頁面時清空帳號和密碼
      login.acc = '';
      login.pas = '';
      showPassword.value = false;
      error.value = '';
      
      // 進頁面時把焦點放在帳號，並確保清空
      queueMicrotask(()=> {
        const accInput = document.getElementById('acc');
        const pasInput = document.getElementById('pas');
        
        if (accInput) {
          accInput.value = ''; // 確保 DOM 元素也被清空
          accInput.focus();
        }
        if (pasInput) {
          pasInput.value = ''; // 確保密碼欄位也被清空
        }
      });
      
      // 延遲清空，防止瀏覽器自動填充覆蓋
      setTimeout(() => {
        login.acc = '';
        login.pas = '';
        const accInput = document.getElementById('acc');
        const pasInput = document.getElementById('pas');
        if (accInput) accInput.value = '';
        if (pasInput) pasInput.value = '';
      }, 100);
    });

    const focusAccount = ()=> document.getElementById('acc')?.focus();
    const focusPassword = ()=> document.getElementById('pas')?.focus();

    const openForgot = ()=>{
      forgotAccount.value = login.acc || '';
      modalForgot?.show();
    };

    const sendForgot = async ()=>{
      const acc = forgotAccount.value.trim();
      if (!acc) {
        setError('請先輸入帳號再送出。');
        return;
      }
      try{
        clearError();
        loading.value = true;
        const res = await fetch('forgot_password2.php', {
          method: 'POST',
          headers: { 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' },
          body: new URLSearchParams({ account: acc, ajax: '1' })
        });

        const data = await res.json().catch(()=>null);
        if (!res.ok || !data) {
          throw new Error(data?.message || '伺服器回應格式錯誤');
        }

        if (data.success) {
          modalForgot?.hide();
          notify('已送出', data.message || '請至註冊信箱確認郵件。', 'success');
        } else {
          setError(data.message || '寄送失敗，請稍後再試。');
        }
      }catch(e){
        setError('送出失敗：' + (e.message || '請稍後再試。'));
      }finally{
        loading.value = false;
      }
    };
// ===== API 切換設定（PHP / Render）=====
const USE_RENDER_API = true; // 想用PHP就改 false

const API_BASE = "https://projecteveryday-api.onrender.com"; 
const API_KEY  = "pe_demo_2026_key"; 

// const loginSubmit = async ()=>{
//   clearError();
//   if (!login.acc) { setError('請先輸入帳號'); focusAccount(); return; }
//   if (!login.pas) { setError('請輸入密碼'); focusPassword(); return; }

//   loading.value = true;
//   try{
//     const res = await fetch('api.php?do=login_sub', {
//       method:'POST',
//       headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' },
//       body: new URLSearchParams({ acc: login.acc, pas: login.pas })
//     });
//     const data = await res.json();

//     if (!res.ok || !data.ok) {
//       if (data.code === 'ACCOUNT_NOT_FOUND') focusAccount();
//       if (data.code === 'WRONG_PASSWORD') focusPassword();
//       return setError(data.msg || '登入失敗');
//     }

//     // 如果有多個角色，跳轉到角色選擇頁面
//     if (data.code === 'MULTI_ROLE') {
//       location.href = 'pages/role_select.php';
//       return;
//     }

//     // 登入成功，根據角色決定跳轉頁面
//     // 確保 role_ID 是數字類型進行比較
//     const role_ID = parseInt(data.role_ID, 10) || data.role_ID;
//     let redirectUrl = 'main.php';
    
//     // 班導師（role_ID = 3）跳轉到班級團隊管理頁面
//     if (role_ID == 3 || role_ID === 3 || data.role_ID == 3 || data.role_ID === '3') {
//       redirectUrl = 'main.php#pages/class.php';
//     }
//     // 學生角色（role_ID = 6）跳轉到團隊成員頁面
//     else if (role_ID == 6 || role_ID === 6 || data.role_ID == 6 || data.role_ID === '6') {
//       redirectUrl = 'main.php#pages/student.php';
//     }
//      // 指導老師角色（role_ID = 4）跳轉到指導團隊頁面
//     else if (role_ID == 4 || role_ID === 4 || data.role_ID == 4 || data.role_ID === '4') {
//       redirectUrl = 'main.php#pages/teamteacher.php';

//       } else {
//       // 其他角色跳轉到預設頁面
//       redirectUrl = 'main.php#pages/new.php';
//     }
    
//     location.href = redirectUrl;
//   }catch(e){
//     setError('伺服器錯誤，請稍後再試');
//     // setError("此為未註冊帳號，請重新輸入");
//   }finally{
//     loading.value = false;
//   }
// };

    // 小工具
   
   const loginSubmit = async ()=>{
  clearError();
  if (!login.acc) { setError('請先輸入帳號'); focusAccount(); return; }
  if (!login.pas) { setError('請輸入密碼'); focusPassword(); return; }

  loading.value = true;

  try{
    let data = null;

    if (USE_RENDER_API) {
      // ===== Render / Node API =====
      const res = await fetch(`${API_BASE}/auth/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-api-key': API_KEY,
        },
        body: JSON.stringify({ account: login.acc, password: login.pas })
      });

      data = await res.json().catch(()=>null);

      if (!res.ok || !data || !data.ok) {
        // Render 版錯誤訊息通常在 data.msg
        if (data?.msg) return setError(data.msg);
        return setError('登入失敗（Render API）');
      }

      // ✅ 登入成功：存 token（之後打 /users/me 會用到）
      if (data.token) localStorage.setItem('pe_token', data.token);
      if (data.user)  localStorage.setItem('pe_user', JSON.stringify(data.user));

    } else {
      // ===== 既有 PHP API =====
      const res = await fetch('api.php?do=login_sub', {
        method:'POST',
        headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams({ acc: login.acc, pas: login.pas })
      });

      data = await res.json().catch(()=>null);

      if (!res.ok || !data || !data.ok) {
        if (data?.code === 'ACCOUNT_NOT_FOUND') focusAccount();
        if (data?.code === 'WRONG_PASSWORD') focusPassword();
        return setError(data?.msg || '登入失敗（PHP API）');
      }

      // PHP 的 MULTI_ROLE 邏輯保留
      if (data.code === 'MULTI_ROLE') {
        location.href = 'pages/role_select.php';
        return;
      }
    }

    // ===== 共用導頁邏輯（吃 PHP 或 Render 的 role_ID）=====
    const roleFromRender = data?.user?.role_ID;
    const roleFromPhp    = data?.role_ID;
    const role_ID = parseInt(roleFromRender ?? roleFromPhp, 10);

    let redirectUrl = 'main.php';

    if (role_ID === 3) redirectUrl = 'main.php#pages/class.php';
    else if (role_ID === 6) redirectUrl = 'main.php#pages/student.php';
    else if (role_ID === 4) redirectUrl = 'main.php#pages/teamteacher.php';
    else redirectUrl = 'main.php#pages/new.php';

    location.href = redirectUrl;

  }catch(e){
    console.error(e);
    setError('伺服器錯誤，請稍後再試');
  }finally{
    loading.value = false;
  }
};

   
    const sleep = (ms)=> new Promise(r=>setTimeout(r, ms));
    const setError = (msg)=>{ error.value = msg; if (!window.Swal) return; Swal.fire('提醒', msg, 'warning'); };
    const clearError = ()=> error.value = '';
    const notify = (title, text, icon='info')=>{
      if (window.Swal) Swal.fire(title, text, icon);
      else alert(`${title}\n${text}`);
    };

    return {
      showForm, loading, error, showPassword,
      login, hasAccount,
      forgotAccount,
      focusAccount, focusPassword, openForgot, sendForgot,
      loginSubmit
    };
  }
}).mount('#app');
