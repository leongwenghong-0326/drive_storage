<?php
// Query current user tags for folder creation color palette
$userFolderTags = [];
if (auth_check()) {
    $authUid = $_SESSION['auth_user_id'] ?? null;
    if ($authUid) {
        try {
            $pdoFooter = Database::getConnection();
            $stmtTagList = $pdoFooter->prepare("SELECT id, name, color FROM tags WHERE user_id = ? ORDER BY name ASC");
            $stmtTagList->execute([$authUid]);
            $userFolderTags = $stmtTagList->fetchAll();
        } catch (Exception $e) {
            $userFolderTags = [];
        }
    }
}
?>
        </div><!-- End content-body -->
    </main><!-- End app-main -->
</div><!-- End app-wrapper -->

<!-- New Folder Modal -->
<div class="modal fade" id="newFolderModal" tabindex="-1" aria-labelledby="newFolderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: var(--radius-lg);">
            <form action="<?= BASE_URL ?>/ajax.php" method="POST" onsubmit="event.preventDefault(); submitNewFolder();">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold" id="newFolderModalLabel">
                        <i class="fa-solid fa-folder-plus text-primary me-2"></i> Create New Folder
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <input type="hidden" id="newFolderParentId" value="<?= (int)($currentFolderId ?? 0) ?>">
                    <input type="hidden" id="newFolderSelectedColor" value="#4f46e5">

                    <!-- Live Folder Icon Preview -->
                    <div class="d-flex align-items-center gap-3 mb-3 p-3 bg-light rounded border">
                        <i class="fa-solid fa-folder fa-2x" id="newFolderIconPreview" style="color: #4f46e5; transition: color 0.2s ease;"></i>
                        <div>
                            <div class="small fw-semibold text-dark">Selected Color Tag</div>
                            <div class="text-muted small" id="newFolderColorLabel">Default Indigo (#4f46e5)</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Folder Name</label>
                        <input type="text" id="newFolderName" class="form-control" placeholder="e.g. Project Documents" required autocomplete="off">
                    </div>

                    <!-- Custom Tags Palette Container -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold m-0">Your Custom Tags</label>
                            <a href="<?= BASE_URL ?>/tags.php" class="small text-primary text-decoration-none" style="font-size: 0.76rem;" target="_blank">
                                <i class="fa-solid fa-plus-circle me-1"></i>Manage Tags
                            </a>
                        </div>
                        <div class="d-flex flex-wrap gap-1 p-2 bg-light rounded border" id="folderCustomTagsContainer" style="min-height: 42px;">
                            <?php if (!empty($userFolderTags)): ?>
                                <?php foreach ($userFolderTags as $ut): ?>
                                    <button type="button" class="btn btn-sm btn-white border shadow-sm px-2 py-1 small folder-tag-pill" onclick="pickFolderColor('<?= e($ut['color']) ?>', '<?= e(addslashes($ut['name'])) ?>', this)">
                                        <span class="d-inline-block rounded-circle me-1" style="width: 10px; height: 10px; background-color: <?= e($ut['color']) ?>;"></span>
                                        <span><?= e($ut['name']) ?></span>
                                    </button>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted small p-1 fst-italic">No custom tags created yet. Select a preset below or create tags in <a href="<?= BASE_URL ?>/tags.php" target="_blank">Tags & Labels</a>.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Preset Palette & Custom Color Picker -->
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-2">Preset Colors & Custom</label>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <?php 
                            $folderPresets = [
                                '#4f46e5' => 'Indigo',
                                '#059669' => 'Emerald',
                                '#d97706' => 'Amber',
                                '#e11d48' => 'Rose',
                                '#0284c7' => 'Sky',
                                '#8b5cf6' => 'Violet',
                                '#ec4899' => 'Pink',
                                '#64748b' => 'Slate'
                            ];
                            foreach ($folderPresets as $hex => $cName):
                            ?>
                                <button type="button" class="color-swatch-btn <?= $hex === '#4f46e5' ? 'active' : '' ?> folder-color-swatch" style="background-color: <?= $hex ?>;" title="<?= $cName ?>" onclick="pickFolderColor('<?= $hex ?>', '<?= $cName ?>', this)">
                                    <i class="fa-solid fa-check check-icon text-white"></i>
                                </button>
                            <?php endforeach; ?>

                            <!-- Native Color Picker -->
                            <div class="d-flex align-items-center gap-1 ms-1">
                                <input type="color" id="folderCustomColorPicker" class="form-control form-control-color border-0 p-0 shadow-sm" value="#4f46e5" title="Pick any custom color" style="width: 28px; height: 28px; border-radius: 50%; cursor: pointer;" onchange="pickFolderColor(this.value, 'Custom (' + this.value + ')', null)">
                                <span class="text-muted small" style="font-size: 0.72rem;">Custom</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Create Folder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Rename Modal -->
<div class="modal fade" id="renameModal" tabindex="-1" aria-labelledby="renameModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: var(--radius-lg);">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold" id="renameModalLabel">
                    <i class="fa-solid fa-pen-to-square text-primary me-2"></i> Rename Item
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <input type="hidden" id="renameResourceType">
                <input type="hidden" id="renameResourceId">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">New Name</label>
                    <input type="text" id="renameResourceInput" class="form-control" required autocomplete="off">
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary px-4" onclick="submitRename()">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Move Modal -->
<div class="modal fade" id="moveModal" tabindex="-1" aria-labelledby="moveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: var(--radius-lg);">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold" id="moveModalLabel">
                    <i class="fa-solid fa-arrows-up-down-left-right text-primary me-2"></i> Move Item
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <input type="hidden" id="moveResourceType">
                <input type="hidden" id="moveResourceId">
                <p class="text-muted small mb-3">Moving item: <strong id="moveTargetName" class="text-dark"></strong></p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Select Destination Folder</label>
                    <select id="moveDestinationFolder" class="form-select">
                        <option value="0">📁 Root / My Storage</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary px-4" onclick="submitMove()">Move Item</button>
            </div>
        </div>
    </div>
</div>

<!-- Share Modal -->
<div class="modal fade" id="shareModal" tabindex="-1" aria-labelledby="shareModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: var(--radius-lg);">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold" id="shareModalLabel">
                    <i class="fa-solid fa-share-nodes text-primary me-2"></i> Share Resource
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <input type="hidden" id="shareResourceType">
                <input type="hidden" id="shareResourceId">
                <p class="text-muted small mb-3">Resource: <strong id="shareResourceTitle" class="text-dark"></strong></p>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Link Expiration</label>
                        <select id="shareExpiration" class="form-select">
                            <option value="never">Never Expires</option>
                            <option value="1">1 Day</option>
                            <option value="7" selected>7 Days</option>
                            <option value="30">30 Days</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Password (Optional)</label>
                        <input type="password" id="sharePassword" class="form-control" placeholder="Leave empty for public">
                    </div>
                </div>

                <div class="d-grid mb-3">
                    <button type="button" class="btn btn-outline-primary" onclick="generateShareLink()">
                        <i class="fa-solid fa-link me-1"></i> Generate / Update Share Link
                    </button>
                </div>

                <!-- Generated Link Details Container -->
                <div id="shareLinkSection" class="p-3 bg-light rounded border d-none">
                    <label class="form-label small fw-semibold text-muted">Public Access URL</label>
                    <div class="input-group mb-2">
                        <input type="text" id="shareLinkInput" class="form-control form-control-sm" readonly>
                        <button class="btn btn-primary btn-sm" type="button" onclick="copyShareLink()">
                            <i class="fa-regular fa-copy me-1"></i> Copy
                        </button>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Total Views: <strong id="shareViewCount">0</strong></span>
                        <span id="shareExpiresText">Expires in 7 days</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- File Details Drawer Modal -->
<div class="modal fade" id="fileDetailsModal" tabindex="-1" aria-labelledby="fileDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: var(--radius-lg);">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold" id="fileDetailsModalLabel">
                    <i class="fa-solid fa-circle-info text-primary me-2"></i> File Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div id="detailsLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
                <div id="detailsContent" class="d-none">
                    <h6 class="fw-bold text-dark text-break mb-3" id="detailsFileName"></h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless small mb-0">
                            <tbody>
                                <tr>
                                    <td class="text-muted w-40">File Size</td>
                                    <td class="fw-semibold text-dark" id="detailsFileSize"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Type</td>
                                    <td class="text-dark" id="detailsFileType"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Folder</td>
                                    <td class="text-dark" id="detailsFileFolder"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Revisions</td>
                                    <td><span class="badge bg-secondary-subtle text-secondary" id="detailsFileVersionCount"></span></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Created</td>
                                    <td class="text-dark" id="detailsFileCreated"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Last Modified</td>
                                    <td class="text-dark" id="detailsFileModified"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Tags</td>
                                    <td id="detailsTagsWrapper"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <a href="#" id="detailsDownloadLink" class="btn btn-outline-primary btn-sm px-3">
                    <i class="fa-solid fa-download me-1"></i> Download
                </a>
                <button type="button" class="btn btn-light btn-sm px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function pickFolderColor(hex, name, el) {
    document.getElementById('newFolderSelectedColor').value = hex;
    document.getElementById('newFolderIconPreview').style.color = hex;
    document.getElementById('newFolderColorLabel').innerText = (name || 'Selected') + ' (' + hex + ')';
    document.getElementById('folderCustomColorPicker').value = hex;

    // Reset swatch active states
    document.querySelectorAll('.folder-color-swatch').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.folder-tag-pill').forEach(btn => {
        btn.classList.remove('border-primary', 'fw-bold');
        btn.style.boxShadow = '';
    });

    if (el) {
        if (el.classList.contains('folder-color-swatch')) {
            el.classList.add('active');
        } else if (el.classList.contains('folder-tag-pill')) {
            el.classList.add('border-primary', 'fw-bold');
            el.style.boxShadow = '0 0 0 2px rgba(79, 70, 229, 0.25)';
        }
    }
}

