<?php
/**
 * 表單匯出模板系統 - AI 動態排版版本
 * 根據範例文件或實際表單題目自動生成匯出格式
 */

/**
 * AI 識別範例文件結構並生成匯出模板
 */
function recognizeTemplateWithAI($exampleFilePath, $questions, $submission, $teamMembers) {
    global $conn;
    
    if (empty($exampleFilePath) || !file_exists($exampleFilePath)) {
        // 如果沒有範例文件，使用動態模板
        return renderDynamicForm($submission, $questions, $teamMembers);
    }
    
    try {
        require_once __DIR__ . '/../includes/ai_config.php';
        $aiConfig = include __DIR__ . '/../includes/ai_config.php';
        $apiKey = $aiConfig['google_api_key'] ?? '';
        
        if (empty($apiKey)) {
            error_log('AI API Key 未設定，使用動態模板');
            return renderDynamicForm($submission, $questions, $teamMembers);
        }
        
        // 讀取範例文件
        $fileContent = file_get_contents($exampleFilePath);
        $fileExt = strtolower(pathinfo($exampleFilePath, PATHINFO_EXTENSION));
        
        // 判斷 MIME 類型
        $mimeType = 'application/pdf';
        if ($fileExt === 'png') {
            $mimeType = 'image/png';
        } elseif (in_array($fileExt, ['jpg', 'jpeg'])) {
            $mimeType = 'image/jpeg';
        }
        
        // 使用 AI 識別範例文件結構
        $templateStructure = recognizeTemplateStructure($fileContent, $mimeType, $apiKey, $questions, $submission, $teamMembers);
        
        // 根據識別結果生成匯出模板
        return renderFromTemplateStructure($templateStructure, $questions, $submission, $teamMembers);
        
    } catch (Exception $e) {
        error_log('AI 識別範例文件失敗: ' . $e->getMessage());
        // 如果 AI 識別失敗，使用動態模板
        return renderDynamicForm($submission, $questions, $teamMembers);
    }
}

/**
 * 使用 AI 識別範例文件的結構
 */
