jQuery(document).ready(function($) {
    const startControls = $('#gssa-start-controls');
    const startPreButton = $('#gssa-start-pre-analysis');
    const startPostButton = $('#gssa-start-post-analysis');
    const startOpeningPriceButton = $('#gssa-start-opening-price-analysis');
    const startBothButton = $('#gssa-start-both-analysis');
    const resumeControls = $('#gssa-resume-controls');
    const resumeButton = $('#gssa-resume-analysis');
    const startFreshButton = $('#gssa-start-fresh-analysis');
    const runningControls = $('#gssa-running-controls');
    const stopButton = $('#gssa-stop-analysis');
    const manualTriggerButton = $('#gssa-manual-trigger');
    const statusDiv = $('#gssa-status');
    let statusInterval;

    $('#gssa-end-date').datepicker({
        dateFormat: 'yy-mm-dd'
    });

    // Butonların orijinal metinlerini başlangıçta sakla
    startControls.find('button').each(function() {
        $(this).data('original-text', $(this).text());
    });

    updateStatusView();

    function startProcess(isFresh, analysisMode, button) {
        if (isFresh) {
            analysisMode = 'both';
        }

        button.prop('disabled', true).text('Başlatılıyor...');
        startControls.find('button').prop('disabled', true);
        resumeControls.find('button').prop('disabled', true);
        statusDiv.html('İşlem başlatılıyor, lütfen bekleyin...');

        $.ajax({
            url: gssa_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'gssa_start_background_process',
                nonce: gssa_ajax_object.nonce,
                analysis_mode: analysisMode
            },
            success: function(response) {
                if (response && response.success) {
                    updateStatusView();
                } else {
                    let errorMessage = 'Bilinmeyen bir hata oluştu.';
                    if (response && response.data && response.data.message) {
                        errorMessage = response.data.message;
                    } else if (response && response.data) {
                        errorMessage = JSON.stringify(response.data);
                    }
                    statusDiv.html('<strong>Hata:</strong> ' + errorMessage);
                    updateStatusView();
                }
            },
            error: function(xhr) {
                let errorMsg = '<strong>Hata:</strong> Sunucuyla iletişim kurulamadı.';
                if (xhr.responseText) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if(response.data && response.data.message) {
                           errorMsg = '<strong>Hata:</strong> ' + response.data.message;
                        }
                    } catch (e) {
                         errorMsg += '<br><strong>Sunucu Yanıtı:</strong><pre>' + $('<div />').text(xhr.responseText).html() + '</pre>';
                    }
                }
                statusDiv.html(errorMsg);
                updateStatusView();
            }
        });
    }
    
    startPreButton.on('click', function() { startProcess(false, 'pre', $(this)); });
    startPostButton.on('click', function() { startProcess(false, 'post', $(this)); });
    startOpeningPriceButton.on('click', function() { startProcess(false, 'opening_price', $(this)); });
    startBothButton.on('click', function() { startProcess(false, 'both', $(this)); });

    startFreshButton.on('click', function() {
        if (confirm('Mevcut işlem ilerlemesi silinecek ve tüm analizler (pre, post ve açılış fiyatı) en baştan başlayacak. Emin misiniz?')) {
            startProcess(true, 'both', $(this));
        }
    });

    resumeButton.on('click', function() {
        $(this).prop('disabled', true);
        resumeControls.hide();
        statusDiv.html('İşleme devam ediliyor...');
        $.ajax({
            url: gssa_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'gssa_resume_background_process',
                nonce: gssa_ajax_object.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateStatusView();
                } else {
                    statusDiv.html('<strong>Hata:</strong> ' + (response.data.message || 'Bilinmeyen hata.'));
                    updateStatusView();
                }
            },
             error: function() {
                statusDiv.html('<strong>Hata:</strong> Devam etme isteği gönderilemedi.');
                updateStatusView();
            }
        });
    });

    stopButton.on('click', function() {
        $(this).prop('disabled', true).text('Durduruluyor...');
        clearInterval(statusInterval);
        $.ajax({
            url: gssa_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'gssa_stop_background_process',
                nonce: gssa_ajax_object.nonce
            },
            success: function(response) {
                updateStatusView();
            },
            error: function() {
                statusDiv.append('<br><strong>Hata:</strong> Durdurma isteği gönderilemedi.');
                updateStatusView();
            }
        });
    });

    manualTriggerButton.on('click', function() {
        $(this).prop('disabled', true).text('Tetikleniyor...');
        $.ajax({
            url: gssa_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'gssa_manual_trigger_cron',
                nonce: gssa_ajax_object.nonce
            },
            success: function(response) {
                // Sadece status view'ı yenilemek yeterli, o zaten logları çekecek
                updateStatusView();
            },
            error: function() {
                statusDiv.prepend('<strong>Hata:</strong> Manuel tetikleme isteği gönderilemedi.<br>');
            },
            complete: function() {
                // Butonu tekrar aktif et, ama hemen değil ki sürekli basılmasın
                setTimeout(function() {
                    manualTriggerButton.prop('disabled', false).text('Arka Plan İşlemini Manuel Tetikle');
                }, 2000);
            }
        });
    });

    function updateStatusView() {
        if (statusInterval) {
            clearInterval(statusInterval);
        }

        $.ajax({
            url: gssa_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'gssa_get_status_log',
                nonce: gssa_ajax_object.nonce
            },
            success: function(response) {
                if (response && response.success && response.data) {
                    const status = response.data.status;
                    const logContent = response.data.log;
                    const total = response.data.total || 0;
                    const processed = response.data.processed || 0;

                    let progressText = "";
                    if (total > 0 || status === 'running') {
                        let statusMessage = status === 'running' ? 'Çalışıyor' : 'Durdu';
                        progressText = `<div style="padding-bottom: 10px; border-bottom: 1px solid #ddd; margin-bottom: 10px; font-weight: bold;">Durum: ${statusMessage} [${processed} / ${total}]</div>`;
                    }

                    let logHtml = '';
                    if (logContent && logContent.trim() !== '') {
                        const escapedLog = $('<div />').text(logContent).html();
                        logHtml = escapedLog.replace(/\n/g, '<br>');
                    } else if (status === 'running') {
                        logHtml = 'İşlem çalışıyor, log çıktısı bekleniyor...';
                    } else {
                        logHtml = 'İşlem logu burada görünecek...';
                    }
                    
                    statusDiv.html(progressText + logHtml);
                    statusDiv.scrollTop(0);

                    // Kontrol butonlarının görünürlüğünü ayarla
                    startControls.hide();
                    resumeControls.hide();
                    runningControls.hide();

                    if (status === 'running') {
                        runningControls.show();
                        stopButton.prop('disabled', false).text('İşlemi Durdur');
                        manualTriggerButton.prop('disabled', false).text('Arka Plan İşlemini Manuel Tetikle');
                        statusInterval = setInterval(updateStatusView, 5000);
                    } else { // 'stopped'
                        const pendingExists = response.data.pending_exists;
                        startControls.show();
                        startControls.find('button').prop('disabled', false).each(function() {
                            $(this).text($(this).data('original-text'));
                        });
                        if (pendingExists) {
                            resumeControls.show();
                            resumeButton.prop('disabled', false);
                            startFreshButton.prop('disabled', false);
                        }
                    }
                } else {
                     let errorMessage = '<strong>Hata:</strong> Sunucudan geçersiz veya başarısız bir yanıt alındı. Bu genellikle sunucu tarafında bir PHP hatası olduğunu gösterir.<br><br><strong>Ham Sunucu Yanıtı:</strong><pre>' + JSON.stringify(response, null, 2) + '</pre>';
                     statusDiv.html(errorMessage);
                }
            },
            error: function(xhr, status, error) {
                let errorMsg = '<strong>Hata: Durum bilgisi sunucudan alınamadı.</strong>';
                errorMsg += '<br>HTTP Durumu: ' + xhr.status + ' ' + error;
                if (xhr.responseText) {
                    errorMsg += '<br><strong>Sunucu Yanıtı:</strong><pre>' + $('<div />').text(xhr.responseText).html() + '</pre>';
                }
                 errorMsg += '<br>Lütfen sayfayı yenileyip tekrar deneyin. Sorun devam ederse sunucu loglarını kontrol edin.';
                statusDiv.html(errorMsg);
                clearInterval(statusInterval);
            }
        });
    }
});

