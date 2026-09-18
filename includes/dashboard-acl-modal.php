<?php
/**
 * includes/dashboard-acl-modal.php
 * Reusable Access Permissions Modal & Client-Side Controller
 * Standardized across all PFMS-toolkit dashboards.
 */
if (!defined('PFMS_ACL_MODAL_INCLUDED')):
define('PFMS_ACL_MODAL_INCLUDED', true);
?>
<!-- ========================================================================= -->
<!-- MODAL: CONFIGURE ACCESS PERMISSIONS (PROFILES & USERS)                    -->
<!-- ========================================================================= -->
<style>
#dashboardAclModal .modal-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
}
#dashboardAclModal .modal-head h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}
#dashboardAclModal .btn-apply {
    background: #0d9488;
    color: #fff;
    border: none;
    padding: 7px 14px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: 0.2s;
}
#dashboardAclModal .btn-apply:hover { background: #0f766e; }
#dashboardAclModal .btn-secondary-custom {
    background: #fff;
    color: #475569;
    border: 1px solid #cbd5e1;
    padding: 7px 14px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    transition: 0.2s;
}
#dashboardAclModal .btn-secondary-custom:hover { background: #f1f5f9; color: #0f172a; }
.pfms-acl-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: #0f172a;
    color: #fff;
    padding: 10px 18px;
    border-radius: 6px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    z-index: 99999;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
}
.pfms-acl-toast.show { opacity: 1; pointer-events: auto; }
.pfms-acl-toast.toast-success { background: #065f46; border-left: 4px solid #34d399; }
.pfms-acl-toast.toast-error { background: #991b1b; border-left: 4px solid #f87171; }
</style>

<div class="modal-overlay" id="dashboardAclModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9000; align-items:center; justify-content:center; backdrop-filter:blur(2px);" onclick="if(event.target === this) closeDashboardAclModal()">
    <div class="modal-card" style="width:680px; max-width:95vw; max-height:90vh; background:#fff; border-radius:10px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); display:flex; flex-direction:column; overflow:hidden;">
        <div class="modal-head">
            <h3>
                <span class="material-symbols-outlined" style="color:#0d9488; font-size:20px;">lock_person</span>
                <span>Access Permissions</span>
            </h3>
            <span class="material-symbols-outlined" style="cursor:pointer; color:#7f8c8d; font-size:20px;" onclick="closeDashboardAclModal()">close</span>
        </div>

        <div class="modal-body" style="padding:16px 22px; overflow-y:auto; flex:1;">
            <input type="hidden" id="aclDashboardId" value="">
            
            <div style="margin-bottom:14px; padding-bottom:12px; border-bottom:1px solid #e2e8f0;">
                <div style="font-size:13px; color:#64748b;">
                    Dashboard: <strong id="aclModalDashName" style="color:#0f172a; font-size:14px;">-</strong>
                </div>
                <div style="font-size:11.5px; color:#94a3b8; margin-top:2px;">
                    Configure which Pandora FMS Profiles and User accounts can view or edit this dashboard.
                </div>
            </div>

            <!-- SECTION 1: VIEW PERMISSION -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:16px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                    <span class="material-symbols-outlined" style="font-size:18px; color:#0284c7;">visibility</span>
                    <span style="font-weight:600; font-size:13px; color:#0f172a;">Who can View this dashboard?</span>
                </div>
                <div style="font-size:11.5px; color:#64748b; margin-bottom:10px;">
                    Users with view access can see data, widgets, and charts in View-Only mode.
                </div>

                <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:10px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12.5px; color:#334155;">
                        <input type="radio" name="acl_view_policy" value="admin_only" checked onchange="toggleAclPolicyViews()">
                        <span><strong>Pandora Administrator Only:</strong> Strict administrator access (Default)</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12.5px; color:#334155;">
                        <input type="radio" name="acl_view_policy" value="profiles" onchange="toggleAclPolicyViews()">
                        <span><strong>Specific Pandora FMS Profiles:</strong> Only users assigned to selected profiles</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12.5px; color:#334155;">
                        <input type="radio" name="acl_view_policy" value="users" onchange="toggleAclPolicyViews()">
                        <span><strong>Specific Users:</strong> Only designated user accounts</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12.5px; color:#334155;">
                        <input type="radio" name="acl_view_policy" value="all" onchange="toggleAclPolicyViews()">
                        <span><strong>Everyone:</strong> All authenticated users & embedded views</span>
                    </label>
                </div>

                <!-- View Profiles List -->
                <div id="aclViewProfilesBox" style="display:none; margin-top:10px; padding:10px 12px; background:#fff; border:1px solid #cbd5e1; border-radius:6px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Select Authorized Profiles:</div>
                    <div id="aclViewProfilesList" style="display:grid; grid-template-columns: 1fr 1fr; gap:6px; max-height:140px; overflow-y:auto;">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>

                <!-- View Users List -->
                <div id="aclViewUsersBox" style="display:none; margin-top:10px; padding:10px 12px; background:#fff; border:1px solid #cbd5e1; border-radius:6px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Select Authorized Users:</div>
                    <div id="aclViewUsersList" style="display:grid; grid-template-columns: 1fr 1fr; gap:6px; max-height:140px; overflow-y:auto;">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>
            </div>

            <!-- SECTION 2: EDIT & MANAGE PERMISSION -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                    <span class="material-symbols-outlined" style="font-size:18px; color:#0d9488;">edit_document</span>
                    <span style="font-weight:600; font-size:13px; color:#0f172a;">Who can Edit & Manage this dashboard?</span>
                </div>
                <div style="font-size:11.5px; color:#64748b; margin-bottom:10px;">
                    Users with edit access can modify layout, add widgets/elements, edit configurations, and delete panels.
                </div>

                <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:10px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12.5px; color:#334155;">
                        <input type="radio" name="acl_edit_policy" value="admin_only" checked onchange="toggleAclPolicyViews()">
                        <span><strong>Pandora Administrator Only:</strong> Strict administrator control (Default)</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12.5px; color:#334155;">
                        <input type="radio" name="acl_edit_policy" value="profiles" onchange="toggleAclPolicyViews()">
                        <span><strong>Specific Pandora FMS Profiles:</strong> (e.g. Chief Operator, Operator Write)</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12.5px; color:#334155;">
                        <input type="radio" name="acl_edit_policy" value="users" onchange="toggleAclPolicyViews()">
                        <span><strong>Specific Users:</strong> Individual designated editors</span>
                    </label>
                </div>

                <!-- Edit Profiles List -->
                <div id="aclEditProfilesBox" style="display:none; margin-top:10px; padding:10px 12px; background:#fff; border:1px solid #cbd5e1; border-radius:6px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Select Authorized Editor Profiles:</div>
                    <div id="aclEditProfilesList" style="display:grid; grid-template-columns: 1fr 1fr; gap:6px; max-height:140px; overflow-y:auto;">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>

                <!-- Edit Users List -->
                <div id="aclEditUsersBox" style="display:none; margin-top:10px; padding:10px 12px; background:#fff; border:1px solid #cbd5e1; border-radius:6px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Select Authorized Editor Users:</div>
                    <div id="aclEditUsersList" style="display:grid; grid-template-columns: 1fr 1fr; gap:6px; max-height:140px; overflow-y:auto;">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-foot" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#f8fafc;">
            <span id="aclModalStatus" style="font-size:11.5px; color:#64748b;"></span>
            <div style="display:flex; gap:8px;">
                <button type="button" class="btn-secondary-custom" onclick="closeDashboardAclModal()">Cancel</button>
                <button type="button" class="btn-apply" id="btnSaveDashboardAcl" onclick="saveDashboardAcl()">
                    <span class="material-symbols-outlined" style="font-size:16px;">save</span>
                    <span>Save Permissions</span>
                </button>
            </div>
        </div>
    </div>
</div>

<div id="pfmsAclToast" class="pfms-acl-toast"></div>

<script>
(function() {
    let aclCachedProfiles = [];
    let aclCachedUsers = [];
    let aclCurrentDashId = null;

    // Robust HTML entity decoder: cleans &#x20;, &#40;, &#41;, etc.
    window.cleanText = function(str) {
        if (!str) return '';
        let txt = String(str);
        try {
            const doc = new DOMParser().parseFromString(txt, 'text/html');
            txt = doc.body.textContent || txt;
        } catch(e) {}
        return txt
            .replace(/&#x20;/gi, ' ')
            .replace(/&amp;#x20;/gi, ' ')
            .replace(/&#32;/gi, ' ')
            .replace(/&nbsp;/gi, ' ')
            .replace(/#@20;/gi, ' ')
            .replace(/&#40;/gi, '(')
            .replace(/&#41;/gi, ')')
            .trim();
    };

    function escapeHtmlSafe(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    if (typeof window.showToast !== 'function') {
        window.showToast = function(message, type = 'success') {
            const toast = document.getElementById('pfmsAclToast') || document.getElementById('toastNotification');
            if (!toast) {
                alert(message);
                return;
            }
            toast.className = 'pfms-acl-toast show toast-' + (type === 'success' ? 'success' : 'error');
            const icon = type === 'success' ? 'check_circle' : 'error';
            toast.innerHTML = `<span class="material-symbols-outlined" style="font-size:18px;">${icon}</span> <span>${escapeHtmlSafe(message)}</span>`;
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3500);
        };
    }

    async function fetchAclOptions() {
        if (aclCachedProfiles.length > 0 && aclCachedUsers.length > 0) return;
        try {
            const getUrl = (typeof window.getApiUrl === 'function') 
                ? window.getApiUrl('get_acl_options') 
                : '?api=get_acl_options';
            const res = await fetch(getUrl);
            const data = await res.json();
            if (data.ok) {
                aclCachedProfiles = data.profiles || [];
                aclCachedUsers = data.users || [];
            }
        } catch (err) {
            console.error("Failed to load ACL options:", err);
        }
    }

    window.openDashboardAclModal = async function(dashId, optTitle = '') {
        // Resolve dashboard object from any active global dashboard registry
        let dash = null;
        if (typeof masterDashboards !== 'undefined' && Array.isArray(masterDashboards)) {
            dash = masterDashboards.find(d => String(d.id) === String(dashId));
        } else if (typeof allDashboards !== 'undefined' && Array.isArray(allDashboards)) {
            dash = allDashboards.find(d => String(d.id) === String(dashId));
        } else if (typeof dashboards !== 'undefined' && Array.isArray(dashboards)) {
            dash = dashboards.find(d => String(d.id) === String(dashId));
        }

        const dashName = optTitle || (dash ? (dash.name || dash.title || dashId) : dashId);

        aclCurrentDashId = dashId;
        const idInput = document.getElementById('aclDashboardId');
        if (idInput) idInput.value = dashId;
        const nameEl = document.getElementById('aclModalDashName');
        if (nameEl) nameEl.innerText = cleanText(dashName);
        const statusEl = document.getElementById('aclModalStatus');
        if (statusEl) statusEl.innerText = '';

        await fetchAclOptions();

        const acl = (dash && dash.access_control) ? dash.access_control : {
            view_policy: 'admin_only',
            view_profiles: [],
            view_users: [],
            edit_policy: 'admin_only',
            edit_profiles: [],
            edit_users: []
        };

        // Set View Policy (default strictly to admin_only)
        const viewPolicyRadios = document.getElementsByName('acl_view_policy');
        viewPolicyRadios.forEach(r => {
            r.checked = (r.value === (acl.view_policy || 'admin_only'));
        });

        // Set Edit Policy
        const editPolicyRadios = document.getElementsByName('acl_edit_policy');
        editPolicyRadios.forEach(r => {
            r.checked = (r.value === (acl.edit_policy || 'admin_only'));
        });

        // Populate View Profiles
        const vpList = document.getElementById('aclViewProfilesList');
        const cleanViewProfiles = (acl.view_profiles || []).map(cleanText);
        vpList.innerHTML = aclCachedProfiles.map(p => {
            const cName = cleanText(p.name);
            const isChecked = cleanViewProfiles.includes(cName) ? 'checked' : '';
            return `<label style="display:flex; align-items:center; gap:6px; font-size:12px; cursor:pointer;">
                <input type="checkbox" class="acl-vp-chk" value="${escapeHtmlSafe(cName)}" ${isChecked}>
                <span>${escapeHtmlSafe(cName)}</span>
            </label>`;
        }).join('');

        // Populate View Users
        const vuList = document.getElementById('aclViewUsersList');
        const cleanViewUsers = (acl.view_users || []).map(cleanText);
        vuList.innerHTML = aclCachedUsers.map(u => {
            const cUser = cleanText(u.id_user);
            const isChecked = cleanViewUsers.includes(cUser) ? 'checked' : '';
            const cComm = cleanText(u.comments);
            const name = cComm ? `${cUser} (${cComm})` : cUser;
            return `<label style="display:flex; align-items:center; gap:6px; font-size:12px; cursor:pointer;">
                <input type="checkbox" class="acl-vu-chk" value="${escapeHtmlSafe(cUser)}" ${isChecked}>
                <span>${escapeHtmlSafe(name)}</span>
            </label>`;
        }).join('');

        // Populate Edit Profiles
        const epList = document.getElementById('aclEditProfilesList');
        const cleanEditProfiles = (acl.edit_profiles || []).map(cleanText);
        epList.innerHTML = aclCachedProfiles.map(p => {
            const cName = cleanText(p.name);
            const isChecked = cleanEditProfiles.includes(cName) ? 'checked' : '';
            return `<label style="display:flex; align-items:center; gap:6px; font-size:12px; cursor:pointer;">
                <input type="checkbox" class="acl-ep-chk" value="${escapeHtmlSafe(cName)}" ${isChecked}>
                <span>${escapeHtmlSafe(cName)}</span>
            </label>`;
        }).join('');

        // Populate Edit Users
        const euList = document.getElementById('aclEditUsersList');
        const cleanEditUsers = (acl.edit_users || []).map(cleanText);
        euList.innerHTML = aclCachedUsers.map(u => {
            const cUser = cleanText(u.id_user);
            const isChecked = cleanEditUsers.includes(cUser) ? 'checked' : '';
            const cComm = cleanText(u.comments);
            const name = cComm ? `${cUser} (${cComm})` : cUser;
            return `<label style="display:flex; align-items:center; gap:6px; font-size:12px; cursor:pointer;">
                <input type="checkbox" class="acl-eu-chk" value="${escapeHtmlSafe(cUser)}" ${isChecked}>
                <span>${escapeHtmlSafe(name)}</span>
            </label>`;
        }).join('');

        toggleAclPolicyViews();

        const modal = document.getElementById('dashboardAclModal');
        if (modal) modal.style.display = 'flex';
    };

    window.closeDashboardAclModal = function() {
        const modal = document.getElementById('dashboardAclModal');
        if (modal) modal.style.display = 'none';
        aclCurrentDashId = null;
    };

    window.toggleAclPolicyViews = function() {
        const viewPolicy = document.querySelector('input[name="acl_view_policy"]:checked')?.value || 'admin_only';
        const editPolicy = document.querySelector('input[name="acl_edit_policy"]:checked')?.value || 'admin_only';

        const vpBox = document.getElementById('aclViewProfilesBox');
        if (vpBox) vpBox.style.display = (viewPolicy === 'profiles') ? 'block' : 'none';
        const vuBox = document.getElementById('aclViewUsersBox');
        if (vuBox) vuBox.style.display = (viewPolicy === 'users') ? 'block' : 'none';

        const epBox = document.getElementById('aclEditProfilesBox');
        if (epBox) epBox.style.display = (editPolicy === 'profiles') ? 'block' : 'none';
        const euBox = document.getElementById('aclEditUsersBox');
        if (euBox) euBox.style.display = (editPolicy === 'users') ? 'block' : 'none';
    };

    window.saveDashboardAcl = async function() {
        if (!aclCurrentDashId) return;

        const btn = document.getElementById('btnSaveDashboardAcl');
        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">hourglass_empty</span> Saving...';

        const viewPolicy = document.querySelector('input[name="acl_view_policy"]:checked')?.value || 'admin_only';
        const editPolicy = document.querySelector('input[name="acl_edit_policy"]:checked')?.value || 'admin_only';

        const viewProfiles = Array.from(document.querySelectorAll('.acl-vp-chk:checked')).map(el => el.value);
        const viewUsers = Array.from(document.querySelectorAll('.acl-vu-chk:checked')).map(el => el.value);
        const editProfiles = Array.from(document.querySelectorAll('.acl-ep-chk:checked')).map(el => el.value);
        const editUsers = Array.from(document.querySelectorAll('.acl-eu-chk:checked')).map(el => el.value);

        const aclPayload = {
            view_policy: viewPolicy,
            view_profiles: viewProfiles,
            view_users: viewUsers,
            edit_policy: editPolicy,
            edit_profiles: editProfiles,
            edit_users: editUsers
        };

        const csrfTokenVal = (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) 
            ? CSRF_TOKEN 
            : (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');

        const postUrl = (typeof window.getApiUrl === 'function')
            ? window.getApiUrl('save_dashboard_acl')
            : '?api=save_dashboard_acl';

        try {
            const res = await fetch(postUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfTokenVal
                },
                body: JSON.stringify({
                    dashboard_id: aclCurrentDashId,
                    id: aclCurrentDashId,
                    access_control: aclPayload
                })
            });

            const data = await res.json();
            if (data.ok) {
                // Update local model in active arrays
                const updateDash = (list) => {
                    if (Array.isArray(list)) {
                        const target = list.find(d => String(d.id) === String(aclCurrentDashId));
                        if (target) target.access_control = data.access_control;
                    }
                };
                if (typeof masterDashboards !== 'undefined') updateDash(masterDashboards);
                if (typeof allDashboards !== 'undefined') updateDash(allDashboards);
                if (typeof dashboards !== 'undefined') updateDash(dashboards);

                showToast(data.msg || 'Access permissions saved successfully!', 'success');
                closeDashboardAclModal();
            } else {
                showToast(data.error || 'Failed to save access permissions.', 'error');
            }
        } catch (err) {
            console.error("Save ACL error:", err);
            showToast('Network error while saving permissions.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    };
})();
</script>
<?php endif; ?>
