<?php
require '../includes/pdo.php';
$groups = $conn->query("SELECT * FROM `groupdata` ORDER BY group_ID ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="css/group_manage.css?v=<?= time() ?>">

<div class="group-management-container">
    <div class="page-header">
        <h1 class="page-title">
            <i class="fa-solid fa-layer-group me-2" style="color: #ffc107;"></i>類組管理
        </h1>
    </div>

    <!-- 新增區塊 -->
    <div class="add-group-card">
        <div class="card-header">
            <h5>
                <i class="fa-solid fa-plus-circle"></i>新增類組
            </h5>
        </div>
        <div class="card-body">
            <form id="addForm" method="post" action="api.php?do=add_group" class="add-group-form">
                <input type="text" 
                       name="group_name" 
                       id="group_name" 
                       class="form-control add-group-input" 
                       placeholder="輸入類組名稱..." 
                       required
                       autocomplete="off">
                <button type="button" class="btn btn-add-group" onclick="confirmAdd()">
                    <i class="fa-solid fa-plus me-2"></i>新增
                </button>
            </form>
        </div>
    </div>

    <!-- 清單區塊 -->
    <div class="groups-list-card">
        <div class="card-header">
            <h5>
                <i class="fa-solid fa-list"></i>類組清單
            </h5>
            <span class="badge-count">共 <?= count($groups) ?> 筆</span>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($groups)): ?>
                <div class="empty-state-group">
                    <i class="fa-solid fa-inbox"></i>
                    <h4>目前沒有類組資料</h4>
                    <p>請使用上方表單新增類組</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="groups-table">
                        <thead>
                            <tr>
                                <!-- <th style="width: 80px;">序號</th> -->
                                <th>類組名稱</th>
                                <th style="width: 150px;">狀態</th>
                                <th style="width: 180px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groups as $i => $g): ?>
                                <tr>
                                    <!-- <td><?= $i + 1 ?></td> -->
                                    <td>
                                        <strong><?= htmlspecialchars($g['group_name']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $g['group_status'] ? 'active' : 'inactive' ?>">
                                            <?= $g['group_status'] ? '啟用' : '停用' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="post" action="api.php?do=toggle_group" class="d-inline toggle-group-form">
                                            <input type="hidden" name="group_ID" value="<?= (int)$g['group_ID'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-toggle-group <?= $g['group_status'] ? 'btn-disable' : 'btn-enable' ?>">
                                                <i class="fa-solid <?= $g['group_status'] ? 'fa-ban' : 'fa-check' ?> me-2"></i>
                                                <?= $g['group_status'] ? '停用' : '啟用' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="js/group_manage.js?v=<?= time() ?>"></script>