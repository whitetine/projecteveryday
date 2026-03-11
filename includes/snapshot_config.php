<?php
// Snapshot token 設定：集中管理，不在各檔案硬編碼密鑰。
if (!function_exists('getSnapshotTokenSecret')) {
    /**
     * 取得用來產生 snapshot_token 的伺服器端固定密鑰。
     * 建議從環境變數 SNAPSHOT_TOKEN_SECRET 讀取，若未設定則使用預設值。
     */
    function getSnapshotTokenSecret(): string
    {
        // 若有環境變數則優先使用，否則 fallback 為預設密鑰
        $env = getenv('SNAPSHOT_TOKEN_SECRET');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        // 與規格一致的預設密鑰（可視需要改成只走環境變數）
        return 'projectevery_secret_key';
    }
}

