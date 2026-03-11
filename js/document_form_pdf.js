(function () {
    var fileName = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.fileName) || 'document.pdf';
    var download = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.download) || false;
    var exportMode = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.exportMode) || false;
    var showSignedPdfPreview = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.showSignedPdfPreview) || false;
    var pdfBlobUrl = null;
    var attachmentPdfUrl = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.attachmentPdfUrl) || '';
    var supplementPdfUrl = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.supplementPdfUrl) || '';
    var versionPayload = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.versionPayload) || '';
    var versionFooterText = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.versionFooterText) || '';
    var documentId = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.documentId) || 0;
    var submissionId = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.submissionId) || 0;
    var pathname = window.location.pathname || '';

    // 保存原始PDF到服务器（透過 apply_test_upload_original.php），寫入 uploads/original/ 並更新 original_pdf_path / qr_modified_at / snapshot_token
    function saveOriginalPdfToServer(blob) {
        return new Promise(function (resolve, reject) {
            if (!submissionId || submissionId === '0') {
                resolve(null);
                return;
            }
            var formData = new FormData();
            formData.append('original_pdf', blob, fileName);
            formData.append('doc_ID', documentId);
            formData.append('sub_ID', submissionId);
            var snapToken = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.snapshotToken) || '';
            // 後端仍使用 snapshot_token 做技術性核實，但前端不再顯示「版本碼」字樣

            // 頁面在 /pages/ 底下時，直接呼叫同層的 apply_test_upload_original.php；
            // 否則從根目錄進入時，改呼叫 pages/apply_test_upload_original.php。
            var saveUrl = pathname.includes('/pages/')
                ? 'apply_test_upload_original.php'
                : 'pages/apply_test_upload_original.php';
            fetch(saveUrl, {
                method: 'POST',
                body: formData
            }).then(function (res) {
                return res.text();
            }).then(function (text) {
                var data = null;
                try {
                    data = (text && text.trim()) ? JSON.parse(text) : null;
                } catch (e) {
                    console.error('保存原始PDF：後端回傳非 JSON，內容如下：', text);
                    resolve(null);
                    return;
                }
                if (data && data.ok) {
                    console.log('原始PDF已保存到服务器:', data.original_pdf_path, 'sub_ID:', data.sub_ID || submissionId);
                    // 儲存 sub_ID / snapshot_token 供簽名上傳時一併送出（同步到父視窗 / 開啟者，僅作技術用途）
                    if (typeof window !== 'undefined') {
                        if (data.sub_ID) {
                            window.CURRENT_SUB_ID = data.sub_ID;
                            try {
                                if (window.parent && window.parent !== window) {
                                    window.parent.CURRENT_SUB_ID = data.sub_ID;
                                }
                                if (window.opener && !window.opener.closed) {
                                    window.opener.CURRENT_SUB_ID = data.sub_ID;
                                }
                            } catch (e) { /* ignore */ }
                        }
                        if (data.snapshot_token) {
                            window.SNAPSHOT_TOKEN = data.snapshot_token;
                            try {
                                if (window.parent && window.parent !== window) {
                                    window.parent.SNAPSHOT_TOKEN = data.snapshot_token;
                                }
                                if (window.opener && !window.opener.closed) {
                                    window.opener.SNAPSHOT_TOKEN = data.snapshot_token;
                                }
                            } catch (e) { /* same-origin 保護失敗時忽略 */ }
                        }
                    }
                    resolve(data);
                } else {
                    if (data && (data.msg || data.message)) console.warn('保存原始PDF失败:', data.msg || data.message);
                    resolve(null);
                }
            }).catch(function (err) {
                console.error('保存原始PDF錯誤:', err);
                resolve(null);
            });
        });
    }

    // 在補充附件之後新增一頁，放置三軌驗證資訊（純文字版本）
    function addVersionFooterPage(blob) {
        if (!PDFDocClass) return Promise.resolve(blob);
        var footerText = versionFooterText || '';
        // 不再使用 QR Code，僅以三軌文字作為核實依據
        if (!footerText.trim()) return Promise.resolve(blob);

        return (blob.arrayBuffer ? blob.arrayBuffer() : Promise.resolve(blob)).then(function (buf) {
            var ab = buf instanceof ArrayBuffer ? buf : (buf.buffer || buf);
            return PDFDocClass.load(ab);
        }).then(function (pdf) {
            var pages = pdf.getPages();
            if (pages.length === 0) return pdf.save(); // 防止空文件
            // 一律新增一頁專用於三軌驗證，不論有無補充附件，格式一致
            var refPage = pages[0];
            var pageW = refPage.getWidth();
            var pageH = refPage.getHeight();
            var newPage = pdf.addPage([pageW, pageH]);
            var page = newPage;

            var margin = 50;
            var fontSize = 12;
            var lineHeight = fontSize * 1.6;

            // 盡量使用標準字型，確保為真正的文字層（可選取複製）
            var fontPromise;
            if (typeof PDFLib !== 'undefined' && PDFLib.StandardFonts && typeof pdf.embedFont === 'function') {
                fontPromise = pdf.embedFont(PDFLib.StandardFonts.Helvetica);
            } else {
                fontPromise = Promise.resolve(null);
            }

            return fontPromise.then(function (font) {
                var lines = (footerText || '').split(/\r?\n/).filter(Boolean);
                if (lines.length === 0) {
                    return pdf.save();
                }

                // 從頁面下方往上排，避免被裁切
                var y = pageH - margin;
                y -= lineHeight; // 第一行

                lines.forEach(function (line) {
                    if (y < margin) return;
                    page.drawText(line, {
                        x: margin,
                        y: y,
                        size: fontSize,
                        font: font || undefined
                    });
                    y -= lineHeight;
                });

                return pdf.save();
            });
        }).then(function (bytes) {
            return new Blob([bytes], { type: 'application/pdf' });
        }).catch(function (err) {
            console.warn('addVersionFooterPage 失敗:', err);
            return blob;
        });
    }

    function injectVersionMetadata(blob, payload) {
        var PDFDoc = (typeof PDFDocument !== 'undefined' && typeof PDFDocument.load === 'function')
            ? PDFDocument
            : (typeof PDFLib !== 'undefined' && PDFLib.PDFDocument) ? PDFLib.PDFDocument : undefined;
        if (!payload || !PDFDoc) return Promise.resolve(blob);
        var asciiPayload = typeof payload === 'string' ? payload.replace(/[^\x00-\x7F]/g, '') : String(payload);
        if (!asciiPayload) return Promise.resolve(blob);
        return (blob.arrayBuffer ? blob.arrayBuffer() : Promise.resolve(blob)).then(function (buf) {
            var ab = buf instanceof ArrayBuffer ? buf : (buf.buffer || buf);
            return PDFDoc.load(ab).then(function (pdf) {
                pdf.setKeywords(asciiPayload);
                return pdf.save();
            });
        }).then(function (bytes) {
            return new Blob([bytes], { type: 'application/pdf' });
        }).catch(function (err) {
            console.warn('injectVersionMetadata 失敗，PDF 將不含版本 Keywords:', err && err.message ? err.message : err);
            return blob;
        });
    }

    var qrInitPromise = Promise.resolve();

    function getPdfOpt() {
        return {
            margin: [30, 30, 30, 30],
            filename: fileName,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, logging: false },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait', compress: true },
            pagebreak: { mode: ['css', 'legacy'] }
        };
    }

    function downloadBlob(blob, name) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = name || fileName;
        a.click();
        URL.revokeObjectURL(url);
    }

    var PDFDocClass = (typeof PDFDocument !== 'undefined' && typeof PDFDocument.load === 'function')
        ? PDFDocument
        : (typeof PDFLib !== 'undefined' && PDFLib.PDFDocument) ? PDFLib.PDFDocument : undefined;

    /**
     * 合併附件 PDF。
     * @param {Blob} blob 當前主 PDF Blob
     * @param {string} url 欲合併的 PDF URL
     * @returns {Promise<Blob>} 合併後的 Blob
     */
    function _mergePdfUrl(blob, url) {
        if (!url || !PDFDocClass) return Promise.resolve(blob);
        return fetch(url, { credentials: 'include' }).then(function (res) { return res.ok ? res.arrayBuffer() : Promise.reject(); })
            .then(function (targetBuf) {
                return PDFDocClass.load(targetBuf).then(function (targetPdf) {
                    return (blob.arrayBuffer ? blob.arrayBuffer() : Promise.resolve(blob)).then(function (mainBuf) {
                        return PDFDocClass.load(mainBuf).then(function (mainPdf) {
                            var indices = targetPdf.getPageIndices();
                            return indices.reduce(function (prev, pageIndex) {
                                return prev.then(function () {
                                    return mainPdf.copyPages(targetPdf, [pageIndex]).then(function (copiedPages) {
                                        if (copiedPages && copiedPages[0]) mainPdf.addPage(copiedPages[0]);
                                    });
                                });
                            }, Promise.resolve()).then(function () {
                                return mainPdf.save();
                            });
                        });
                    });
                });
            })
            .then(function (mergedBytes) {
                return new Blob([mergedBytes], { type: 'application/pdf' });
            })
            .catch(function (err) {
                console.warn('合併 PDF 失敗 (' + url + '):', err);
                return blob;
            });
    }

    // 取得需要合併到主 PDF 的附件 URL，並做去重：
    // - 同一路徑（忽略查詢參數與尾端斜線）只保留一次
    // - 比較時一律轉小寫，避免大小寫差異造成重複
    function _normalizeUrlForDedupe(url) {
        if (!url) return '';
        try {
            var loc = window.location || {};
            var base = (loc.origin && typeof loc.origin === 'string') ? loc.origin : (loc.protocol ? (loc.protocol + '//' + (loc.host || '')) : '');
            var u = new URL(url, base || undefined);
            return (u.pathname || '').replace(/\/+$/, '').toLowerCase();
        } catch (e) {
            var noQuery = String(url).split('?')[0] || '';
            return noQuery.replace(/\/+$/, '').toLowerCase();
        }
    }

    function _buildMergeTargetUrls() {
        var urls = [];
        var seen = {};
        [attachmentPdfUrl, supplementPdfUrl].forEach(function (u) {
            if (!u) return;
            var key = _normalizeUrlForDedupe(u);
            if (!key || seen[key]) return;
            seen[key] = true;
            urls.push(u);
        });
        return urls;
    }

    function exportPdf() {
        if (pdfBlobUrl) {
            downloadBlob(pdfBlobUrl, fileName);
            return;
        }

        // 🎯 呼叫 generate_original_pdf.php 先產生簽名前 PDF 與伺服器快照（含 qr_modified_at / snapshot_token）
        var qrAjaxUrl = pathname.includes('/pages/') ? 'generate_original_pdf.php' : 'pages/generate_original_pdf.php';
        fetch(qrAjaxUrl, {
            method: 'POST',
            body: new URLSearchParams({ sub_ID: submissionId, doc_ID: documentId })
        }).then(function (res) { return res.json(); }).then(function (data) {
            if (data && data.ok) {
                var snap = data.snapshot_token || data.snapshot;
                if (typeof window !== 'undefined') {
                    if (data.sub_ID) {
                        window.CURRENT_SUB_ID = data.sub_ID;
                        try {
                            if (window.parent && window.parent !== window) {
                                window.parent.CURRENT_SUB_ID = data.sub_ID;
                            }
                            if (window.opener && !window.opener.closed) {
                                window.opener.CURRENT_SUB_ID = data.sub_ID;
                            }
                        } catch (e) { /* ignore */ }
                    }
                    if (data.snapshot_token) {
                        // 儲存 snapshot_token 供簽名上傳時一併送出（同步到父視窗 / 開啟者，僅作技術用途）
                        window.SNAPSHOT_TOKEN = data.snapshot_token;
                        try {
                            if (window.parent && window.parent !== window) {
                                window.parent.SNAPSHOT_TOKEN = data.snapshot_token;
                            }
                            if (window.opener && !window.opener.closed) {
                                window.opener.SNAPSHOT_TOKEN = data.snapshot_token;
                            }
                        } catch (e) { /* same-origin 保護失敗時忽略 */ }
                    }
                }
                // 若仍有需要顯示「最後修改時間」文字，可在這裡更新 footer 內容
                if (snap) {
                    var lines = (versionFooterText || '').split('\n');
                    if (lines[1] && lines[1].includes('最後修改時間')) {
                        var pipeIdx = lines[1].indexOf(' | ');
                        var suffix = (pipeIdx >= 0) ? lines[1].slice(pipeIdx) : '';
                        lines[1] = '最後修改時間：' + snap + suffix;
                        versionFooterText = lines.join('\n');
                    }
                }
            }
            // 繼續產 PDF
            _doExportPdf();
        }).catch(function (err) {
            console.warn('QR 預產生失敗，採用預設時間繼續：', err);
            _doExportPdf();
        });
    }

    function _doExportPdf() {

        // 2026-03 修正：隱藏 HTML 中的三軌驗證區塊，避免與 JS 生成的後半部專屬頁面重複
        var footerElements = document.querySelectorAll('.pdf-footer-info, .no-pdf-duplicate, .no-print-pdf');
        footerElements.forEach(function (el) { el.style.display = 'none'; });

        var generatePdf = function () {
            var el = document.getElementById('pdfContent');
            if (!el) {
                alert('找不到 PDF 內容區塊，請重新整理頁面後再試一次。');
                return;
            }

            // 確保匯出時內容可見且在畫面上，避免被先前預覽流程設定為 hidden / 移出畫面導致匯出為空白頁
            var prevStyle = {
                visibility: el.style.visibility,
                position: el.style.position,
                left: el.style.left
            };
            el.style.visibility = 'visible';
            el.style.position = 'static';
            el.style.left = '';

            function restoreEl() {
                el.style.visibility = prevStyle.visibility;
                el.style.position = prevStyle.position;
                el.style.left = prevStyle.left;
            }

            // 2026-03 修正：附件與補充附件做去重（同一路徑只合併一次）
            var urlsToMerge = _buildMergeTargetUrls();

            var worker = html2pdf().set(getPdfOpt()).from(el).toContainer().toCanvas().toImg().toPdf();
            worker.output('blob').then(function (mainBlob) {
                var chain = Promise.resolve(mainBlob);

                urlsToMerge.forEach(function (url) {
                    chain = chain.then(function (currentBlob) {
                        return _mergePdfUrl(currentBlob, url);
                    });
                });

                chain = chain.then(addVersionFooterPage);
                chain = chain.then(function (blob) { return injectVersionMetadata(blob, versionPayload); });
                chain.then(function (finalBlob) {
                    if (exportMode && window.parent !== window) {
                        // 每次產生簽名前 PDF 都上傳並寫入 original_pdf_path，避免 DB 為 NULL
                        saveOriginalPdfToServer(finalBlob).then(function (result) {
                            if (result && result.snapshot_token && window.parent && window.parent !== window) {
                                try {
                                    window.parent.SNAPSHOT_TOKEN = result.snapshot_token;
                                    window.parent.CURRENT_SUB_ID = result.sub_ID;
                                } catch (e) { /* ignore */ }
                            }
                        }).catch(function () { /* 不阻擋下載 */ });
                        finalBlob.arrayBuffer().then(function (ab) {
                            try {
                                var payload = {
                                    type: 'document_form_pdf_blob',
                                    arrayBuffer: ab,
                                    fileName: fileName,
                                    versionPayload: versionPayload || '',
                                    versionFooterText: (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.versionFooterText) || ''
                                };
                                window.parent.postMessage(payload, '*');
                            } catch (e) {
                                console.error('exportMode postMessage failed', e);
                            }
                        }).catch(function (e) {
                            console.error('exportMode arrayBuffer failed', e);
                        });
                        return;
                    }
                    saveOriginalPdfToServer(finalBlob).then(function (result) {
                        downloadBlob(finalBlob, fileName);
                        restoreEl();
                    }).catch(function (err) {
                        downloadBlob(finalBlob, fileName);
                        restoreEl();
                    });
                }).catch(function (err) {
                    console.error(err);
                    alert('PDF 產生失敗，請使用列印功能（Ctrl+P）另存為 PDF。');
                    restoreEl();
                });
            }).catch(function (err) {
                console.error(err);
                alert('PDF 產生失敗，請使用列印功能（Ctrl+P）另存為 PDF。');
                restoreEl();
            });
        };

        var qrReady = qrInitPromise || Promise.resolve();
        qrReady.then(function () {
            setTimeout(generatePdf, 300);
        }).catch(function () {
            setTimeout(generatePdf, 300);
        });
    }

    function runPreviewPdf() {
        var el = document.getElementById('pdfContent');
        var wrap = document.getElementById('pdfPreviewWrap');
        var frame = document.getElementById('pdfPreviewFrame');
        var generating = document.getElementById('pdfGenerating');
        generating.style.display = 'flex';
        wrap.style.display = 'none';

        function showPdfBlob(blob) {
            if (pdfBlobUrl) URL.revokeObjectURL(pdfBlobUrl);
            pdfBlobUrl = URL.createObjectURL(blob);
            var iframePreview = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.iframePreview);
            var hash = '#zoom=70';
            frame.src = pdfBlobUrl + hash;
            generating.style.display = 'none';
            wrap.style.display = 'block';
            // 在 iframe 預覽模式下，PDF 產生後隱藏原始 HTML 內容，避免在檢視器下方看到表單畫面
            if (el && iframePreview) {
                el.style.display = 'none';
            }
        }

        function generatePreview() {
            var footerElements = document.querySelectorAll('.pdf-footer-info, .no-pdf-duplicate');
            footerElements.forEach(function (el) { el.style.display = 'none'; });

            var urlsToMerge = _buildMergeTargetUrls();

            var worker = html2pdf().set(getPdfOpt()).from(el).toContainer().toCanvas().toImg().toPdf();
            worker.output('blob').then(function (blob) {
                var chain = Promise.resolve(blob);

                urlsToMerge.forEach(function (url) {
                    chain = chain.then(function (currentBlob) {
                        return _mergePdfUrl(currentBlob, url);
                    });
                });

                chain = chain.then(addVersionFooterPage);
                chain = chain.then(function (blob) { return injectVersionMetadata(blob, versionPayload); });
                chain.then(function (finalBlob) {
                    saveOriginalPdfToServer(finalBlob).then(function (result) {
                        showPdfBlob(finalBlob);
                    }).catch(function (err) {
                        showPdfBlob(finalBlob);
                    });
                }).catch(function () {
                    showPdfBlob(blob);
                });
            }).catch(function (err) {
                console.error(err);
                generating.style.display = 'none';
            });
        }

        // 🎯 預覽前先同步伺服器時間與 QR
        var qrAjaxUrl = pathname.includes('/pages/') ? 'generate_original_pdf.php' : 'pages/generate_original_pdf.php';
        fetch(qrAjaxUrl, {
            method: 'POST',
            body: new URLSearchParams({ sub_ID: submissionId, doc_ID: documentId })
        }).then(function (res) { return res.json(); }).then(function (data) {
            if (data && data.ok) {
                if (data.snapshot_token || data.snapshot) {
                    var snap = data.snapshot_token || data.snapshot;
                    versionPayload = submissionId + '|' + documentId + '|' + snap;
                }
                // 一律從 API 回傳值更新 sub_ID / SNAPSHOT_TOKEN，供後續簽名上傳使用（同步到父視窗 / 開啟者）
                if (typeof window !== 'undefined') {
                    if (data.sub_ID) {
                        window.CURRENT_SUB_ID = data.sub_ID;
                        try {
                            if (window.parent && window.parent !== window) {
                                window.parent.CURRENT_SUB_ID = data.sub_ID;
                            }
                            if (window.opener && !window.opener.closed) {
                                window.opener.CURRENT_SUB_ID = data.sub_ID;
                            }
                        } catch (e) { /* ignore */ }
                    }
                    if (data.snapshot_token) {
                        window.SNAPSHOT_TOKEN = data.snapshot_token;
                        try {
                            if (window.parent && window.parent !== window) {
                                window.parent.SNAPSHOT_TOKEN = data.snapshot_token;
                            }
                            if (window.opener && !window.opener.closed) {
                                window.opener.SNAPSHOT_TOKEN = data.snapshot_token;
                            }
                        } catch (e) { /* same-origin 保護失敗時忽略 */ }
                    }
                }
            }
            var qrReady = qrInitPromise || Promise.resolve();
            qrReady.then(function () {
                setTimeout(generatePreview, 300);
            }).catch(function () {
                setTimeout(generatePreview, 300);
            });
        }).catch(function () {
            var qrReady = qrInitPromise || Promise.resolve();
            qrReady.then(function () {
                setTimeout(generatePreview, 300);
            }).catch(function () {
                setTimeout(generatePreview, 300);
            });
        });
    }

    var downloadBtn = document.getElementById('btnDownloadPdf');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', exportPdf);
    }

    var iframePreview = (window.DOC_PDF_CONFIG && window.DOC_PDF_CONFIG.iframePreview) || false;
    // 若為提交申請頁的核實彈窗內 iframe（簽名前 PDF 預覽），僅預覽、不自動下載
    if (iframePreview) {
        runPreviewPdf();
    } else if (exportMode && window.parent !== window) {
        // 被 apply_test 等以 iframe + export=1 載入時：直接產 PDF（含 versionFooterText 的 SNAPSHOT_TOKEN）並 postMessage 給父視窗
        setTimeout(_doExportPdf, 500);
    } else if (download) {
        // 直接進入 PDF 頁時才自動產生並下載
        setTimeout(exportPdf, 500);
    }
})();