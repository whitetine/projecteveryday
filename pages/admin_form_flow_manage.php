<?php
session_start();
require '../includes/pdo.php';

if (!isset($_SESSION['u_ID'])) {
    echo "<script>alert('請先登入');location.href='../index.php';</script>";
    exit;
}

$role_ID = $_SESSION['role_ID'] ?? 0;
if (!in_array($role_ID, [1, 2])) {
    echo "<script>alert('此頁面僅限主任和科辦使用');location.href='../main.php';</script>";
    exit;
}

// 獲取所有啟用的類組
$groups = $conn->query("SELECT * FROM groupdata WHERE group_status = 1 AND group_name <> 'ukn' ORDER BY group_ID")->fetchAll(PDO::FETCH_ASSOC);
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    .flow-management-container {
        padding: 20px;
        max-width: 1400px;
        margin: 0 auto;
    }
    .page-header {
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .page-title {
        font-size: 28px;
        font-weight: bold;
        color: #333;
        display: flex;
        align-items: center;
    }
    .toolbar {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-bottom: 20px;
    }
    .search-box {
        flex: 1;
        max-width: 300px;
        position: relative;
    }
    .search-box input {
        width: 100%;
        padding: 8px 35px 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }
    .search-box i {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
    }
    .filter-buttons {
        display: flex;
        gap: 5px;
    }
    .filter-btn {
        padding: 6px 12px;
        border: 1px solid #ddd;
        background: white;
        border-radius: 4px;
        cursor: pointer;
        font-size: 13px;
        transition: all 0.2s;
    }
    .filter-btn.active {
        background: #007bff;
        color: white;
        border-color: #007bff;
    }
    .flow-list-card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }
    .card-header {
        padding: 15px 20px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .card-body {
        padding: 20px;
    }
    .flow-item {
        display: flex;
        align-items: center;
        padding: 15px;
        margin-bottom: 10px;
        background: #f8f9fa;
        border-radius: 8px;
        border: 2px solid transparent;
        transition: all 0.3s;
        cursor: pointer;
    }
    .flow-item:hover {
        border-color: #007bff;
        background: #e7f3ff;
    }
    .flow-item.dragging {
        opacity: 0.5;
        transform: scale(0.95);
    }
    .flow-item.disabled {
        opacity: 0.6;
        background: #e9ecef;
    }
    .flow-order-input {
        width: 60px;
        height: 40px;
        border: 2px solid #007bff;
        border-radius: 8px;
        text-align: center;
        font-weight: bold;
        font-size: 16px;
        margin-right: 15px;
        flex-shrink: 0;
    }
    .flow-order-display {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: #007bff;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 18px;
        margin-right: 15px;
        flex-shrink: 0;
        cursor: pointer;
        transition: all 0.2s;
    }
    .flow-order-display:hover {
        background: #0056b3;
        transform: scale(1.1);
    }
    .flow-info {
        flex: 1;
        min-width: 0;
    }
    .flow-name {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 5px;
        color: #333;
    }
    .flow-form-name {
        color: #666;
        font-size: 14px;
        margin-bottom: 3px;
    }
    .flow-status-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 500;
        margin-top: 5px;
    }
    .flow-status-badge.enabled {
        background: #d4edda;
        color: #155724;
    }
    .flow-status-badge.disabled {
        background: #f8d7da;
        color: #721c24;
    }
    .flow-actions {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-shrink: 0;
    }
    .btn-action {
        padding: 6px 12px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .btn-edit {
        background: #007bff;
        color: white;
    }
    .btn-edit:hover {
        background: #0056b3;
    }
    .btn-copy {
        background: #17a2b8;
        color: white;
    }
    .btn-copy:hover {
        background: #138496;
    }
    .btn-toggle {
        background: #28a745;
        color: white;
    }
    .btn-toggle.disabled {
        background: #6c757d;
    }
    .btn-delete {
        background: #dc3545;
        color: white;
    }
    .btn-delete:hover {
        background: #c82333;
    }
    .drag-handle {
        cursor: move;
        color: #999;
        font-size: 18px;
        margin-right: 10px;
        padding: 5px;
        transition: color 0.2s;
    }
    .drag-handle:hover {
        color: #007bff;
    }
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #999;
    }
    .btn-add-flow {
        background: #28a745;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 5px;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .btn-add-flow:hover {
        background: #218838;
    }
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        overflow-y: auto;
    }
    .modal-content {
        background: white;
        margin: 5% auto;
        padding: 30px;
        border-radius: 8px;
        width: 90%;
        max-width: 600px;
        position: relative;
    }
    .form-group {
        margin-bottom: 20px;
    }
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #333;
    }
    .form-group select,
    .form-group input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        box-sizing: border-box;
    }
    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
    }
    .batch-actions {
        display: none;
        padding: 15px 20px;
        background: #f8f9fa;
        border-bottom: 1px solid #eee;
        align-items: center;
        gap: 10px;
    }
    .batch-actions.active {
        display: flex;
    }
    .checkbox-select {
        width: 18px;
        height: 18px;
        cursor: pointer;
        margin-right: 10px;
    }
    .order-controls {
        display: flex;
        gap: 5px;
        align-items: center;
        margin-left: 10px;
    }
    .btn-order {
        width: 30px;
        height: 30px;
        border: 1px solid #ddd;
        background: white;
        border-radius: 4px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
    }
    .btn-order:hover {
        background: #f0f0f0;
    }
