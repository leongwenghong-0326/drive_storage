/**
 * AetherVault - Core JavaScript Client
 * Modular AJAX, Modals, Drag-and-Drop Uploads, and Interactivity
 */

document.addEventListener('DOMContentLoaded', function () {
    // Mobile sidebar toggle
    const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    const appSidebar = document.querySelector('.app-sidebar');

    if (sidebarToggleBtn && appSidebar) {
        sidebarToggleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            appSidebar.classList.toggle('show');
        });

        document.addEventListener('click', function (e) {
            if (window.innerWidth <= 992 && !appSidebar.contains(e.target) && !sidebarToggleBtn.contains(e.target)) {
                appSidebar.classList.remove('show');
            }
        });
    }

    // Initialize Toast Container if not present
    if (!document.getElementById('toastContainer')) {
        const toastDiv = document.createElement('div');
        toastDiv.id = 'toastContainer';
        toastDiv.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        toastDiv.style.zIndex = '1090';
        document.body.appendChild(toastDiv);
    }

    // Initialize Dropzone Upload if element exists
    initDropzone();
});

/**
 * Toast Notification Helper
 */
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const bgClass = type === 'success' ? 'bg-success' : (type === 'error' ? 'bg-danger' : (type === 'warning' ? 'bg-warning text-dark' : 'bg-primary'));
    const icon = type === 'success' ? 'fa-circle-check' : (type === 'error' ? 'fa-circle-xmark' : 'fa-circle-info');

    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center text-white ${bgClass} border-0 shadow-lg`;
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');

    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center py-2 px-3">
                <i class="fa-solid ${icon} me-2 fs-5"></i>
                <span class="small fw-medium">${message}</span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;

    container.appendChild(toastEl);
    const toast = new bootstrap.Toast(toastEl, { delay: 4000 });
    toast.show();

    toastEl.addEventListener('hidden.bs.toast', () => {
        toastEl.remove();
    });
}

/**
 * Universal AJAX Request Wrapper with CSRF header
 */
async function apiRequest(endpoint, data = {}, method = 'POST') {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    
    let options = {
        method: method,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken
        }
    };

    if (method === 'POST') {
        if (data instanceof FormData) {
            options.body = data;
            if (!data.has('csrf_token') && csrfToken) {
                data.append('csrf_token', csrfToken);
            }
        } else {
            options.headers['Content-Type'] = 'application/json';
            data.csrf_token = csrfToken;
            options.body = JSON.stringify(data);
        }
    }

    try {
        const response = await fetch(endpoint, options);
        const result = await response.json();
        return result;
    } catch (err) {
        console.error('API Request Failure:', err);
        return { success: false, message: 'Network request error. Please try again.' };
    }
}

/**
 * Toggle Star Status for File or Folder
 */
async function toggleStar(resourceType, resourceId, btnElement) {
    const res = await apiRequest('ajax.php', {
        action: 'toggle_star',
        type: resourceType,
        id: resourceId
    });

    if (res.success) {
        const matchedButtons = document.querySelectorAll(`.btn-star[data-type="${resourceType}"][data-id="${resourceId}"]`);
        if (matchedButtons.length > 0) {
            matchedButtons.forEach(btn => {
                btn.classList.toggle('starred', res.is_starred);
                const icon = btn.querySelector('i');
                if (icon) {
                    if (res.is_starred) {
                        icon.classList.remove('fa-regular');
                        icon.classList.add('fa-solid');
                    } else {
                        icon.classList.remove('fa-solid');
                        icon.classList.add('fa-regular');
                    }
                }
            });
        } else if (btnElement) {
            btnElement.classList.toggle('starred', res.is_starred);
            const icon = btnElement.querySelector('i');
            if (icon) {
                if (res.is_starred) {
                    icon.classList.remove('fa-regular');
                    icon.classList.add('fa-solid');
                } else {
                    icon.classList.remove('fa-solid');
                    icon.classList.add('fa-regular');
                }
            }
        }
        showToast(res.message, 'success');
    } else {
        showToast(res.message, 'error');
    }
}

/**
 * Open Rename Modal
 */
function openRenameModal(type, id, currentName) {
    document.getElementById('renameResourceType').value = type;
    document.getElementById('renameResourceId').value = id;
    document.getElementById('renameResourceInput').value = currentName;

    const modal = new bootstrap.Modal(document.getElementById('renameModal'));
    modal.show();
}

/**
 * Submit Rename Form
 */
async function submitRename() {
    const type = document.getElementById('renameResourceType').value;
    const id = document.getElementById('renameResourceId').value;
    const newName = document.getElementById('renameResourceInput').value.trim();

    if (!newName) {
        showToast('Name cannot be empty', 'warning');
        return;
    }

    const res = await apiRequest('ajax.php', {
        action: 'rename',
        type: type,
        id: id,
        new_name: newName
    });

    if (res.success) {
        showToast(res.message, 'success');
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(res.message, 'error');
    }
}

/**
 * Open Move Modal
 */
async function openMoveModal(type, id, name) {
    document.getElementById('moveResourceType').value = type;
    document.getElementById('moveResourceId').value = id;
    document.getElementById('moveTargetName').innerText = name;

    const folderSelect = document.getElementById('moveDestinationFolder');
    folderSelect.innerHTML = '<option value="">Loading available folders...</option>';

    const modal = new bootstrap.Modal(document.getElementById('moveModal'));
    modal.show();

    // Fetch folder tree
    const res = await apiRequest('ajax.php', {
        action: 'get_folder_tree',
        exclude_id: type === 'folder' ? id : 0
    });

    if (res.success) {
        folderSelect.innerHTML = '<option value="0">📁 Root / My Storage</option>';
        res.folders.forEach(f => {
            folderSelect.innerHTML += `<option value="${f.id}">${'— '.repeat(f.depth)}📁 ${f.name}</option>`;
        });
    } else {
        folderSelect.innerHTML = '<option value="0">📁 Root / My Storage</option>';
    }
}

/**
 * Submit Move
 */
async function submitMove() {
    const type = document.getElementById('moveResourceType').value;
    const id = document.getElementById('moveResourceId').value;
    const destFolderId = document.getElementById('moveDestinationFolder').value;

    const res = await apiRequest('ajax.php', {
        action: 'move',
        type: type,
        id: id,
        target_folder_id: destFolderId
    });

    if (res.success) {
        showToast(res.message, 'success');
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(res.message, 'error');
    }
}

/**
 * Confirm and Soft Delete Item (Move to Trash)
 */
async function moveToTrash(type, id, name) {
    if (!confirm(`Are you sure you want to move "${name}" to the Trash?`)) {
        return;
    }

    const res = await apiRequest('ajax.php', {
        action: 'trash_item',
        type: type,
        id: id
    });

    if (res.success) {
        showToast(res.message, 'success');
        setTimeout(() => location.reload(), 500);
    } else {
        showToast(res.message, 'error');
    }
}

/**
 * Restore Item from Trash
 */
async function restoreTrash(type, id) {
    const res = await apiRequest('ajax.php', {
        action: 'restore_item',
        type: type,
        id: id
    });

    if (res.success) {
        showToast(res.message, 'success');
        setTimeout(() => location.reload(), 500);
    } else {
        showToast(res.message, 'error');
    }
}

/**
 * Permanently Delete Item
 */
async function permanentDelete(type, id, name) {
    if (!confirm(`WARNING: This will permanently delete "${name}". This action CANNOT be undone. Proceed?`)) {
        return;
    }

    const res = await apiRequest('ajax.php', {
        action: 'permanent_delete',
        type: type,
        id: id
    });

    if (res.success) {
        showToast(res.message, 'success');
        setTimeout(() => location.reload(), 500);
    } else {
        showToast(res.message, 'error');
    }
}

/**
 * Empty Entire Trash
 */
async function emptyTrash() {
    if (!confirm('Are you completely sure you want to empty the Trash? All items will be permanently erased.')) {
        return;
    }

    const res = await apiRequest('ajax.php', {
        action: 'empty_trash'
    });

    if (res.success) {
        showToast(res.message, 'success');
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(res.message, 'error');
    }
}

/**
 * Open File Details Drawer/Modal
 */
async function openFileDetails(fileId) {
    const modalEl = document.getElementById('fileDetailsModal');
    if (!modalEl) return;

    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    document.getElementById('detailsLoading').classList.remove('d-none');
    document.getElementById('detailsContent').classList.add('d-none');

    const res = await apiRequest('ajax.php', {
        action: 'get_file_details',
        file_id: fileId
    });

    document.getElementById('detailsLoading').classList.add('d-none');

    if (res.success) {
        const f = res.file;
        document.getElementById('detailsFileName').innerText = f.original_name;
        document.getElementById('detailsFileSize').innerText = f.file_size_formatted;
        document.getElementById('detailsFileType').innerText = f.mime_type + ' (.' + f.extension + ')';
        document.getElementById('detailsFileFolder').innerText = f.folder_name || 'Root / My Storage';
        document.getElementById('detailsFileCreated').innerText = f.created_at;
        document.getElementById('detailsFileModified').innerText = f.updated_at;
        document.getElementById('detailsFileVersionCount').innerText = f.version_count;
        document.getElementById('detailsDownloadLink').href = 'download.php?id=' + f.id;
        
        // Tags list rendering
        const tagsWrapper = document.getElementById('detailsTagsWrapper');
        tagsWrapper.innerHTML = '';
        if (f.tags && f.tags.length > 0) {
            f.tags.forEach(t => {
                tagsWrapper.innerHTML += `<span class="badge" style="background-color: ${t.color}">${t.name}</span> `;
            });
        } else {
            tagsWrapper.innerHTML = '<span class="text-muted small">No tags attached</span>';
        }

        document.getElementById('detailsContent').classList.remove('d-none');
    } else {
        showToast(res.message, 'error');
        modal.hide();
    }
}

/**
 * Open Share Modal for File or Folder
 */
async function openShareModal(type, id, name) {
    document.getElementById('shareResourceType').value = type;
    document.getElementById('shareResourceId').value = id;
    document.getElementById('shareResourceTitle').innerText = name;
    document.getElementById('shareLinkSection').classList.add('d-none');
    document.getElementById('sharePassword').value = '';

    const modal = new bootstrap.Modal(document.getElementById('shareModal'));
    modal.show();

    // Check if active share already exists
    const res = await apiRequest('ajax.php', {
        action: 'get_share_info',
        type: type,
        id: id
    });

    if (res.success && res.share) {
        document.getElementById('shareLinkInput').value = res.share.share_url;
        document.getElementById('shareViewCount').innerText = res.share.view_count;
        document.getElementById('shareExpiresText').innerText = res.share.expires_text;
        document.getElementById('shareLinkSection').classList.remove('d-none');
    }
}

/**
 * Create or Update Share Link
 */
async function generateShareLink() {
    const type = document.getElementById('shareResourceType').value;
    const id = document.getElementById('shareResourceId').value;
    const password = document.getElementById('sharePassword').value;
    const expiration = document.getElementById('shareExpiration').value;

    const res = await apiRequest('ajax.php', {
        action: 'create_share',
        type: type,
        id: id,
        password: password,
        expiration: expiration
    });

    if (res.success) {
        document.getElementById('shareLinkInput').value = res.share_url;
        document.getElementById('shareViewCount').innerText = '0';
        document.getElementById('shareExpiresText').innerText = res.expires_text;
        document.getElementById('shareLinkSection').classList.remove('d-none');
        showToast('Share link created successfully!', 'success');
    } else {
        showToast(res.message, 'error');
    }
}

/**
 * Copy Share Link to Clipboard
 */
function copyShareLink() {
    const input = document.getElementById('shareLinkInput');
    input.select();
    navigator.clipboard.writeText(input.value);
    showToast('Share link copied to clipboard!', 'info');
}

/**
 * Initialize Drag-and-Drop and File Input Upload
 */
function initDropzone() {
    const fileInput = document.getElementById('fileUploadInput');
    const folderIdInput = document.getElementById('uploadFolderId');
    const progressBar = document.getElementById('uploadProgressBar');
    const progressContainer = document.getElementById('uploadProgressContainer');
    const dropzone = document.getElementById('uploadDropzone') || document.querySelector('.empty-state');

    if (!fileInput) return;

    // Support drag and drop if a dropzone or empty state container is present
    if (dropzone) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => dropzone.classList.add('dragover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => dropzone.classList.remove('dragover'), false);
        });

        dropzone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            if (dt && dt.files && dt.files.length > 0) {
                handleFiles(dt.files);
            }
        });
    }

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    // Always bind file input change event
    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files.length > 0) {
            handleFiles(fileInput.files);
        }
    });

    function handleFiles(files) {
        if (!files || !files.length) return;

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const baseUrl = document.querySelector('meta[name="base-url"]')?.getAttribute('content') || '';
        const folderId = folderIdInput ? folderIdInput.value : '';

        if (progressContainer) {
            progressContainer.classList.remove('d-none');
            if (progressBar) {
                progressBar.style.width = '0%';
                progressBar.innerText = '0%';
            }
        }

        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('folder_id', folderId);

        for (let i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
        }

        const uploadUrl = baseUrl ? (baseUrl.replace(/\/+$/, '') + '/upload.php') : 'upload.php';

        const xhr = new XMLHttpRequest();
        xhr.open('POST', uploadUrl, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable && progressBar) {
                const percent = Math.round((e.loaded / e.total) * 100);
                progressBar.style.width = percent + '%';
                progressBar.innerText = percent + '%';
            }
        };

        xhr.onload = function () {
            if (progressContainer) progressContainer.classList.add('d-none');
            // Reset input so the user can upload the same file again if needed
            fileInput.value = '';

            try {
                const res = JSON.parse(xhr.responseText);
                if (res.success) {
                    showToast(res.message || 'Files uploaded successfully!', 'success');
                    setTimeout(() => location.reload(), 700);
                } else {
                    showToast(res.message || 'Upload failed.', 'error');
                }
            } catch (err) {
                showToast('Upload error occurred.', 'error');
            }
        };

        xhr.onerror = function () {
            if (progressContainer) progressContainer.classList.add('d-none');
            fileInput.value = '';
            showToast('Network error during upload.', 'error');
        };

        xhr.send(formData);
    }
}
