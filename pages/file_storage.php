<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

require '../includes/pdo.php';
require '../includes/utils.php';

$pdo = $conn ?? null;
if (!$pdo instanceof PDO) {
  json_err('資料庫連線失敗');
}

$ensureFiledataTable = static function () use ($pdo): void {
  try {
    $pdo->query('SELECT 1 FROM filedata LIMIT 1');
    return;
  } catch (Throwable $e) {
    // table not found, continue to create
  }

  $createSQL = "
    CREATE TABLE IF NOT EXISTS filedata (
      file_ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
      file_name VARCHAR(255) NOT NULL,
      file_url VARCHAR(255) NOT NULL,
      file_des TEXT DEFAULT NULL,
      is_required TINYINT(1) DEFAULT 0,
      file_start_d DATETIME DEFAULT NULL,
      file_end_d DATETIME DEFAULT NULL,
      file_status TINYINT(1) DEFAULT 1,
      is_top TINYINT(1) DEFAULT 0,
      file_update_d DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (file_ID)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ";

  try {
    $pdo->exec($createSQL);
  } catch (Throwable $e) {
    json_err('無法建立 filedata：' . $e->getMessage());
  }

  try {
    $count = (int)$pdo->query('SELECT COUNT(*) FROM filedata')->fetchColumn();
    if ($count > 0) {
      return;
    }
  } catch (Throwable $e) {
    return;
  }

  try {
    $legacyRows = $pdo->query("
      SELECT file_ID, file_name, file_url, file_status, is_top, file_updated_d
      FROM file
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$legacyRows) {
      return;
    }

    $insert = $pdo->prepare("
      INSERT INTO filedata (file_ID, file_name, file_url, file_status, is_top, file_update_d)
      VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($legacyRows as $row) {
      $insert->execute([
        $row['file_ID'] ?? null,
        $row['file_name'] ?? '',
        $row['file_url'] ?? '',
        $row['file_status'] ?? 1,
        $row['is_top'] ?? 0,
        $row['file_updated_d'] ?? date('Y-m-d H:i:s'),
      ]);
    }
  } catch (Throwable $e) {
    // ignore if legacy table missing or copy fails
  }
};

$ensureFiledataTable();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$do = $_GET['do'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$normalizeDateTime = static function (?string $value): ?string {
  if ($value === null) {
    return null;
  }
  $value = trim($value);
  if ($value === '') {
    return null;
  }
  $value = str_replace('T', ' ', $value);
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
    $value .= ':00';
  }
  return $value;
};

$ensureFileColumns = static function () use ($pdo): void {
  try { $pdo->exec('ALTER TABLE filedata ADD COLUMN file_des TEXT'); } catch (Throwable $e) {}
  try { $pdo->exec('ALTER TABLE filedata ADD COLUMN is_required INT DEFAULT 0'); } catch (Throwable $e) {}
  try { $pdo->exec('ALTER TABLE filedata ADD COLUMN file_start_d DATETIME NULL'); } catch (Throwable $e) {}
  try { $pdo->exec('ALTER TABLE filedata ADD COLUMN file_end_d DATETIME NULL'); } catch (Throwable $e) {}
  try { $pdo->exec('ALTER TABLE filedata ADD COLUMN file_update_d DATETIME NULL'); } catch (Throwable $e) {}
};

$ensureTargetTable = static function () use ($pdo): void {
  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS filetargetdata (
        file_ID INT NOT NULL,
        file_target_type ENUM('ALL','COHORT','GRADE','CLASS','GROUP') NOT NULL,
        file_target_ID VARCHAR(50) NOT NULL,
        PRIMARY KEY (file_ID, file_target_type, file_target_ID),
        FOREIGN KEY (file_ID) REFERENCES filedata(file_ID) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
  } catch (Throwable $e) {
    // ignore if the table already exists or privilege insufficient
  }
};

$respondList = static function (bool $onlyActive = false) use ($pdo): void {
  try {
    $sql = "
      SELECT
        file_ID,
        file_name,
        file_url,
        file_des,
        is_required,
        file_status,
        is_top,
        file_start_d,
        file_end_d,
        file_update_d
      FROM filedata
      " . ($onlyActive ? "WHERE file_status = 1" : "") . "
      ORDER BY is_top DESC, file_ID DESC
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
      json_ok(['data' => []]);
    }

    $ids = array_column($rows, 'file_ID');
    foreach ($rows as &$row) {
      $row['target_all'] = false;
      $row['target_cohorts'] = [];
      $row['target_grades'] = [];
      $row['target_classes'] = [];
      $row['target_groups'] = [];
    }
    unset($row);

    if ($ids) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      try {
        $stmt = $pdo->prepare("
          SELECT file_ID, file_target_type, file_target_ID
          FROM filetargetdata
          WHERE file_ID IN ({$placeholders})
        ");
        $stmt->execute($ids);
        $map = [];
        while ($target = $stmt->fetch(PDO::FETCH_ASSOC)) {
          $map[$target['file_ID']][] = $target;
        }
        foreach ($rows as &$row) {
          $targets = $map[$row['file_ID']] ?? [];
          foreach ($targets as $target) {
            switch ($target['file_target_type']) {
              case 'ALL':
                $row['target_all'] = true;
                break;
              case 'COHORT':
                $row['target_cohorts'][] = $target['file_target_ID'];
                break;
              case 'GRADE':
                $row['target_grades'][] = $target['file_target_ID'];
                break;
              case 'CLASS':
                $row['target_classes'][] = $target['file_target_ID'];
                break;
              case 'GROUP':
                $row['target_groups'][] = $target['file_target_ID'];
                break;
            }
          }
        }
        unset($row);
      } catch (Throwable $e) {
        // ignore if the table is missing
      }
    }

    json_ok(['data' => $rows]);
} catch (Throwable $e) {
    json_err('資料讀取失敗：' . $e->getMessage());
  }
};

$handleUpdate = static function (?array $payload = null) use ($pdo): void {
  $payload = $payload ?? read_json_body();
  $fileId = (int)($payload['file_ID'] ?? 0);
  if ($fileId <= 0) {
    json_err('file_ID 無效');
  }
  $fileStatus = array_key_exists('file_status', $payload) ? (int)$payload['file_status'] : null;
  $isTop = array_key_exists('is_top', $payload) ? (int)$payload['is_top'] : null;

  if ($fileStatus === null && $isTop === null) {
    json_err('缺少更新欄位');
  }

  try {
    $stmt = $pdo->prepare("
      UPDATE filedata
      SET file_status = COALESCE(?, file_status),
          is_top      = COALESCE(?, is_top),
          file_update_d = NOW()
      WHERE file_ID = ?
    ");
    $stmt->execute([$fileStatus, $isTop, $fileId]);
    json_ok();
  } catch (Throwable $e) {
    json_err('更新失敗：' . $e->getMessage());
  }
};

$handleDelete = static function (array $ids) use ($pdo): void {
  $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
  if (!$ids) {
    json_err('沒有指定要刪除的檔案');
  }

  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $rows = [];

  try {
    $stmt = $pdo->prepare("SELECT file_ID, file_url FROM filedata WHERE file_ID IN ({$placeholders})");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
      json_ok(['deleted' => []]);
    }

    $pdo->beginTransaction();

    $del = $pdo->prepare("DELETE FROM filedata WHERE file_ID IN ({$placeholders})");
    $del->execute($ids);

    try {
      $delTarget = $pdo->prepare("DELETE FROM filetargetdata WHERE file_ID IN ({$placeholders})");
      $delTarget->execute($ids);
    } catch (Throwable $e) {
      // ignore when target table missing
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    json_err('刪除失敗：' . $e->getMessage());
  }

  foreach ($rows as $row) {
    if (!empty($row['file_url'])) {
      $path = realpath(__DIR__ . '/..') . '/' . ltrim($row['file_url'], '/');
      if ($path && is_file($path)) {
        @unlink($path);
      }
    }
  }

  json_ok(['deleted' => $ids]);
};

$handleUpload = static function (bool $withTargets = true) use ($pdo, $normalizeDateTime, $ensureFileColumns, $ensureTargetTable): void {
  $fileName = trim($_POST['file_name'] ?? ($_POST['f_name'] ?? ''));
  if ($fileName === '') {
    json_err('缺少表單名稱');
  }
  if (empty($_FILES['file']['name'])) {
    json_err('請選擇要上傳的檔案');
  }

  try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM filedata WHERE file_name = ?');
    $stmt->execute([$fileName]);
    if ($stmt->fetchColumn() > 0) {
      json_err('已有同樣檔名，請更換');
    }
  } catch (Throwable $e) {
    json_err('檔名檢查失敗：' . $e->getMessage());
  }

  $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
  if ($ext !== 'pdf') {
    json_err('僅允許上傳 PDF');
  }

  $dir = realpath(__DIR__ . '/..') . '/templates';
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    json_err('無法建立儲存目錄');
  }

  $saveName = 'tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
  $savePath = $dir . '/' . $saveName;
  if (!move_uploaded_file($_FILES['file']['tmp_name'], $savePath)) {
    json_err('檔案上傳失敗');
  }

  $fileUrl = 'templates/' . $saveName;
  $fileDes = trim($_POST['file_des'] ?? '');
  $isRequired = isset($_POST['is_required']) ? (int)$_POST['is_required'] : 0;
  $fileStart = $normalizeDateTime($_POST['file_start_d'] ?? null);
  $fileEnd = $normalizeDateTime($_POST['file_end_d'] ?? null);

  $targetAll = $withTargets ? (int)($_POST['target_all'] ?? 0) : 0;
  $targetCohorts = $withTargets ? (json_decode($_POST['target_cohorts'] ?? '[]', true) ?: []) : [];
  $targetGrades = $withTargets ? (json_decode($_POST['target_grades'] ?? '[]', true) ?: []) : [];
  $targetClasses = $withTargets ? (json_decode($_POST['target_classes'] ?? '[]', true) ?: []) : [];

  $ensureFileColumns();
  if ($withTargets) {
    $ensureTargetTable();
  }

  try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
      INSERT INTO filedata (file_name, file_url, file_des, is_required, file_start_d, file_end_d, file_status, is_top, file_update_d)
      VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW())
    ");
    $stmt->execute([$fileName, $fileUrl, $fileDes, $isRequired, $fileStart, $fileEnd]);
    $fileId = (int)$pdo->lastInsertId();

    if ($withTargets) {
      if ($targetAll) {
        $stmt = $pdo->prepare("
          INSERT INTO filetargetdata (file_ID, file_target_type, file_target_ID)
          VALUES (?, 'ALL', '1')
          ON DUPLICATE KEY UPDATE file_target_ID = '1'
        ");
        $stmt->execute([$fileId]);
    } else {
        $insertTarget = $pdo->prepare("
          INSERT INTO filetargetdata (file_ID, file_target_type, file_target_ID)
          VALUES (?, ?, ?)
          ON DUPLICATE KEY UPDATE file_target_ID = VALUES(file_target_ID)
        ");
        foreach ($targetCohorts as $id) {
          $insertTarget->execute([$fileId, 'COHORT', $id]);
        }
        foreach ($targetGrades as $id) {
          $insertTarget->execute([$fileId, 'GRADE', $id]);
        }
        foreach ($targetClasses as $id) {
          $insertTarget->execute([$fileId, 'CLASS', $id]);
        }
      }
    }

    $pdo->commit();
    json_ok(['file_ID' => $fileId, 'file_url' => $fileUrl]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    @unlink($savePath);
    json_err('資料寫入失敗：' . $e->getMessage());
  }
};

if ($do !== '') {
  switch ($do) {
    case 'get_files_with_targets':
    case 'get_files':
      $respondList(false);
      break;
    case 'listActiveFiles':
      $respondList(true);
      break;
    case 'upload_file_with_targets':
      if ($method !== 'POST') {
        json_err('Method Not Allowed');
      }
      $handleUpload(true);
      break;
    case 'upload_template':
      if ($method !== 'POST') {
        json_err('Method Not Allowed');
      }
      $handleUpload(false);
      break;
    case 'update_template':
      if ($method !== 'POST') {
        json_err('Method Not Allowed');
      }
      $handleUpdate();
      break;
    case 'delete_file':
      if ($method !== 'POST') {
        json_err('Method Not Allowed');
      }
      $payload = read_json_body();
      $handleDelete([$payload['file_ID'] ?? 0]);
      break;
    case 'batch_delete_files':
      if ($method !== 'POST') {
        json_err('Method Not Allowed');
      }
      $payload = read_json_body();
      $handleDelete($payload['file_IDs'] ?? []);
      break;
    default:
      json_err('Unknown action');
  }
}

if ($method === 'GET') {
  if ($action === 'listActive') {
    $respondList(true);
  }
  $respondList(false);
}

if ($method === 'POST') {
  $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($contentType, 'application/json') !== false) {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($payload['action'] ?? '') === 'update') {
      $handleUpdate($payload);
    }
    if (($payload['action'] ?? '') === 'delete') {
      $handleDelete($payload['file_IDs'] ?? []);
    }
  }

  if (!empty($_FILES['file']) && isset($_POST['file_name'])) {
    $handleUpload(true);
  }

  json_err('無效的動作');
}

json_err('Method Not Allowed');

