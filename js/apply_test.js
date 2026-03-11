(function () {
        function setUserName() {
            if (window.CURRENT_USER && window.CURRENT_USER.u_name) {
                const inputEl = document.getElementById('apply_user');
                if (inputEl) {
                    inputEl.value = window.CURRENT_USER.u_name;
                }
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setUserName);
        } else {
            setTimeout(setUserName, 0);
        }
    })();

    (function () {
        function loadAndMount() {
            // 確保 Vue 已載入
            if (typeof Vue === 'undefined') {
                setTimeout(loadAndMount, 10);
                return;
            }

            if (typeof window.mountApplyTestFormFiller === 'function') {
                const mountEl = document.querySelector('#app');
                if (mountEl && !mountEl.__vue_app__) {
                    window.mountApplyTestFormFiller('#app');
                }
            } else {
                const script = document.createElement('script');
                script.src = 'js/apply-test-form-filler.js?v=' + (window.APPLY_TEST_CACHE_VERSION || Date.now());
                script.onload = function () {
                    if (typeof window.mountApplyTestFormFiller === 'function') {
                        const mountEl = document.querySelector('#app');
                        if (mountEl && !mountEl.__vue_app__) {
                            window.mountApplyTestFormFiller('#app');
                        }
                    }
                };
                document.head.appendChild(script);
            }
        }

        // 立即嘗試掛載，不等待 DOMContentLoaded
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', loadAndMount);
        } else {
            loadAndMount();
        }
    })();