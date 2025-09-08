jQuery(document).ready(function($) {
    const startControls = $('#gssa-start-controls');
    const startPreButton = $('#gssa-start-pre-analysis');
    const startPostButton = $('#gssa-start-post-analysis');
    const startBothButton = $('#gssa-start-both-analysis');
    const stopButton = $('#gssa-stop-analysis');
    const resumeControls = $('#gssa-resume-controls');
    const resumeButton = $('#gssa-resume-analysis');
    const startFreshButton = $('#gssa-start-fresh-analysis');
    const statusDiv = $('#gssa-status');
    let statusInterval;

    // Initialize Datepicker
    $('#gssa-end-date').datepicker({
        dateFormat: 'yy-mm-dd'
    });

    // Sayfa yüklendiğinde durumu kontrol et
    updateStatusView();

    function startProcess(isFresh, analysisMode, button) {
        // If it's a fresh start from the resume section, it should always be 'both'
        if (isFresh) {
            analysisMode = 'both';
        }

        button.prop('disabled', true).text('Başlatılıyor...');
        startControls.find('button').prop('disabled', true); // Disable all start buttons
        statusDiv.html('İşlem başlatılıyor, lütfen bekleyin...');

        $.ajax({
            url: gssa_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'gssa_start_background_process',
                nonce: gssa_ajax_object.nonce,
                analysis_mode: analysisMode // Pass the mode to backend
            },
            success: function(response) {
                if (response.success) {
                    updateStatusView();
                } else {
                    statusDiv.html('<strong>Hata:</strong> ' + response.data.message);
                    updateStatusView(); // Butonları eski haline getir
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
                         errorMsg += '<br><strong>Sunucu Yanıtı:</strong><pre>' + xhr.responseText + '</pre>';
                    }
                }
                statusDiv.html(errorMsg);
                updateStatusView(); // Butonları eski haline getir
            }
        });
    }
    
    startPreButton.on('click', function() {
        startProcess(false, 'pre', $(this));
    });
    startPostButton.on('click', function() {
        startProcess(false, 'post', $(this));
    });
    startBothButton.on('click', function() {
        startProcess(false, 'both', $(this));
    });
    startFreshButton.on('click', function() {
        if (confirm('Mevcut işlem ilerlemesi silinecek ve tüm analizler (pre ve post) en baştan başlayacak. Emin misiniz?')) {
            startProcess(true, 'both', $(this)); // isFresh is true, mode is 'both'
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
                    statusDiv.html('<strong>Hata:</strong> ' + response.data.message);
                    updateStatusView();
                }
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
                if (response.success) {
                    updateStatusView();
                } else {
                    updateStatusView();
                }
            },
            error: function() {
                statusDiv.append('<br><strong>Hata:</strong> Durdurma isteği gönderilemedi.');
                updateStatusView();
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
                if (response.success) {
                    const logContent = response.data.log.join('\n');
                    statusDiv.text(logContent);
                    const status = response.data.status;
                    const currentIndex = response.data.currentIndex;
                    const totalSymbols = response.data.totalSymbols;

                    // Hide all controls first
                    startControls.hide();
                    stopButton.hide();
                    resumeControls.hide();

                    if (status === 'running' || status === 'error_retrying') {
                        stopButton.show().prop('disabled', false).text('İşlemi Durdur');
                        statusInterval = setInterval(updateStatusView, 5000);
                    } else { // stopped
                        if (currentIndex > 0 && currentIndex < totalSymbols) {
                            resumeControls.show();
                            resumeButton.prop('disabled', false);
                            startFreshButton.prop('disabled', false);
                        } else {
                            startControls.show();
                            startControls.find('button').prop('disabled', false);
                            startPreButton.text('Sadece Pre-Market Analizi');
                            startPostButton.text('Sadece Post-Market Analizi');
                            startBothButton.text('Tüm Analizleri Başlat');
                        }
                    }
                }
            }
        });
    }
});
