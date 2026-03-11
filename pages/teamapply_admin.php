
    <!DOCTYPE html>
    <html lang="zh-TW">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>指導老師管理後台</title>
        <?php $asset_prefix = (strpos($_SERVER['PHP_SELF'], '/pages/') !== false) ? '../' : ''; ?>
        <link rel="stylesheet" href="<?= $asset_prefix ?>css/team_table_unified.css?v=<?= time() ?>">
        <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"> -->
        <!-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"> -->
        <style>
            body { background-color: #f8f9fa; }
            .card { border: none; shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .status-badge { width: 80px; text-align: center; }
        </style>
    </head>
    <body>

    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold text-dark">指導老師管理</h2>
                <p class="text-secondary mb-0">管理前台下拉選單的顯示項目</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-lg"></i> 新增老師
            </button>
        </div>

        <div class="card rounded-3 shadow-sm">
            <div class="card-body p-0 team-unified-table-wrap">
                <table class="table align-middle mb-0 team-unified-table">
                    <thead class="bg-light">
                        <tr>
                            <th class="p-4">姓名</th>
                            <th>系所</th>
                            <th>Email</th>
                            <th>前台狀態</th>
                            
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($advisors as $advisor): ?>
                        <tr>
                            <td class="p-4 fw-bold"><?= htmlspecialchars($advisor['name']) ?></td>
                            <td><?= htmlspecialchars($advisor['department']) ?></td>
                            <td class="text-muted"><?= htmlspecialchars($advisor['email']) ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $advisor['id'] ?>">
                                    <button type="submit" class="btn btn-sm status-badge <?= $advisor['status'] === 'active' ? 'btn-success' : 'btn-secondary' ?>">
                                        <?= $advisor['status'] === 'active' ? '顯示中' : '已隱藏' ?>
                                    </button>
                                </form>
                            </td>
                            
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">新增指導老師</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">姓名</label>
                            <input type="text" name="name" class="form-control" required placeholder="例如：王小明">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">系所</label>
                            <input type="text" name="department" class="form-control" required placeholder="例如：資管系">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="選填">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary">確認新增</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>