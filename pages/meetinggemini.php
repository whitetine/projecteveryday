<!-- 
<div class="container-fluid py-3 gmail-notifications" data-page-id="admin_notify">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="page-title">
        <i class="fa-solid fa-layer-group me-2" style="color: #ffc107;"></i>會議咪挺
    </h1>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-secondary" id="refreshBtn" title="重新整理">
        <i class="bi bi-arrow-clockwise"></i> 重新整理
      </button>
    </div>
  </div>

    <div id="meetingList" class="list-group">
        

    </div>
</div> -->
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>會議咪挺 - 聊天室</title>
    <style>
        body { font-family: sans-serif; margin: 0; background-color: #f9f9f9; display: flex; flex-direction: column; height: 100vh; }
        
        /* 頂部標題欄 */
        header { background: white; padding: 10px 20px; border-bottom: 2px solid #ffcc00; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 20px; font-weight: bold; color: #333; display: flex; align-items: center; }
        .logo span { color: #ffcc00; margin-right: 5px; }

        /* 中間聊天框 */
        #chat-container { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 15px; }
        .message { max-width: 70%; padding: 10px 15px; border-radius: 15px; font-size: 14px; line-height: 1.5; }
        .user-msg { align-self: flex-end; background-color: #007bff; color: white; }
        .ai-msg { align-self: flex-start; background-color: #e9e9eb; color: #333; }

        /* 底部輸入區域 */
        .input-area { background: white; border-top: 1px solid #ddd; padding: 10px; display: flex; align-items: center; gap: 10px; }
        .input-wrapper { flex: 1; display: flex; border: 2px solid #333; border-radius: 5px; overflow: hidden; }
        
        .upload-btn { background: #eee; border: none; padding: 10px; cursor: pointer; border-right: 1px solid #333; white-space: nowrap; }
        .text-input { flex: 1; border: none; padding: 10px; outline: none; }
        .audio-btn { background: #eee; border: none; padding: 10px; cursor: pointer; border-left: 1px solid #333; white-space: nowrap; }
        
        button:hover { background: #ddd; }
    </style>
</head>
<body>

<header>
    <div class="logo"><span>🥞</span> 會議咪挺</div>
    <button onclick="location.reload()">重新整理</button>
</header>

<div id="chat-container">
    <div class="message ai-msg">你好！請輸入訊息或上傳檔案開始對話。</div>
    </div>

<div class="input-area">
    <div class="input-wrapper">
        <input type="file" id="fileInput" style="display:none" onchange="handleFileUpload()">
        <button class="upload-btn" onclick="document.getElementById('fileInput').click()">上傳圖檔/檔案</button>
        
        <input type="text" id="userInput" class="text-input" placeholder="請輸入訊息..." onkeypress="if(event.key==='Enter') sendMessage()">
        
        <button class="audio-btn" onclick="alert('錄音功能啟動中...')">錄音檔</button>
    </div>
    <button onclick="sendMessage()" style="padding: 10px 20px; background: #333; color: white; border-radius: 5px; border: none; cursor: pointer;">發送</button>
</div>

<script>
    const chatContainer = document.getElementById('chat-container');

    function sendMessage() {
        const input = document.getElementById('userInput');
        if (input.value.trim() === "") return;

        // 使用者訊息
        appendMessage(input.value, 'user-msg');
        
        // 模擬 AI 回應
        setTimeout(() => {
            appendMessage("我收到你的訊息了：" + input.value, 'ai-msg');
        }, 500);

        input.value = "";
    }

    function appendMessage(text, className) {
        const msgDiv = document.createElement('div');
        msgDiv.className = `message ${className}`;
        msgDiv.innerText = text;
        chatContainer.appendChild(msgDiv);
        chatContainer.scrollTop = chatContainer.scrollHeight; // 自動捲動到底部
    }

    function handleFileUpload() {
        const file = document.getElementById('fileInput').files[0];
        if (file) {
            appendMessage("已上傳檔案: " + file.name, 'user-msg');
        }
    }
</script>

</body>
</html>