// Dynamically refresh user custom tags whenever the New Folder modal is opened
document.addEventListener('DOMContentLoaded', function () {
    const newFolderModalEl = document.getElementById('newFolderModal');
    if (newFolderModalEl) {
        newFolderModalEl.addEventListener('show.bs.modal', async function () {
            const container = document.getElementById('folderCustomTagsContainer');
            if (!container) return;

            const res = await apiRequest('ajax.php', { action: 'get_user_tags' });
            if (res.success && res.tags && res.tags.length > 0) {
                container.innerHTML = '';
                res.tags.forEach(t => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-sm btn-white border shadow-sm px-2 py-1 small folder-tag-pill';
                    btn.innerHTML = `<span class="d-inline-block rounded-circle me-1" style="width: 10px; height: 10px; background-color: ${t.color};"></span><span>${t.name}</span>`;
                    btn.onclick = () => pickFolderColor(t.color, t.name, btn);
                    container.appendChild(btn);
                });
            }
        });
    }
});

// Inline helper for new folder creation
async function submitNewFolder() {
    const parentId = document.getElementById('newFolderParentId').value;
    const name = document.getElementById('newFolderName').value.trim();
    const color = document.getElementById('newFolderSelectedColor').value || '#4f46e5';

    if (!name) return;

    const res = await apiRequest('ajax.php', {
        action: 'create_folder',
        parent_id: parentId,
        name: name,
        color: color
    });

    if (res.success) {
        showToast(res.message, 'success');
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(res.message, 'error');
    }
}
</script>

<!-- Bootstrap 5.3 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Application Core JS -->
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