</style>

<div class="flow-management-container">
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-sitemap me-2"></i>表單流程管理
        </h1>
    </div>

    <!-- 工具列 -->
    <div class="toolbar">
        <div class="search-box">
            <input type="text" id="searchInput" placeholder="搜尋流程名稱或表單名稱..." onkeyup="filterFlows()">
            <i class="fas fa-search"></i>
        </div>
        <div style="display: flex; align-items: center; gap: 15px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <label for="groupSelect" style="font-weight: 600; color: #333; white-space: nowrap;">選擇類組：</label>
                <select id="groupSelect" name="group_ID" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; min-width: 150px;" onchange="onGroupChange()">
                    <option value="">-- 請選擇類組 --</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?= htmlspecialchars($group['group_ID']) ?>"><?= htmlspecialchars($group['group_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-buttons">
                <button class="filter-btn active" data-filter="all" onclick="setFilter('all')">全部</button>
                <button class="filter-btn" data-filter="enabled" onclick="setFilter('enabled')">啟用中</button>
                <button class="filter-btn" data-filter="disabled" onclick="setFilter('disabled')">已停用</button>
            </div>
        </div>
    </div>

    <!-- 批量操作列 -->
    <div class="batch-actions" id="batchActions">
        <span id="selectedCount">已選擇 0 項</span>
        <button class="btn-action btn-toggle" onclick="batchToggle(true)">批量啟用</button>
        <button class="btn-action btn-toggle disabled" onclick="batchToggle(false)">批量停用</button>
        <button class="btn-action btn-delete" onclick="batchDelete()">批量刪除</button>
        <button class="btn-action" style="background: #6c757d; color: white;" onclick="clearSelection()">取消選擇</button>
    </div>

    <div class="flow-list-card">
        <div class="card-header">
            <h5><i class="fas fa-list me-2"></i>表單流程順序</h5>
            <button class="btn-add-flow" onclick="openAddFlowModal()">
                <i class="fas fa-plus"></i>新增流程步驟
            </button>
        </div>
        <div class="card-body">
            <div id="flowList">
                <div class="text-center text-secondary">
                    <i class="fas fa-spinner fa-spin"></i> 載入中...
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 新增/編輯流程 Modal -->
<div id="flowModal" class="modal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <h3 id="modalTitle" style="margin: 0;">新增流程步驟</h3>
            <button onclick="closeFlowModal()" style="background: none; border: none; font-size: 28px; cursor: pointer; color: #999; line-height: 1;">&times;</button>
        </div>
        <form id="flowForm">
            <input type="hidden" id="ff_ID" name="ff_ID" value="0">
            <div class="form-group">
                <label>選擇類組 <span style="color: red;">*</span></label>
                <select id="flow_group_ID" name="group_ID" required>
                    <option value="">請選擇類組...</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?= htmlspecialchars($group['group_ID']) ?>"><?= htmlspecialchars($group['group_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">此流程步驟將屬於選定的類組</small>
            </div>
            <div class="form-group">
                <label>選擇表單 <span style="color: red;">*</span></label>
                <select id="form_ID" name="form_ID" required>
                    <option value="">請選擇表單...</option>
                </select>
            </div>
            <div class="form-group">
                <label>流程名稱 <span style="color: red;">*</span></label>
                <input type="text" id="ff_name" name="ff_name" required placeholder="例如：專題初審單">
            </div>
            <div class="form-group">
                <label>是否啟用</label>
                <select id="ff_enabled" name="ff_enabled">
                    <option value="1">啟用</option>
                    <option value="0">停用</option>
                </select>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 30px;">
                <button type="button" onclick="closeFlowModal()" style="padding: 10px 25px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer; font-size: 14px;">取消</button>
                <button type="submit" style="padding: 10px 25px; border: none; background: #007bff; color: white; border-radius: 4px; cursor: pointer; font-size: 14px;">儲存</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function() {
    'use strict';
    
    const FLOW_API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
    let allFlows = [];
    let currentFilter = 'all';
    let selectedFlows = new Set();
    let formsList = []; // 緩存表單列表
    let currentGroupID = null; // 當前選擇的類組ID
    
    // 工具函數 - 必須在 IIFE 開始時定義
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function showError(msg) {
        Swal.fire('錯誤', msg, 'error');
    }
    
    // 類組選擇改變時的處理
    window.onGroupChange = function() {
        const groupSelect = document.getElementById('groupSelect');
        currentGroupID = groupSelect.value ? parseInt(groupSelect.value) : null;
        loadFlows();
    };

    // 載入流程列表
    async function loadFlows() {
        try {
            // 如果沒有選擇類組，提示用戶先選擇
            if (!currentGroupID) {
                document.getElementById('flowList').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-info-circle fa-3x" style="margin-bottom: 15px; opacity: 0.3; color: #007bff;"></i>
                        <p>請先選擇類組以查看該類組的表單流程順序</p>
                    </div>
                `;
                return;
            }

            const url = `${FLOW_API_ROOT}?do=get_form_flows_by_group&group_ID=${currentGroupID}`;
            console.log('載入流程列表，URL:', url);
            const response = await fetch(url);
            const data = await response.json();
            console.log('流程列表API回應:', data);
            
            if (data.ok) {
                allFlows = data.flows || [];
                console.log('載入到', allFlows.length, '個流程');
                renderFlows(allFlows);
            } else {
                console.error('載入失敗:', data.msg);
                showError('載入失敗：' + (data.msg || '未知錯誤'));
            }
        } catch (error) {
            console.error('載入流程錯誤:', error);
            showError('載入流程時發生錯誤');
        }
    }
    
    // 渲染流程列表
    function renderFlows(flows) {
        const container = document.getElementById('flowList');
        
        if (flows.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 15px; opacity: 0.3;"></i>
                    <p>目前沒有設定任何流程步驟</p>
                    <p style="font-size: 12px; color: #999;">點擊「新增流程步驟」開始設定</p>
                </div>
            `;
            return;
        }
        
        container.innerHTML = flows.map((flow, index) => `
            <div class="flow-item ${flow.ff_enabled == 0 ? 'disabled' : ''}" 
                 data-ff-id="${flow.ff_ID}" 
                 data-enabled="${flow.ff_enabled}"
                 data-original-order="${flow.ff_order}"
                 data-flow-name="${escapeHtml(flow.ff_name.toLowerCase())}"
                 data-form-name="${escapeHtml((flow.form_name || '').toLowerCase())}"
                 draggable="true"
                 onclick="event.stopPropagation(); editFlow(${flow.ff_ID})">
                <input type="checkbox" class="checkbox-select" 
                       onclick="event.stopPropagation(); toggleSelection(${flow.ff_ID}, this.checked)"
                       ${selectedFlows.has(flow.ff_ID) ? 'checked' : ''}>
                <i class="fas fa-grip-vertical drag-handle" onclick="event.stopPropagation();"></i>
                <div class="flow-order-display" onclick="event.stopPropagation(); ${flow.ff_enabled == 1 && flow.fgt_status_ID == 1 ? `editOrder(${flow.ff_ID}, ${flow.display_order !== undefined ? flow.display_order : flow.fgt_order})` : ''}" title="${flow.ff_enabled == 1 && flow.fgt_status_ID == 1 ? '點擊編輯順序' : '已停用'}" data-original-order="${flow.fgt_order || flow.ff_order}">
                    ${flow.display_order !== undefined ? flow.display_order : (flow.ff_enabled == 1 && flow.fgt_status_ID == 1 ? (flow.fgt_order || flow.ff_order) : 'X')}
                </div>
                <div class="flow-info" onclick="event.stopPropagation();">
                    <div class="flow-name">${escapeHtml(flow.ff_name)}</div>
                    <div class="flow-form-name">表單：${escapeHtml(flow.form_name || '未設定')}</div>
                    <span class="flow-status-badge ${flow.ff_enabled == 1 ? 'enabled' : 'disabled'}">
                        ${flow.ff_enabled == 1 ? '✓ 啟用中' : '✗ 已停用'}
                    </span>
                </div>
                <div class="flow-actions" onclick="event.stopPropagation();">
                    <button class="btn-action btn-edit" onclick="event.stopPropagation(); editFlow(${flow.ff_ID})" title="編輯">
                        <i class="fas fa-edit"></i> 編輯
                    </button>
                    <button class="btn-action btn-copy" onclick="event.stopPropagation(); copyFlow(${flow.ff_ID})" title="複製">
                        <i class="fas fa-copy"></i> 複製
                    </button>
                    <button class="btn-action btn-toggle ${flow.ff_enabled == 0 ? 'disabled' : ''}" 
                            onclick="event.stopPropagation(); toggleFlow(${flow.ff_ID}, ${flow.ff_enabled == 1 ? 0 : 1})" 
                            title="${flow.ff_enabled == 1 ? '停用' : '啟用'}">
                        <i class="fas fa-${flow.ff_enabled == 1 ? 'check' : 'times'}"></i> ${flow.ff_enabled == 1 ? '停用' : '啟用'}
                    </button>
                    <button class="btn-action btn-delete" onclick="event.stopPropagation(); deleteFlow(${flow.ff_ID})" title="刪除">
                        <i class="fas fa-trash"></i> 刪除
                    </button>
                </div>
            </div>
        `).join('');
        
        // 初始化拖拽功能
        initDragAndDrop();
        updateBatchActions();
    }
    
    // 初始化拖拽排序（改進版）
    function initDragAndDrop() {
        const container = document.getElementById('flowList');
        const items = container.querySelectorAll('.flow-item');
        let draggedElement = null;
        let draggedIndex = -1;
        
        items.forEach((item, index) => {
            item.addEventListener('dragstart', (e) => {
                draggedElement = item;
                draggedIndex = index;
                item.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/html', item.innerHTML);
            });
            
            item.addEventListener('dragend', () => {
                item.classList.remove('dragging');
                // 移除所有拖拽相關的樣式
                items.forEach(i => {
                    i.style.borderTop = '';
                });
            });
            
            item.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                
                // 顯示插入位置
                const afterElement = getDragAfterElement(container, e.clientY);
                items.forEach(i => {
                    i.style.borderTop = '';
                });
                
                if (afterElement == null) {
                    items[items.length - 1].style.borderTop = '3px solid #007bff';
                } else {
                    afterElement.style.borderTop = '3px solid #007bff';
                }
        });
        
            item.addEventListener('drop', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                if (draggedElement && draggedElement !== item) {
                    const afterElement = getDragAfterElement(container, e.clientY);
                    
                    if (afterElement == null) {
                        container.appendChild(draggedElement);
                    } else {
                        container.insertBefore(draggedElement, afterElement);
                    }
                    
                    // 更新順序
                await updateFlowOrder();
                }
                
                // 清除樣式
                items.forEach(i => {
                    i.style.borderTop = '';
                });
            });
        });
    }
    
    function getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll('.flow-item:not(.dragging)')];
        
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }
    
    // 更新流程順序
    async function updateFlowOrder() {
        if (!currentGroupID) {
            showError('請先選擇類組');
            return;
        }

        const items = document.querySelectorAll('.flow-item');
        const orders = Array.from(items).map((item, index) => ({
            ff_ID: parseInt(item.dataset.ffId),
            ff_order: index + 1
        }));
        
        try {
            const response = await fetch(`${FLOW_API_ROOT}?do=update_flow_order`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    group_ID: currentGroupID,
                    orders: orders
                })
            });
            
            const data = await response.json();
            if (data.ok) {
                // 重新載入以更新顯示順序（包括停用流程的X顯示）
                loadFlows();
            } else {
                showError('更新順序失敗：' + (data.msg || '未知錯誤'));
                loadFlows(); // 重新載入
            }
        } catch (error) {
            console.error('更新順序錯誤:', error);
            showError('更新順序時發生錯誤');
            loadFlows(); // 重新載入
        }
    }
    
    // 編輯順序（手動輸入）- 只對啟用的流程有效
    async function editOrder(ff_ID, currentOrder) {
        if (!currentGroupID) {
            Swal.fire('提示', '請先選擇類組', 'info');
            return;
        }

        // 檢查是否為數字（停用的流程顯示X，不能編輯）
        if (currentOrder === 'X' || isNaN(currentOrder)) {
            Swal.fire('提示', '停用的流程無法編輯順序', 'info');
            return;
        }
        
        // 只計算啟用流程的數量
        const enabledFlows = allFlows.filter(f => f.ff_enabled == 1 && f.fgt_status_ID == 1);
        const maxOrder = enabledFlows.length;
        
        const { value: newOrder } = await Swal.fire({
            title: '編輯順序',
            input: 'number',
            inputLabel: `請輸入新的順序號碼 (1-${maxOrder})`,
            inputValue: currentOrder,
            inputAttributes: {
                min: 1,
                max: maxOrder,
                step: 1
            },
            showCancelButton: true,
            confirmButtonText: '確定',
            cancelButtonText: '取消',
            inputValidator: (value) => {
                if (!value || value < 1) {
                    return '順序號碼必須大於 0';
                }
                if (value > maxOrder) {
                    return `順序號碼不能超過 ${maxOrder}`;
                }
            }
        });
        
        if (newOrder && newOrder != currentOrder) {
            const targetOrder = Math.max(1, Math.min(parseInt(newOrder), maxOrder));
            
            // 獲取所有流程（按當前順序）
            const items = Array.from(document.querySelectorAll('.flow-item'));
            
            // 重新計算所有順序
            const orders = [];
            let enabledIndex = 1;
            
            for (let i = 0; i < items.length; i++) {
                const item = items[i];
                const itemId = parseInt(item.dataset.ffId);
                const isEnabled = item.dataset.enabled == '1';
                
                if (itemId === ff_ID) {
                    // 跳過當前項目，稍後插入
                    continue;
                }
                
                if (isEnabled) {
                    // 啟用的流程：如果到達目標位置，先插入當前項目
                    if (enabledIndex === targetOrder) {
                        orders.push({ ff_ID: ff_ID, ff_order: enabledIndex });
                        enabledIndex++;
                    }
                    orders.push({ ff_ID: itemId, ff_order: enabledIndex });
                    enabledIndex++;
                } else {
                    // 停用的流程：保持原順序
                    const flow = allFlows.find(f => f.ff_ID === itemId);
                    const originalOrder = flow ? flow.fgt_order : parseInt(item.dataset.originalOrder || enabledIndex);
                    orders.push({ ff_ID: itemId, ff_order: originalOrder });
                }
            }
            
            // 如果目標順序在最後
            if (targetOrder >= enabledIndex) {
                orders.push({ ff_ID: ff_ID, ff_order: enabledIndex });
            }
            
            try {
                const response = await fetch(`${FLOW_API_ROOT}?do=update_flow_order`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        group_ID: currentGroupID,
                        orders: orders
                    })
                });
                
                const data = await response.json();
                if (data.ok) {
                    Swal.fire('成功', '順序已更新', 'success');
                    loadFlows();
                } else {
                    showError('更新順序失敗：' + (data.msg || '未知錯誤'));
                }
            } catch (error) {
                console.error('更新順序錯誤:', error);
                showError('更新順序時發生錯誤');
            }
        }
    }
    
    // 搜尋和過濾
    function filterFlows() {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const filtered = allFlows.filter(flow => {
            const matchesSearch = !searchTerm || 
                flow.ff_name.toLowerCase().includes(searchTerm) ||
                (flow.form_name || '').toLowerCase().includes(searchTerm);
            
            const matchesFilter = currentFilter === 'all' ||
                (currentFilter === 'enabled' && flow.ff_enabled == 1) ||
                (currentFilter === 'disabled' && flow.ff_enabled == 0);
            
            return matchesSearch && matchesFilter;
        });
        
        renderFlows(filtered);
    }
    
    function setFilter(filter) {
        currentFilter = filter;
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelector(`[data-filter="${filter}"]`).classList.add('active');
        filterFlows();
    }
    
    // 選擇管理
    function toggleSelection(ff_ID, checked) {
        if (checked) {
            selectedFlows.add(ff_ID);
        } else {
            selectedFlows.delete(ff_ID);
        }
        updateBatchActions();
    }
    
    function clearSelection() {
        selectedFlows.clear();
        document.querySelectorAll('.checkbox-select').forEach(cb => cb.checked = false);
        updateBatchActions();
    }
    
    function updateBatchActions() {
        const count = selectedFlows.size;
        document.getElementById('selectedCount').textContent = `已選擇 ${count} 項`;
        const batchActions = document.getElementById('batchActions');
        if (count > 0) {
            batchActions.classList.add('active');
        } else {
            batchActions.classList.remove('active');
        }
    }
    
    // 批量操作
    async function batchToggle(enabled) {
        if (selectedFlows.size === 0) return;
        
        const result = await Swal.fire({
            icon: 'question',
            title: '確認操作',
            text: `確定要${enabled ? '啟用' : '停用'}選中的 ${selectedFlows.size} 個流程嗎？`,
            showCancelButton: true,
            confirmButtonText: '確定',
            cancelButtonText: '取消'
        });
        
        if (!result.isConfirmed) return;
        
        try {
            const promises = Array.from(selectedFlows).map(ff_ID => 
                fetch(`${FLOW_API_ROOT}?do=toggle_form_flow`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ff_ID, ff_enabled: enabled ? 1 : 0 })
                })
            );
            
            await Promise.all(promises);
            Swal.fire('成功', `已${enabled ? '啟用' : '停用'}選中的流程`, 'success');
            clearSelection();
            loadFlows();
        } catch (error) {
            console.error('批量操作錯誤:', error);
            showError('批量操作時發生錯誤');
        }
    }
    
    async function batchDelete() {
        if (selectedFlows.size === 0) return;
        
        const result = await Swal.fire({
            icon: 'warning',
            title: '確認刪除',
            text: `確定要刪除選中的 ${selectedFlows.size} 個流程嗎？此操作無法復原。`,
            showCancelButton: true,
            confirmButtonText: '確定刪除',
            cancelButtonText: '取消',
            confirmButtonColor: '#dc3545'
        });
        
        if (!result.isConfirmed) return;
        
        try {
            const promises = Array.from(selectedFlows).map(ff_ID => 
                fetch(`${FLOW_API_ROOT}?do=delete_form_flow`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ff_ID })
                })
            );
            
            await Promise.all(promises);
            Swal.fire('成功', '已刪除選中的流程', 'success');
            clearSelection();
            loadFlows();
        } catch (error) {
            console.error('批量刪除錯誤:', error);
            showError('批量刪除時發生錯誤');
        }
    }
    
    // 載入表單列表（用於下拉選單）- 改進版
    async function loadFormsList() {
        try {
            // 如果已經載入過，直接返回緩存的列表
            if (formsList.length > 0) {
                return formsList;
            }
            
            const response = await fetch(`${FLOW_API_ROOT}?do=get_forms`);
            const data = await response.json();
            
            if (data.ok && data.forms) {
                formsList = data.forms;
                console.log('表單列表載入完成，共', formsList.length, '個表單');
                return formsList;
            } else {
                throw new Error(data.msg || '載入表單列表失敗');
            }
        } catch (error) {
            console.error('載入表單列表錯誤:', error);
            throw error;
        }
    }
    
    // 填充表單下拉選單
    function populateFormSelect(selectElement, selectedValue = '') {
        if (!selectElement) {
            console.error('selectElement 不存在');
            return false;
        }
        
        if (formsList.length === 0) {
            console.warn('表單列表為空，無法填充');
            return false;
        }
        
        const currentValue = selectedValue || selectElement.value;
        
        selectElement.innerHTML = '<option value="">請選擇表單...</option>' +
            formsList.map(f => {
                const selected = f.form_ID == currentValue ? 'selected' : '';
                return `<option value="${f.form_ID}" ${selected}>${escapeHtml(f.form_name)} (${escapeHtml(f.form_category || '未分類')})</option>`;
            }).join('');
        
        if (currentValue) {
            selectElement.value = currentValue;
        }
        
        return true;
    }
    
    // 開啟新增/編輯 Modal
    window.openAddFlowModal = async function(ff_ID = 0) {
        const modal = document.getElementById('flowModal');
        const form = document.getElementById('flowForm');
        
        if (!modal || !form) {
            Swal.fire('錯誤', 'Modal 元素不存在', 'error');
            return;
        }
        
        // 設置標題和 ID
        const titleEl = document.getElementById('modalTitle');
        const ff_IDEl = document.getElementById('ff_ID');
        const groupSelect = document.getElementById('flow_group_ID');
        const formSelect = document.getElementById('form_ID');
        const ff_nameEl = document.getElementById('ff_name');
        const ff_enabledEl = document.getElementById('ff_enabled');
        
        if (!titleEl || !ff_IDEl || !groupSelect || !formSelect || !ff_nameEl || !ff_enabledEl) {
            Swal.fire('錯誤', '表單元素不存在', 'error');
            return;
        }
        
        // 重置表單
        form.reset();
        
        // 設置標題和 ID
        titleEl.textContent = ff_ID ? '編輯流程步驟' : '新增流程步驟';
        ff_IDEl.value = ff_ID;
        
        // 如果是新增模式且已經選擇了類組，自動設置類組選擇
        if (ff_ID === 0 && currentGroupID) {
            groupSelect.value = currentGroupID;
        }
        
        // 顯示 Modal
        modal.style.display = 'block';
        
        try {
            // 載入表單列表
            await loadFormsList();
            
            // 填充下拉選單
            populateFormSelect(formSelect);
            
            // 如果是編輯模式，載入詳情（傳入當前選擇的類組ID）
            if (ff_ID) {
                await loadFlowDetail(ff_ID, currentGroupID);
            }
        } catch (error) {
            console.error('開啟 Modal 錯誤:', error);
            Swal.fire('錯誤', '載入表單列表失敗: ' + error.message, 'error');
        }
    };
    
    // 編輯流程
    window.editFlow = function(ff_ID) {
        openAddFlowModal(ff_ID);
    };
    
    // 複製流程
    window.copyFlow = async function(ff_ID) {
        try {
            const response = await fetch(`${FLOW_API_ROOT}?do=get_form_flow_detail&ff_ID=${ff_ID}`);
            const data = await response.json();
            
            if (data.ok && data.flow) {
                const flow = data.flow;
                
                // 使用 openAddFlowModal 來打開 Modal
                await openAddFlowModal(0);
                
                // 設置為複製模式
                document.getElementById('modalTitle').textContent = '複製流程步驟';
                
                // 設置表單值
                const groupSelect = document.getElementById('flow_group_ID');
                const formSelect = document.getElementById('form_ID');
                const ff_nameEl = document.getElementById('ff_name');
                const ff_enabledEl = document.getElementById('ff_enabled');
                
                // 設置類組（使用當前選擇的類組或流程的類組）
                if (groupSelect) {
                    if (flow.group_ID) {
                        groupSelect.value = flow.group_ID;
                    } else if (currentGroupID) {
                        groupSelect.value = currentGroupID;
                    }
                }
                
                if (formSelect && formsList.length > 0) {
                    populateFormSelect(formSelect, flow.form_ID);
                }
                
                if (ff_nameEl) ff_nameEl.value = (flow.ff_name || '') + ' (複製)';
                if (ff_enabledEl) ff_enabledEl.value = flow.ff_enabled || 1;
                
                console.log('複製流程完成:', flow);
            } else {
                Swal.fire('錯誤', data.msg || '載入流程詳情失敗', 'error');
            }
        } catch (error) {
            console.error('載入流程詳情錯誤:', error);
            Swal.fire('錯誤', '載入流程詳情時發生錯誤', 'error');
        }
    };
    
    // 載入流程詳情
    async function loadFlowDetail(ff_ID, group_ID = null) {
        try {
            let url = `${FLOW_API_ROOT}?do=get_form_flow_detail&ff_ID=${ff_ID}`;
            if (group_ID) {
                url += `&group_ID=${group_ID}`;
            }
            const response = await fetch(url);
            const data = await response.json();
            
            if (data.ok && data.flow) {
                const flow = data.flow;
                const groupSelect = document.getElementById('flow_group_ID');
                const formSelect = document.getElementById('form_ID');
                const ff_nameEl = document.getElementById('ff_name');
                const ff_enabledEl = document.getElementById('ff_enabled');
                
                if (!groupSelect || !formSelect || !ff_nameEl || !ff_enabledEl) {
                    throw new Error('表單元素不存在');
                }
                
                // 確保表單列表已載入
                if (formsList.length === 0) {
                    await loadFormsList();
                }
                
                // 設置類組：優先使用流程的類組信息，否則使用當前選擇的類組
                if (flow.group_ID) {
                    groupSelect.value = flow.group_ID;
                } else if (group_ID) {
                    groupSelect.value = group_ID;
                } else if (currentGroupID) {
                    groupSelect.value = currentGroupID;
                }
                
                // 填充並設置選中的表單
                populateFormSelect(formSelect, flow.form_ID);
                
                // 設置其他欄位
                ff_nameEl.value = flow.ff_name || '';
                ff_enabledEl.value = flow.ff_enabled || 1;
                
                console.log('流程詳情載入完成:', flow);
            } else {
                throw new Error(data.msg || '載入流程詳情失敗');
            }
        } catch (error) {
            console.error('載入流程詳情錯誤:', error);
            Swal.fire('錯誤', error.message || '載入流程詳情時發生錯誤', 'error');
        }
    }
    
    // 關閉 Modal
    window.closeFlowModal = function() {
        document.getElementById('flowModal').style.display = 'none';
    };
    
    // 提交表單 - 使用事件委派
    function initFormSubmit() {
        const form = document.getElementById('flowForm');
        if (form) {
            form.addEventListener('submit', handleFormSubmit);
        }
    }
    
    async function handleFormSubmit(e) {
        e.preventDefault();
        
        // 確保所有元素都存在
        const ff_IDEl = document.getElementById('ff_ID');
        const form_IDEl = document.getElementById('form_ID');
        const ff_nameEl = document.getElementById('ff_name');
        const ff_enabledEl = document.getElementById('ff_enabled');
        
        if (!ff_IDEl || !form_IDEl || !ff_nameEl || !ff_enabledEl) {
            Swal.fire('錯誤', '表單元素不存在，請重新載入頁面', 'error');
            return;
        }
        
        // 直接從元素獲取值
        const ff_ID = parseInt(ff_IDEl.value) || 0;
        let form_ID = form_IDEl.value;
        const ff_name = ff_nameEl.value.trim();
        const ff_enabled = parseInt(ff_enabledEl.value) || 1;
        
        // 檢查 form_ID 是否為有效數字
        form_ID = parseInt(form_ID);
        
        // 調試信息
        console.log('表單提交數據:', { ff_ID, form_ID, ff_name, ff_enabled });
        console.log('form_ID select 元素:', form_IDEl);
        console.log('form_ID 原始值:', form_IDEl.value);
        console.log('form_ID 解析後:', form_ID);
        console.log('form_ID 選中的選項:', form_IDEl.selectedOptions[0]);
        console.log('所有選項:', Array.from(form_IDEl.options).map(opt => ({ value: opt.value, text: opt.text })));
        
        // 驗證表單名稱
        if (!ff_name) {
            Swal.fire('錯誤', '請輸入流程名稱', 'error');
            ff_nameEl.focus();
            return;
        }
        
        // 驗證表單選擇 - 更嚴格的檢查
        if (!form_IDEl.value || form_IDEl.value === '' || form_IDEl.value === '0') {
            Swal.fire('錯誤', '請選擇表單', 'error');
            form_IDEl.focus();
            return;
        }
        
        if (isNaN(form_ID) || form_ID <= 0) {
            Swal.fire('錯誤', '請選擇有效的表單', 'error');
            form_IDEl.focus();
            return;
        }
        
        // 獲取 Modal 中選擇的類組
        const group_IDEl = document.getElementById('flow_group_ID');
        const group_ID = group_IDEl ? parseInt(group_IDEl.value) : 0;
        
        // 驗證類組選擇
        if (!group_ID || group_ID <= 0) {
            Swal.fire('錯誤', '請選擇類組', 'error');
            if (group_IDEl) group_IDEl.focus();
            return;
        }
        
        const data = {
            ff_ID: ff_ID,
            form_ID: form_ID,
            ff_name: ff_name,
            ff_enabled: ff_enabled,
            group_ID: group_ID
        };
        
        console.log('準備發送的數據:', data);
        
        try {
            const response = await fetch(`${FLOW_API_ROOT}?do=save_form_flow`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            console.log('伺服器回應:', result);
            
            if (result.ok) {
                Swal.fire('成功', result.message || '儲存成功', 'success');
                closeFlowModal();
                
                // 同步更新工具列中的類組選擇為 Modal 中選擇的類組
                const groupSelect = document.getElementById('groupSelect');
                if (groupSelect && group_ID) {
                    groupSelect.value = group_ID;
                    currentGroupID = group_ID;
                    console.log('已更新當前類組ID:', currentGroupID);
                }
                
                // 重新載入流程列表
                console.log('開始重新載入流程列表，類組ID:', currentGroupID);
                await loadFlows();
            } else {
                Swal.fire('錯誤', result.msg || '儲存失敗', 'error');
            }
        } catch (error) {
            console.error('儲存錯誤:', error);
            Swal.fire('錯誤', '儲存時發生錯誤: ' + error.message, 'error');
        }
    }
    
    // 切換啟用狀態 - 暴露到 window 供 HTML 調用
    window.toggleFlow = async function(ff_ID, newStatus) {
        try {
            const response = await fetch(`${FLOW_API_ROOT}?do=toggle_form_flow`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ff_ID, ff_enabled: newStatus })
            });
            
            const data = await response.json();
            if (data.ok) {
                loadFlows();
            } else {
                showError('更新失敗：' + (data.msg || '未知錯誤'));
            }
        } catch (error) {
            console.error('切換狀態錯誤:', error);
            showError('更新時發生錯誤');
        }
    };
    
    // 刪除流程 - 暴露到 window 供 HTML 調用
    window.deleteFlow = async function(ff_ID) {
        const result = await Swal.fire({
            icon: 'warning',
            title: '確認刪除',
            text: '確定要刪除此流程步驟嗎？',
            showCancelButton: true,
            confirmButtonText: '確定刪除',
            cancelButtonText: '取消',
            confirmButtonColor: '#dc3545'
        });
        
        if (!result.isConfirmed) return;
        
        try {
            const response = await fetch(`${FLOW_API_ROOT}?do=delete_form_flow`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ff_ID })
            });
            
            const data = await response.json();
            if (data.ok) {
                Swal.fire('成功', '已刪除', 'success');
                selectedFlows.delete(ff_ID);
                loadFlows();
            } else {
                Swal.fire('錯誤', data.msg || '刪除失敗', 'error');
            }
        } catch (error) {
            console.error('刪除錯誤:', error);
            Swal.fire('錯誤', '刪除時發生錯誤', 'error');
        }
    };
    
    // 初始化所有事件監聽器
    function initEventListeners() {
        // 初始化表單提交
        initFormSubmit();
        
        // 點擊 Modal 外部關閉
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('flowModal');
            if (event.target == modal) {
                closeFlowModal();
            }
        });
    }
    
    // 頁面載入完成後初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initEventListeners();
            loadFlows();
        });
    } else {
        initEventListeners();
        loadFlows();
    }
})();
</script>
