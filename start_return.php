<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    exit('<div class="pickup-status-msg error">You must be logged in.</div>');
}

$userId   = (int)$_SESSION['user_id'];
$rentalId = isset($_GET['rental_id']) ? (int)$_GET['rental_id'] : 0;

if ($rentalId <= 0) {
    exit('<div class="pickup-status-msg error">Invalid rental.</div>');
}

$stmt = $pdo->prepare("
    SELECT r.RentalID, r.UserBorrowerID, r.UserLenderID, l.Title
    FROM TRentals r
    INNER JOIN TListings l ON r.ListingID = l.ListingID
    WHERE r.RentalID = ?
    LIMIT 1
");
$stmt->execute([$rentalId]);
$rental = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rental) {
    exit('<div class="pickup-status-msg error">Rental not found.</div>');
}

$userRole = null;
if ($userId === (int)$rental['UserBorrowerID'])      $userRole = 'borrower';
elseif ($userId === (int)$rental['UserLenderID'])    $userRole = 'lender';

if (!$userRole) {
    exit('<div class="pickup-status-msg error">You do not have access to this rental.</div>');
}
?>

<style>
.pu-card { padding: 4px 0 8px; }
.pu-card h3 { margin: 0 0 6px; font-size: 17px; font-weight: 700; color: #1a1a2e; }
.pu-card p  { margin: 0 0 14px; font-size: 13px; color: #6b7280; }

.pu-dropzone {
    border: 2px dashed #c7d2fe;
    border-radius: 12px;
    background: #f8f9ff;
    padding: 22px 16px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    margin-bottom: 10px;
    position: relative;
}
.pu-dropzone:hover, .pu-dropzone.drag-over {
    border-color: #667eea;
    background: #eef0ff;
}
.pu-dropzone i { font-size: 28px; color: #667eea; margin-bottom: 8px; display: block; }
.pu-dropzone-label { font-size: 13px; font-weight: 600; color: #667eea; margin-bottom: 3px; }
.pu-dropzone-hint  { font-size: 11px; color: #9ca3af; }

.pu-camera-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: white;
    border: 1.5px solid #e5e7eb;
    border-radius: 9px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    cursor: pointer;
    margin-bottom: 14px;
    transition: border-color 0.15s, color 0.15s;
}
.pu-camera-btn:hover { border-color: #667eea; color: #667eea; }
.pu-camera-btn i { font-size: 14px; }

.pu-preview-wrap { margin-bottom: 14px; }
.pu-preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    gap: 8px;
    margin-top: 8px;
}
.pu-preview-item {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    aspect-ratio: 1;
    background: #f3f4f6;
}
.pu-preview-item img,
.pu-preview-item video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.pu-preview-remove {
    position: absolute;
    top: 3px;
    right: 3px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: rgba(0,0,0,0.6);
    color: white;
    border: none;
    cursor: pointer;
    font-size: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

.pu-cam-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.85);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 12px;
}
.pu-cam-modal.open { display: flex; }
.pu-cam-modal video {
    max-width: min(420px, 90vw);
    max-height: 60vh;
    border-radius: 12px;
    background: black;
}
.pu-cam-btns { display: flex; gap: 10px; }
.pu-cam-capture {
    background: #667eea;
    color: white;
    border: none;
    border-radius: 9px;
    padding: 10px 22px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}
.pu-cam-close {
    background: white;
    color: #374151;
    border: none;
    border-radius: 9px;
    padding: 10px 22px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}
.pu-cam-err { color: #fca5a5; font-size: 13px; display: none; }

.pu-actions { display: flex; gap: 10px; }
.pu-status { margin-top: 10px; font-size: 13px; font-weight: 600; border-radius: 8px; padding: 8px 12px; display: none; }
.pu-status.success { background: #d1fae5; color: #065f46; display: block; }
.pu-status.error   { background: #fee2e2; color: #991b1b; display: block; }
</style>

<div class="pu-card">
    <h3>Return Item</h3>
    <p>Add photos or videos to confirm return for <strong><?php echo htmlspecialchars($rental['Title']); ?></strong>.</p>

    <form id="returnForm" enctype="multipart/form-data">
        <input type="hidden" name="rental_id" value="<?php echo (int)$rental['RentalID']; ?>">
        <input type="hidden" name="user_role"  value="<?php echo htmlspecialchars($userRole); ?>">

        <input type="file" id="returnFileInput" name="return_media[]"
               accept="image/*,video/*" multiple style="display:none;">

        <div class="pu-dropzone" id="returnDropzone">
            <i class="fas fa-cloud-upload-alt"></i>
            <div class="pu-dropzone-label">Drag &amp; drop photos or videos here</div>
            <div class="pu-dropzone-hint">or click to browse &nbsp;·&nbsp; JPG, PNG, MP4, MOV</div>
        </div>

        <button type="button" class="pu-camera-btn" id="returnCameraBtn">
            <i class="fas fa-camera"></i> Take a Photo
        </button>

        <div class="pu-preview-wrap" id="returnPreviewWrap" style="display:none;">
            <div class="pu-preview-grid" id="returnPreviewGrid"></div>
        </div>

        <div class="pu-actions">
            <button type="submit" class="pickup-btn pickup-btn-primary">Confirm Return</button>
            <button type="button" class="return-btn return-btn-secondary"
                    onclick="document.getElementById('closeReturnModal').click()">Cancel</button>
        </div>

        <div class="pu-status" id="returnStatusMsg"></div>
    </form>
</div>

<div class="pu-cam-modal" id="returnCamModal">
    <video id="returnCamVideo" autoplay playsinline muted></video>
    <div class="pu-cam-err" id="returnCamErr"></div>
    <div class="pu-cam-btns">
        <button type="button" class="pu-cam-capture" id="returnCamCapture">
            <i class="fas fa-camera"></i> Capture
        </button>
        <button type="button" class="pu-cam-close" id="returnCamClose">Cancel</button>
    </div>
</div>
<canvas id="returnCamCanvas" style="display:none;"></canvas>

<script>
(function () {
    let selectedFiles = [];

    const form        = document.getElementById('returnForm');
    const fileInput   = document.getElementById('returnFileInput');
    const dropzone    = document.getElementById('returnDropzone');
    const previewWrap = document.getElementById('returnPreviewWrap');
    const previewGrid = document.getElementById('returnPreviewGrid');
    const statusMsg   = document.getElementById('returnStatusMsg');
    const cameraBtn   = document.getElementById('returnCameraBtn');
    const camModal    = document.getElementById('returnCamModal');
    const camVideo    = document.getElementById('returnCamVideo');
    const camCanvas   = document.getElementById('returnCamCanvas');
    const camCapture  = document.getElementById('returnCamCapture');
    const camClose    = document.getElementById('returnCamClose');
    const camErr      = document.getElementById('returnCamErr');

    let cameraStream = null;

    function syncInput() {
        const dt = new DataTransfer();
        selectedFiles.forEach(f => dt.items.add(f));
        fileInput.files = dt.files;
    }

    function renderPreviews() {
        previewGrid.innerHTML = '';
        if (selectedFiles.length === 0) { previewWrap.style.display = 'none'; return; }
        previewWrap.style.display = 'block';

        selectedFiles.forEach((file, idx) => {
            const wrap = document.createElement('div');
            wrap.className = 'pu-preview-item';

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'pu-preview-remove';
            removeBtn.innerHTML = '&times;';
            removeBtn.addEventListener('click', () => {
                selectedFiles.splice(idx, 1);
                syncInput();
                renderPreviews();
            });

            const reader = new FileReader();
            reader.onload = e => {
                let el;
                if (file.type.startsWith('video/')) {
                    el = document.createElement('video');
                    el.src = e.target.result;
                    el.muted = true;
                    el.preload = 'metadata';
                } else {
                    el = document.createElement('img');
                    el.src = e.target.result;
                }
                wrap.appendChild(el);
                wrap.appendChild(removeBtn);
                previewGrid.appendChild(wrap);
            };
            reader.readAsDataURL(file);
        });
    }

    function addFiles(files) {
        Array.from(files).forEach(f => {
            if (selectedFiles.length < 10) selectedFiles.push(f);
        });
        syncInput();
        renderPreviews();
    }

    dropzone.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => { addFiles(fileInput.files); fileInput.value = ''; });

    dropzone.addEventListener('dragover',  e => { e.preventDefault(); dropzone.classList.add('drag-over'); });
    dropzone.addEventListener('dragleave', ()  => dropzone.classList.remove('drag-over'));
    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('drag-over');
        if (e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
    });

    function stopCamera() {
        if (cameraStream) { cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null; }
        camVideo.srcObject = null;
        camModal.classList.remove('open');
    }

    cameraBtn.addEventListener('click', () => {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            camErr.textContent = 'Camera not supported on this device.';
            camErr.style.display = 'block';
            return;
        }
        camErr.style.display = 'none';
        camModal.classList.add('open');
        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
            .then(s => { cameraStream = s; camVideo.srcObject = s; })
            .catch(err => {
                camModal.classList.remove('open');
                camErr.textContent = err.name === 'NotAllowedError'
                    ? 'Camera permission denied. Check your browser settings.'
                    : 'Could not access camera.';
                camErr.style.display = 'block';
            });
    });

    camClose.addEventListener('click', stopCamera);
    camModal.addEventListener('click', e => { if (e.target === camModal) stopCamera(); });

    camCapture.addEventListener('click', () => {
        if (!cameraStream) return;
        camCanvas.width  = camVideo.videoWidth;
        camCanvas.height = camVideo.videoHeight;
        camCanvas.getContext('2d').drawImage(camVideo, 0, 0);
        stopCamera();
        camCanvas.toBlob(blob => {
            if (!blob) return;
            const file = new File([blob], 'return_' + Date.now() + '.jpg', { type: 'image/jpeg' });
            addFiles([file]);
        }, 'image/jpeg', 0.92);
    });

    form.addEventListener('submit', e => {
        e.preventDefault();

        if (selectedFiles.length === 0) {
            statusMsg.className = 'pu-status error';
            statusMsg.textContent = 'Please add at least one photo or video.';
            return;
        }

        const formData = new FormData(form);
        formData.delete('return_media[]');
        selectedFiles.forEach(f => formData.append('return_media[]', f));

        fetch('save_return_photo.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                statusMsg.className = 'pu-status ' + (data.success ? 'success' : 'error');
                statusMsg.textContent = data.message || (data.success ? 'Return saved.' : 'Something went wrong.');
                if (data.success) {
                    const rentalId = formData.get('rental_id');
                    const userRole = formData.get('user_role');
                    setTimeout(() => {
                        const modal = document.getElementById('returnModal');
                        const content = document.getElementById('returnModalContent');
                        if (modal) modal.classList.remove('active');
                        if (content) content.innerHTML = '';
                        const completed = (data.message || '').toLowerCase().includes('completed successfully');
                        if (completed) { location.reload(); return; }
                        updateReturnButtonState(rentalId, userRole, data.message);
                    }, 500);
                }
            })
            .catch(() => {
                statusMsg.className = 'pu-status error';
                statusMsg.textContent = 'Something went wrong saving the return.';
            });
    });
})();
</script>