function recognizeTemplateStructure($fileContent, $mimeType, $apiKey, $questions, $submission, $teamMembers) {
    require_once __DIR__ . '/../modules/form.php';
    
    // 構建問題描述，讓 AI 知道要填入哪些資料
    $questionDescriptions = [];
    foreach ($questions as $q) {
        $title = $q['fq_title'];
        $value = $q['fa_value'];
        $type = $q['fq_type'];
        
        // 處理值
        if (is_array($value)) {
            $valueStr = implode('、', $value);
        } else {
            $valueStr = $value ?? '';
        }
        
        // 如果是指導老師，轉換為名字
        if ((strpos(strtolower($title), '指導老師') !== false || 
             strpos(strtolower($title), 'advisor') !== false) 
            && !empty($valueStr) && preg_match('/^[a-zA-Z0-9_]+$/', $valueStr)) {
            try {
                $stmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                $stmt->execute([$valueStr]);
                $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($teacher && !empty($teacher['u_name'])) {
                    $valueStr = $teacher['u_name'];
                }
            } catch (Exception $e) {
                // 保持原值
            }
        }
        
        $questionDescriptions[] = [
            'title' => $title,
            'value' => $valueStr,
            'type' => $type
        ];
    }
    
    // 構建團隊成員資訊
    $membersInfo = [];
    if (!empty($teamMembers)) {
        foreach ($teamMembers as $member) {
            $membersInfo[] = [
                'class' => $member['class_name'] ?? '',
                'id' => $member['u_ID'] ?? '',
                'name' => $member['u_name'] ?? ''
            ];
        }
    }
    
    $prompt = "你是一個專業的表單分析專家。請仔細分析這個表單範例文件，並告訴我如何完全按照範例文件的格式和順序來生成表單。\n\n";
    $prompt .= "需要填入的資料：\n";
    $prompt .= json_encode($questionDescriptions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    
    if (!empty($membersInfo)) {
        $prompt .= "團隊成員資料：\n";
        $prompt .= json_encode($membersInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    }
    
    $prompt .= "**重要任務：請完全按照範例文件的結構、順序和格式來分析**\n\n";
    $prompt .= "請分析範例文件的：\n";
    $prompt .= "1. **標題位置、文字內容、字體大小、對齊方式**（必須完全一致）\n";
    $prompt .= "2. **每個欄位的標籤文字**（必須完全一致，包括標點符號）\n";
    $prompt .= "3. **每個欄位的出現順序**（從上到下，必須完全按照範例文件的順序）\n";
    $prompt .= "4. **每個欄位的布局方式**（標籤在左、值在右，或其他布局）\n";
    $prompt .= "5. **每個欄位的填寫位置樣式**（底線、框框、表格等）\n";
    $prompt .= "6. **整體布局**（兩欄式、單欄式、特殊布局等）\n";
    $prompt .= "7. **字體大小、行距、間距等樣式細節**\n";
    $prompt .= "8. **特殊元素**（如小組成員表格的位置和格式）\n\n";
    $prompt .= "**請以 JSON 格式回傳分析結果，格式如下：**\n";
    $prompt .= "{\n";
    $prompt .= "  \"layout\": \"two-column\" | \"single-column\" | \"custom\",\n";
    $prompt .= "  \"title\": {\n";
    $prompt .= "    \"text\": \"標題文字\",\n";
    $prompt .= "    \"fontSize\": \"字體大小\",\n";
    $prompt .= "    \"align\": \"center\" | \"left\" | \"right\",\n";
    $prompt .= "    \"subtitle\": [\"副標題1\", \"副標題2\"]\n";
    $prompt .= "  },\n";
    $prompt .= "  \"fields\": [\n";
    $prompt .= "    {\n";
    $prompt .= "      \"order\": 1,\n";
    $prompt .= "      \"label\": \"欄位標籤（必須與範例文件完全一致）\",\n";
    $prompt .= "      \"match\": \"對應的資料欄位名稱\",\n";
    $prompt .= "      \"position\": \"left\" | \"right\",\n";
    $prompt .= "      \"style\": \"underline\" | \"box\" | \"table\",\n";
    $prompt .= "      \"fontSize\": \"字體大小\",\n";
    $prompt .= "      \"marginTop\": \"上邊距\",\n";
    $prompt .= "      \"marginBottom\": \"下邊距\"\n";
    $prompt .= "    }\n";
    $prompt .= "  ]\n";
    $prompt .= "}\n\n";
    $prompt .= "**關鍵要求：**\n";
    $prompt .= "1. fields 陣列必須**完全按照範例文件中欄位出現的順序**排列（從上到下）\n";
    $prompt .= "2. order 欄位必須從 1 開始，按照範例文件的順序遞增\n";
    $prompt .= "3. label 欄位必須與範例文件中的標籤文字**完全一致**（包括標點符號、空格）\n";
    $prompt .= "4. 如果範例文件中有特殊布局或樣式，必須在 JSON 中詳細描述\n";
    $prompt .= "5. 不要遺漏任何欄位，包括空欄位或備註欄位\n";
    
    // 調用 Gemini API
    $model = 'gemini-2.0-flash';
    $apiVersion = 'v1beta';
    $url = "https://generativelanguage.googleapis.com/{$apiVersion}/models/{$model}:generateContent?key=" . urlencode($apiKey);
    
    $parts = [];
    $parts[] = ['text' => $prompt];
    
    // 添加文件內容
    if (strpos($mimeType, 'image/') === 0 || $mimeType === 'application/pdf') {
        $base64 = base64_encode($fileContent);
        $parts[] = [
            'inline_data' => [
                'mime_type' => $mimeType,
                'data' => $base64
            ]
        ];
    }
    
    $data = [
        'contents' => [['parts' => $parts]],
        'generationConfig' => [
            'temperature' => 0.3,
            'maxOutputTokens' => 4000,
            'responseMimeType' => 'application/json'
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 60
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        throw new Exception('AI API 請求失敗');
    }
    
    $result = json_decode($response, true);
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        error_log('AI 回應格式錯誤: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        throw new Exception('AI 回應格式錯誤');
    }
    
    $jsonText = $result['candidates'][0]['content']['parts'][0]['text'];
    // 清理可能的 markdown 代碼塊標記
    $jsonText = preg_replace('/```json\s*/', '', $jsonText);
    $jsonText = preg_replace('/```\s*/', '', $jsonText);
    $jsonText = trim($jsonText);
    
    $structure = json_decode($jsonText, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('無法解析 AI 回應為 JSON: ' . json_last_error_msg() . ' | 回應內容: ' . substr($jsonText, 0, 500));
        throw new Exception('無法解析 AI 回應為 JSON: ' . json_last_error_msg());
    }
    
    // 記錄 AI 識別結果（用於調試）
    error_log('AI 識別結果: ' . json_encode($structure, JSON_UNESCAPED_UNICODE));
    
    return $structure;
}

/**
 * 根據 AI 識別的結構生成匯出模板
 */
function renderFromTemplateStructure($structure, $questions, $submission, $teamMembers) {
    global $conn;
    
    // 建立標籤到值的對應關係
    $valueMap = [];
    foreach ($questions as $q) {
        $title = $q['fq_title'];
        $value = $q['fa_value'];
        $type = $q['fq_type'];
        
        // 處理值
        if (is_array($value)) {
            $valueStr = implode('、', $value);
        } else {
            $valueStr = $value ?? '';
        }
        
        // 如果是指導老師，轉換為名字
        if ((strpos(strtolower($title), '指導老師') !== false || 
             strpos(strtolower($title), 'advisor') !== false) 
            && !empty($valueStr) && preg_match('/^[a-zA-Z0-9_]+$/', $valueStr)) {
            try {
                $stmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                $stmt->execute([$valueStr]);
                $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($teacher && !empty($teacher['u_name'])) {
                    $valueStr = $teacher['u_name'];
                }
            } catch (Exception $e) {
                // 保持原值
            }
        }
        
        // 如果是班級，轉換為名稱
        if ((strpos(strtolower($title), '班級') !== false || strpos(strtolower($title), 'class') !== false) 
            && !empty($valueStr) && is_numeric($valueStr)) {
            try {
                $stmt = $conn->prepare("SELECT c_name FROM classdata WHERE c_ID = ?");
                $stmt->execute([$valueStr]);
                $class = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($class && !empty($class['c_name'])) {
                    $valueStr = $class['c_name'];
                }
            } catch (Exception $e) {
                // 保持原值
            }
        }
        
        // 如果是組別/類組，轉換為名稱
        if ((strpos(strtolower($title), '組別') !== false || strpos(strtolower($title), '類組') !== false || strpos(strtolower($title), 'group') !== false) 
            && !empty($valueStr) && is_numeric($valueStr)) {
            try {
                $stmt = $conn->prepare("SELECT group_name FROM groupdata WHERE group_ID = ?");
                $stmt->execute([$valueStr]);
                $group = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($group && !empty($group['group_name'])) {
                    $valueStr = $group['group_name'];
                }
            } catch (Exception $e) {
                // 保持原值
            }
        }
        
        // 如果是歷屆/屆別，轉換為名稱
        if ((strpos(strtolower($title), '歷屆') !== false || strpos(strtolower($title), '屆別') !== false || strpos(strtolower($title), 'cohort') !== false) 
            && !empty($valueStr) && is_numeric($valueStr)) {
            try {
                $stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ?");
                $stmt->execute([$valueStr]);
                $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($cohort && !empty($cohort['cohort_name'])) {
                    $valueStr = $cohort['cohort_name'];
                }
            } catch (Exception $e) {
                // 保持原值
            }
        }
        
        $valueMap[strtolower($title)] = $valueStr;
    }
    
    // 根據 AI 識別的標題資訊渲染標題（如果有的話）
    if (isset($structure['title']) && is_array($structure['title'])) {
        $titleInfo = $structure['title'];
        $titleText = $titleInfo['text'] ?? $submission['form_name'];
        $titleFontSize = $titleInfo['fontSize'] ?? '18pt';
        $titleAlign = $titleInfo['align'] ?? 'center';
        $subtitles = $titleInfo['subtitle'] ?? [];
        ?>
        <div class="form-header" style="text-align: <?= htmlspecialchars($titleAlign) ?>;">
            <div class="form-title" style="font-size: <?= htmlspecialchars($titleFontSize) ?>;">
                <?= htmlspecialchars($titleText) ?>
            </div>
            <?php foreach ($subtitles as $subtitle): ?>
            <div class="form-category" style="margin-top: 10px; font-size: 16pt;">
                <?= htmlspecialchars($subtitle) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    } else {
        // 如果 AI 沒有提供標題資訊，使用預設標題
        ?>
        <div class="form-header">
            <div class="form-title"><?= htmlspecialchars($submission['form_name']) ?></div>
            <?php if (!empty($submission['form_category'])): ?>
            <div class="form-category"><?= htmlspecialchars($submission['form_category']) ?></div>
            <?php endif; ?>
            <?php if (!empty($submission['group_name'])): ?>
            <div class="form-category" style="margin-top: 10px; font-size: 16pt;">
                <?= htmlspecialchars($submission['group_name']) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    // 根據 AI 識別的結構渲染欄位
    if (isset($structure['fields']) && is_array($structure['fields'])) {
        // 按照 order 欄位排序（如果有的話），確保順序與範例文件一致
        usort($structure['fields'], function($a, $b) {
            $orderA = isset($a['order']) ? (int)$a['order'] : 999;
            $orderB = isset($b['order']) ? (int)$b['order'] : 999;
            return $orderA <=> $orderB;
        });
        
        foreach ($structure['fields'] as $field) {
            $label = $field['label'] ?? '';
            $match = $field['match'] ?? '';
            $position = $field['position'] ?? 'right';
            $style = $field['style'] ?? 'underline';
            
            // 查找對應的值
            $value = '';
            if (!empty($match)) {
                $matchLower = strtolower($match);
                foreach ($valueMap as $key => $val) {
                    if (strpos($key, $matchLower) !== false || strpos($matchLower, $key) !== false) {
                        $value = $val;
                        break;
                    }
                }
            }
            
            // 特殊處理：小組成員
            if (strpos(strtolower($label), '小組成員') !== false || 
                strpos(strtolower($label), '組員') !== false) {
                if (!empty($teamMembers)) {
                    ?>
                    <div class="form-section">
                        <div class="form-field-two-col">
                            <div class="field-label-col"><?= htmlspecialchars($label) ?></div>
                            <div class="field-value-col">
                                <div style="font-size: 12pt; color: #666; margin-bottom: 5px;">（請註明班級、學號、姓名）</div>
                                <table class="form-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 33%; text-align: center;">班級</th>
                                            <th style="width: 33%; text-align: center;">學號</th>
                                            <th style="width: 34%; text-align: center;">姓名</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($teamMembers as $member): ?>
                                        <tr>
                                            <td style="text-align: center;"><?= htmlspecialchars($member['class_name'] ?? '') ?></td>
                                            <td style="text-align: center;"><?= htmlspecialchars($member['u_ID']) ?></td>
                                            <td style="text-align: center;"><?= htmlspecialchars($member['u_name'] ?? '') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php
                    continue;
                }
            }
            
            // 渲染一般欄位（完全按照 AI 識別的樣式）
            $fieldFontSize = $field['fontSize'] ?? '14pt';
            $marginTop = $field['marginTop'] ?? '0';
            $marginBottom = $field['marginBottom'] ?? '10px';
            ?>
            <div class="form-section" style="margin-top: <?= htmlspecialchars($marginTop) ?>; margin-bottom: <?= htmlspecialchars($marginBottom) ?>;">
                <div class="form-field-two-col">
                    <div class="field-label-col" style="font-size: <?= htmlspecialchars($fieldFontSize) ?>;">
                        <?= htmlspecialchars($label) ?>
                    </div>
                    <div class="field-value-col" style="font-size: <?= htmlspecialchars($fieldFontSize) ?>;">
                        <?php if (empty($value)): ?>
                            <div class="field-line"></div>
                        <?php else: ?>
                            <?php if ($style === 'box'): ?>
                                <div class="field-value-box"><?= nl2br(htmlspecialchars($value)) ?></div>
                            <?php else: ?>
                                <div class="field-value-text"><?= htmlspecialchars($value) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
        }
    } else {
        // 如果 AI 識別失敗，使用動態模板
        return renderDynamicForm($submission, $questions, $teamMembers);
    }
}

/**
 * 動態渲染表單（通用模板）
 * 根據實際題目自動排版，不寫死任何欄位
 */
function renderDynamicForm($submission, $questions, $teamMembers) {
    global $conn; // 需要資料庫連線來查詢老師名字
    ?>
    <div class="form-header">
        <div class="form-title"><?= htmlspecialchars($submission['form_name']) ?></div>
        <?php if (!empty($submission['form_category'])): ?>
        <div class="form-category"><?= htmlspecialchars($submission['form_category']) ?></div>
        <?php endif; ?>
        <?php if (!empty($submission['group_name'])): ?>
        <div class="form-category" style="margin-top: 10px; font-size: 16pt;">
            <?= htmlspecialchars($submission['group_name']) ?>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // 處理所有題目，自動填入相關資料
    $processedQuestions = [];
    
    foreach ($questions as $q) {
        $title = $q['fq_title'];
        $value = $q['fa_value'];
        $type = $q['fq_type'];
        $titleLower = strtolower($title);
        
        // 檢查是否是組員/成員題目（會單獨處理）
        if (strpos($titleLower, '組員') !== false || strpos($titleLower, '成員') !== false || 
            strpos($titleLower, '團隊成員') !== false || strpos($titleLower, 'team member') !== false ||
            strpos($titleLower, '小組成員') !== false) {
            continue; // 組員會單獨處理
        }
        
        // 如果答案為空，嘗試自動填入
        if (empty($value) || (is_array($value) && empty($value))) {
            // 檢查是否是班級相關欄位
            if (strpos($titleLower, '班級') !== false || strpos($titleLower, 'class') !== false) {
                if (!empty($teamMembers) && !empty($teamMembers[0]['class_name'])) {
                    $value = $teamMembers[0]['class_name'];
                }
            }
            // 檢查是否是團隊名稱/專題名稱相關欄位
            elseif (strpos($titleLower, '團隊名稱') !== false || 
                    strpos($titleLower, '專題名稱') !== false ||
                    strpos($titleLower, '專題標題') !== false ||
                    (strpos($titleLower, '團隊') !== false && strpos($titleLower, '成員') === false) ||
                    strpos($titleLower, 'team') !== false) {
                if (!empty($submission['team_project_name'])) {
                    $value = $submission['team_project_name'];
                }
            }
            // 檢查是否是類組相關欄位
            elseif (strpos($titleLower, '類組') !== false || strpos($titleLower, '組別') !== false) {
                if (!empty($submission['group_name'])) {
                    $value = $submission['group_name'];
                }
            }
            // 檢查是否是提交人相關欄位
            elseif (strpos($titleLower, '提交人') !== false || strpos($titleLower, '申請人') !== false) {
                if (!empty($submission['submitter_name'])) {
                    $value = $submission['submitter_name'];
                }
            }
        }
        
        // 解析答案（如果是 JSON 格式）
        if (is_string($value) && (substr($value, 0, 1) === '[' || substr($value, 0, 1) === '{')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        
        // 檢查是否是指導老師相關欄位，如果是帳號則轉換為名字
        if ((strpos($titleLower, '指導老師') !== false || strpos($titleLower, 'advisor') !== false || strpos($titleLower, 'teacher') !== false) 
            && !empty($value) && !is_array($value)) {
            // 如果值是帳號格式（通常是英文字母和數字），嘗試查詢名字
            $teacherID = $value;
            if (preg_match('/^[a-zA-Z0-9_]+$/', $teacherID)) {
                try {
                    $stmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                    $stmt->execute([$teacherID]);
                    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($teacher && !empty($teacher['u_name'])) {
                        $value = $teacher['u_name'];
                    }
                } catch (Exception $e) {
                    // 如果查詢失敗，保持原值
                }
            }
        }
        
        // 檢查是否是班級相關欄位，如果是 ID 則轉換為名稱
        if ((strpos($titleLower, '班級') !== false || strpos($titleLower, 'class') !== false) 
            && !empty($value) && !is_array($value) && is_numeric($value)) {
            try {
                $stmt = $conn->prepare("SELECT c_name FROM classdata WHERE c_ID = ?");
                $stmt->execute([$value]);
                $class = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($class && !empty($class['c_name'])) {
                    $value = $class['c_name'];
                }
            } catch (Exception $e) {
                // 如果查詢失敗，保持原值
            }
        }
        
        // 檢查是否是組別/類組相關欄位，如果是 ID 則轉換為名稱
        if ((strpos($titleLower, '組別') !== false || strpos($titleLower, '類組') !== false || strpos($titleLower, 'group') !== false) 
            && !empty($value) && !is_array($value) && is_numeric($value)) {
            try {
                $stmt = $conn->prepare("SELECT group_name FROM groupdata WHERE group_ID = ?");
                $stmt->execute([$value]);
                $group = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($group && !empty($group['group_name'])) {
                    $value = $group['group_name'];
                }
            } catch (Exception $e) {
                // 如果查詢失敗，保持原值
            }
        }
        
        // 檢查是否是歷屆/屆別相關欄位，如果是 ID 則轉換為名稱
        if ((strpos($titleLower, '歷屆') !== false || strpos($titleLower, '屆別') !== false || strpos($titleLower, 'cohort') !== false) 
            && !empty($value) && !is_array($value) && is_numeric($value)) {
            try {
                $stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ?");
                $stmt->execute([$value]);
                $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($cohort && !empty($cohort['cohort_name'])) {
                    $value = $cohort['cohort_name'];
                }
            } catch (Exception $e) {
                // 如果查詢失敗，保持原值
            }
        }
        
        $processedQuestions[] = [
            'title' => $title,
            'value' => $value,
            'type' => $type,
            'required' => $q['fq_required']
        ];
    }
    ?>
    
    <!-- 動態顯示所有題目 -->
    <?php foreach ($processedQuestions as $q): ?>
    <div class="form-section">
        <div class="form-field-two-col">
            <div class="field-label-col">
                <?= htmlspecialchars($q['title']) ?>
                <?php if ($q['required'] == 1): ?>
                <span style="color: red;">*</span>
                <?php endif; ?>
            </div>
            <div class="field-value-col">
                <?php if (empty($q['value']) || (is_array($q['value']) && empty($q['value']))): ?>
                    <!-- 空值顯示底線 -->
                    <div class="field-line"></div>
                <?php else: ?>
                    <!-- 根據題目類型顯示不同格式 -->
                    <?php if ($q['type'] === 'textarea'): ?>
                        <!-- 多行文字顯示為框框 -->
                        <div class="field-value-box">
                            <?= nl2br(htmlspecialchars(is_array($q['value']) ? implode("\n", $q['value']) : $q['value'])) ?>
                        </div>
                    <?php elseif ($q['type'] === 'checkbox'): ?>
                        <!-- 複選框顯示為選中的項目 -->
                        <div class="field-value-text">
                            <?= htmlspecialchars(implode('、', is_array($q['value']) ? $q['value'] : [$q['value']])) ?>
                        </div>
                    <?php elseif ($q['type'] === 'radio' || $q['type'] === 'select'): ?>
                        <!-- 單選或下拉選單顯示選中的值 -->
                        <div class="field-value-text">
                            <?= htmlspecialchars(is_array($q['value']) ? implode('、', $q['value']) : $q['value']) ?>
                        </div>
                    <?php else: ?>
                        <!-- 一般文字顯示底線 -->
                        <div class="field-value-text">
                            <?= htmlspecialchars(is_array($q['value']) ? implode('、', $q['value']) : $q['value']) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <!-- 組員表格（如果有團隊成員） -->
    <?php if (!empty($teamMembers)): ?>
    <div class="form-section">
        <div class="form-field-two-col">
            <div class="field-label-col">小組成員</div>
            <div class="field-value-col">
                <div style="font-size: 12pt; color: #666; margin-bottom: 5px;">（請註明班級、學號、姓名）</div>
                <table class="form-table">
                    <thead>
                        <tr>
                            <th style="width: 33%; text-align: center;">班級</th>
                            <th style="width: 33%; text-align: center;">學號</th>
                            <th style="width: 34%; text-align: center;">姓名</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teamMembers as $member): ?>
                        <tr>
                            <td style="text-align: center;"><?= htmlspecialchars($member['class_name'] ?? '') ?></td>
                            <td style="text-align: center;"><?= htmlspecialchars($member['u_ID']) ?></td>
                            <td style="text-align: center;"><?= htmlspecialchars($member['u_name'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 指導老師簽名（如果有指導老師相關欄位） -->
    <?php
    $hasAdvisor = false;
    foreach ($processedQuestions as $q) {
        if (strpos(strtolower($q['title']), '指導老師') !== false || 
            strpos(strtolower($q['title']), 'advisor') !== false) {
            $hasAdvisor = true;
            break;
        }
    }
    if ($hasAdvisor):
    ?>
    <div class="form-section">
        <div class="form-field-two-col">
            <div class="field-label-col">指導老師簽名</div>
            <div class="field-value-col">
                <div class="field-line"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php
}

/**
 * 根據表單類別選擇模板
 * 優先使用 AI 識別範例文件，如果沒有則使用動態模板
 */
function renderFormByCategory($submission, $questions, $teamMembers) {
    global $conn;
    
    // 檢查是否有範例文件
    $formExample = $submission['form_example'] ?? '';
    
    if (!empty($formExample)) {
        // 構建範例文件完整路徑
        $examplePath = __DIR__ . '/../' . $formExample;
        
        // 如果範例文件存在，使用 AI 識別
        if (file_exists($examplePath)) {
            return recognizeTemplateWithAI($examplePath, $questions, $submission, $teamMembers);
        }
    }
    
    // 如果沒有範例文件或檔案不存在，使用動態模板
    return renderDynamicForm($submission, $questions, $teamMembers);
}
