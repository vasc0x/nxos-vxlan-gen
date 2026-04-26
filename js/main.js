let vrfRowIndex = 0;
let vlanRowIndex = 0;

// ── Build all device panels ──────────────────────────────────────────────────

function buildDeviceForms() {
  const spineCount = parseInt(document.getElementById('spine_count').value) || 2;
  const leafCount = parseInt(document.getElementById('leaf_count').value) || 2;

  buildSection('spine', spineCount, buildSpinePanel);
  buildSection('leaf', leafCount, buildLeafPanel);

  document.getElementById('step2').style.display = '';
  document.getElementById('step1').querySelector('.btn-primary').textContent = 'Rebuild Form';

  // Seed one VRF and one VLAN if the tables are empty
  if (vrfRowIndex === 0) {
    addVrfRow('Tenant-1', '10000', '10');
  }
  if (vlanRowIndex === 0) {
    addVlanRow('11', '10011', '10.0.11.1', '24', '239.0.0.11', 'Tenant-1');
  }

  document.getElementById('step2').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function buildSection(type, count, panelFn) {
  const tabsEl = document.getElementById(`${type}-tabs`);
  const panelsEl = document.getElementById(`${type}-panels`);
  tabsEl.innerHTML = '';
  panelsEl.innerHTML = '';

  for (let i = 0; i < count; i++) {
    const label = type === 'spine' ? `Spine ${i + 1}` : `Leaf ${i + 1}`;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'tab-btn' + (i === 0 ? ' active' : '');
    btn.textContent = label;
    btn.onclick = () => showTab(type, i);
    tabsEl.appendChild(btn);

    const wrapper = document.createElement('div');
    wrapper.innerHTML = panelFn(i);
    panelsEl.appendChild(wrapper.firstElementChild);
  }
}

// ── Panel builders ───────────────────────────────────────────────────────────

function buildSpinePanel(i) {
  return `
    <div class="device-panel ${i === 0 ? 'active' : ''}" id="spine-panel-${i}">
        <div class="device-grid">
            <label class="field">
                <span>Hostname</span>
                <input type="text" name="spine[${i}][hostname]" placeholder="spine-${i + 1}">
            </label>
            <label class="field">
                <span>Loopback 0 IP</span>
                <input type="text" name="spine[${i}][lo0]" placeholder="10.2.1.${i + 1}">
            </label>
            <label class="field">
                <span>Loopback 1 IP</span>
                <input type="text" name="spine[${i}][lo1]" placeholder="100.2.1.${i + 1}">
            </label>
        </div>
        <div class="intf-section">
            <div class="intf-col-headers">
                <span>Interface</span><span>IP Address</span><span>Mask</span>
            </div>
            <div class="intf-list" id="spine-${i}-l3"></div>
            <button type="button" class="btn-add-intf" onclick="addIntf('spine', ${i}, 'l3')">+ Add L3 Uplink</button>
        </div>
    </div>`;
}

function buildLeafPanel(i) {
  return `
    <div class="device-panel ${i === 0 ? 'active' : ''}" id="leaf-panel-${i}">
        <div class="device-grid">
            <label class="field">
                <span>Hostname</span>
                <input type="text" name="leaf[${i}][hostname]" placeholder="leaf-${i + 1}">
            </label>
            <label class="field">
                <span>Loopback 0 IP</span>
                <input type="text" name="leaf[${i}][lo0]" placeholder="10.2.2.${i + 1}">
            </label>
            <label class="field">
                <span>Loopback 1 IP</span>
                <input type="text" name="leaf[${i}][lo1]" placeholder="100.2.2.${i + 1}">
            </label>
        </div>
        <div class="intf-two-col">
            <div class="intf-section">
                <div class="intf-section-title">L3 Uplinks <span class="hint-inline">to spines</span></div>
                <div class="intf-col-headers">
                    <span>Interface</span><span>IP Address</span><span>Mask</span>
                </div>
                <div class="intf-list" id="leaf-${i}-l3"></div>
                <button type="button" class="btn-add-intf" onclick="addIntf('leaf', ${i}, 'l3')">+ Add Interface</button>
            </div>
            <div class="intf-section">
                <div class="intf-section-title">L2 Host Ports <span class="hint-inline">trunk to servers</span></div>
                <div class="intf-col-headers l2-headers">
                    <span>Interface</span>
                </div>
                <div class="intf-list" id="leaf-${i}-l2"></div>
                <button type="button" class="btn-add-intf" onclick="addIntf('leaf', ${i}, 'l2')">+ Add Interface</button>
            </div>
        </div>
    </div>`;
}

// ── Tab switching ────────────────────────────────────────────────────────────

function showTab(type, idx) {
  document.querySelectorAll(`#${type}-panels .device-panel`).forEach(p => p.classList.remove('active'));
  document.querySelectorAll(`#${type}-tabs .tab-btn`).forEach(b => b.classList.remove('active'));
  document.getElementById(`${type}-panel-${idx}`).classList.add('active');
  document.querySelectorAll(`#${type}-tabs .tab-btn`)[idx].classList.add('active');
}

// ── Dynamic interface rows ───────────────────────────────────────────────────

function addIntf(deviceType, deviceIdx, type) {
  const container = document.getElementById(`${deviceType}-${deviceIdx}-${type}`);
  const count = container.children.length;
  const row = document.createElement('div');
  row.className = 'intf-row';

  if (type === 'l3') {
    row.innerHTML = `
            <input type="text" name="${deviceType}[${deviceIdx}][l3_intf][]" placeholder="Ethernet1/${count + 1}" class="intf-name">
            <input type="text" name="${deviceType}[${deviceIdx}][l3_ip][]"   placeholder="10.0.0.${count * 4 + 1}" class="intf-ip">
            <input type="text" name="${deviceType}[${deviceIdx}][l3_mask][]" placeholder="30" class="intf-mask">
            <button type="button" class="btn-remove" onclick="this.parentElement.remove()" title="Remove">&times;</button>`;
  } else {
    row.innerHTML = `
            <input type="text" name="${deviceType}[${deviceIdx}][l2_intf][]" placeholder="Ethernet1/${count + 1}" class="intf-name">
            <button type="button" class="btn-remove" onclick="this.parentElement.remove()" title="Remove">&times;</button>`;
  }

  container.appendChild(row);
}

// ── VRF rows ─────────────────────────────────────────────────────────────────

function addVrfRow(name = '', l3vni = '', transit_vlan = '') {
  const idx = vrfRowIndex++;
  const tbody = document.getElementById('vrf-tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
        <td><input type="text" name="vrf[${idx}][name]" value="${name}" placeholder="Tenant-1" onchange="syncVrfDropdowns()"></td>
        <td><input type="text" name="vrf[${idx}][l3vni]" value="${l3vni}" placeholder="10000"></td>
        <td><input type="text" name="vrf[${idx}][transit_vlan]" value="${transit_vlan}" placeholder="10"></td>
        <td><button type="button" class="btn-remove" onclick="this.closest('tr').remove(); syncVrfDropdowns()">&times;</button></td>`;
  tbody.appendChild(tr);
  syncVrfDropdowns();
}

// ── VLAN rows ─────────────────────────────────────────────────────────────────

function addVlanRow(vlan_id = '', vni_id = '', svi_ip = '', mask = '24', mcast_grp = '', vrf = '') {
  const idx = vlanRowIndex++;
  const tbody = document.getElementById('vlan-tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
        <td><input type="text" name="vlan[${idx}][vlan_id]"   value="${vlan_id}"   placeholder="11"          style="width:65px"></td>
        <td><input type="text" name="vlan[${idx}][vni_id]"    value="${vni_id}"    placeholder="10011"        style="width:80px"></td>
        <td><input type="text" name="vlan[${idx}][svi_ip]"    value="${svi_ip}"    placeholder="10.0.11.1"></td>
        <td><input type="text" name="vlan[${idx}][mask]"      value="${mask}"      placeholder="24"           style="width:50px"></td>
        <td><input type="text" name="vlan[${idx}][mcast_grp]" value="${mcast_grp}" placeholder="239.0.0.11"></td>
        <td><select name="vlan[${idx}][vrf]" id="vlan-vrf-${idx}"></select></td>
        <td><button type="button" class="btn-remove" onclick="this.closest('tr').remove()">&times;</button></td>`;
  tbody.appendChild(tr);
  syncVrfDropdowns();
  if (vrf) {
    const sel = document.getElementById(`vlan-vrf-${idx}`);
    if (sel) sel.value = vrf;
  }
}

// Keep VRF dropdowns in the VLAN table in sync with the VRF table
function syncVrfDropdowns() {
  const names = Array.from(document.querySelectorAll('#vrf-tbody input[name$="[name]"]'))
    .map(i => i.value.trim())
    .filter(Boolean);

  document.querySelectorAll('#vlan-tbody select[name$="[vrf]"]').forEach(sel => {
    const current = sel.value;
    sel.innerHTML = names.map(n => `<option value="${n}"${n === current ? ' selected' : ''}>${n}</option>`).join('');
  });
}
