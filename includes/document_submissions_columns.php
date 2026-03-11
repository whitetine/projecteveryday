<?php
/**
 * 檢查 document_submissions 表是否有指定欄位（用於 qr_modified_at、sign_uploaded_d 等欄位移除後的相容）
 */
if (!function_exists('document_submissions_has_column')) {
    function document_submissions_has_column($conn, $col) {
        static $cache = [];
        if (!isset($cache[$col])) {
            try {
                $stmt = $conn->prepare("SHOW COLUMNS FROM document_submissions LIKE ?");
                $stmt->execute([$col]);
                $cache[$col] = $stmt->fetch() !== false;
            } catch (Throwable $e) {
                $cache[$col] = false;
            }
        }
        return $cache[$col];
    }
}
