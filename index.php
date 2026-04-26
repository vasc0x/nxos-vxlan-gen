<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NX-OS VXLAN/EVPN Config Generator</title>
  <script src="js/theme.js"></script>
  <link rel="stylesheet" href="css/style.css">
</head>

<body>

  <header>
    <div class="header-inner">
      <div>
        <h1>NX-OS VXLAN/EVPN Config Generator</h1>
        <p>MP-BGP EVPN &middot; OSPF + PIM SM Underlay</p>
      </div>
      <button id="theme-toggle" class="theme-toggle" onclick="toggleTheme()">Dark</button>
    </div>
  </header>

  <main>
    <form id="main-form" action="generate.php" method="POST">

      <!-- ── STEP 1: Topology & Global Settings ──────────────────────── -->
      <section class="card" id="step1">
        <h2>1 &mdash; Topology &amp; Global Settings</h2>

        <div class="form-grid">
          <label class="field">
            <span>Spines</span>
            <input type="number" id="spine_count" name="spine_count" value="2" min="1" max="8" required>
          </label>
          <label class="field">
            <span>Leaves</span>
            <input type="number" id="leaf_count" name="leaf_count" value="2" min="1" max="32" required>
          </label>
          <label class="field">
            <span>BGP ASN</span>
            <input type="text" name="asn" value="65001" required>
          </label>
          <label class="field">
            <span>OSPF Process</span>
            <input type="text" name="ospf_process" value="UNDERLAY" required>
          </label>
          <label class="field">
            <span>OSPF Area</span>
            <input type="text" name="ospf_area" value="0.0.0.0" required>
          </label>
          <label class="field">
            <span>PIM RP Address</span>
            <input type="text" name="rp_address" placeholder="e.g. 100.2.1.1" required>
          </label>
          <label class="field">
            <span>Anycast GW MAC</span>
            <input type="text" name="anycast_mac" value="1234.5678.9000" required>
          </label>
        </div>

        <button type="button" class="btn-primary" onclick="buildDeviceForms()">
          Next: Configure Devices &rarr;
        </button>
      </section>

      <!-- ── STEP 2: Device details ───────────────────────────────────── -->
      <div id="step2" style="display:none">

        <!-- Spines -->
        <section class="card">
          <h2>2 &mdash; Spine Switches</h2>
          <div class="tab-bar" id="spine-tabs"></div>
          <div id="spine-panels"></div>
        </section>

        <!-- Leaves -->
        <section class="card">
          <h2>3 &mdash; Leaf Switches</h2>
          <div class="tab-bar" id="leaf-tabs"></div>
          <div id="leaf-panels"></div>
        </section>

        <!-- VRFs -->
        <section class="card">
          <h2>4 &mdash; VRFs (Tenants)</h2>
          <p class="hint">Each VRF needs a dedicated transit VLAN and an L3 VNI for inter-subnet routing.</p>
          <table class="data-table">
            <thead>
              <tr>
                <th>VRF Name</th>
                <th>L3 VNI ID</th>
                <th>Transit VLAN ID</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="vrf-tbody"></tbody>
          </table>
          <button type="button" class="btn-add" onclick="addVrfRow()">+ Add VRF</button>
        </section>

        <!-- VLANs / L2 VNIs -->
        <section class="card">
          <h2>5 &mdash; VLANs / L2 VNIs</h2>
          <p class="hint">Each entry maps a VLAN to an L2 VNI for overlay transport. The SVI IP becomes the anycast gateway for that segment.</p>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>VLAN ID</th>
                  <th>VNI ID</th>
                  <th>SVI IP Address</th>
                  <th>Prefix Len</th>
                  <th>Multicast Group</th>
                  <th>VRF</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="vlan-tbody"></tbody>
            </table>
          </div>
          <button type="button" class="btn-add" onclick="addVlanRow()">+ Add VLAN</button>
        </section>

        <div class="generate-bar">
          <button type="submit" class="btn-generate">&#9889; Generate Configs</button>
        </div>

      </div><!-- #step2 -->

    </form>
  </main>

  <script src="js/main.js"></script>
</body>

</html>