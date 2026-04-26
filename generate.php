<?php
// ── Helpers ───────────────────────────────────────────────────────────────────

function clean($v): string
{
  return strip_tags(trim((string)($v ?? '')));
}

function h($v): string
{
  return htmlspecialchars(trim((string)($v ?? '')), ENT_QUOTES, 'UTF-8');
}

function cleanArray(array $arr): array
{
  return array_map('clean', $arr);
}

// ── Parse globals ─────────────────────────────────────────────────────────────

$asn          = clean($_POST['asn']          ?? '65001');
$ospf_process = clean($_POST['ospf_process'] ?? 'Underlay');
$ospf_area    = clean($_POST['ospf_area']    ?? '0.0.0.0');
$rp_address   = clean($_POST['rp_address']   ?? '');
$anycast_mac  = clean($_POST['anycast_mac']  ?? '1234.5678.9000');

$raw_spines = $_POST['spine'] ?? [];
$raw_leaves = $_POST['leaf']  ?? [];
$raw_vrfs   = $_POST['vrf']   ?? [];
$raw_vlans  = $_POST['vlan']  ?? [];

// ── Collect loopback IPs for cross-referencing ────────────────────────────────

$spine_lo0s = [];
$spine_lo1s = [];
foreach ($raw_spines as $s) {
  $lo0 = clean($s['lo0'] ?? '');
  $lo1 = clean($s['lo1'] ?? '');
  if ($lo0) $spine_lo0s[] = $lo0;
  if ($lo1) $spine_lo1s[] = $lo1;
}

$leaf_lo0s = [];
foreach ($raw_leaves as $l) {
  $lo0 = clean($l['lo0'] ?? '');
  if ($lo0) $leaf_lo0s[] = $lo0;
}

// ── Clean VRFs ────────────────────────────────────────────────────────────────

$vrfs = [];
foreach ($raw_vrfs as $v) {
  $name = clean($v['name'] ?? '');
  if (!$name) continue;
  $vrfs[] = [
    'name'         => $name,
    'l3vni'        => clean($v['l3vni']        ?? ''),
    'transit_vlan' => clean($v['transit_vlan'] ?? ''),
  ];
}

// ── Clean VLANs ───────────────────────────────────────────────────────────────

$vlans = [];
foreach ($raw_vlans as $v) {
  $vlan_id = clean($v['vlan_id'] ?? '');
  if (!$vlan_id) continue;
  $vlans[] = [
    'vlan_id'   => $vlan_id,
    'vni_id'    => clean($v['vni_id']    ?? ''),
    'svi_ip'    => clean($v['svi_ip']    ?? ''),
    'mask'      => clean($v['mask']      ?? '24'),
    'mcast_grp' => clean($v['mcast_grp'] ?? ''),
    'vrf'       => clean($v['vrf']       ?? ''),
  ];
}

// Trunk VLAN list = all L2VNI vlans + all transit vlans
$trunk_ids = array_merge(
  array_column($vlans, 'vlan_id'),
  array_column($vrfs,  'transit_vlan')
);
$trunk_vlans = implode(',', array_filter(array_unique($trunk_ids)));

// ── Config generators ─────────────────────────────────────────────────────────

function genSpine(
  array $s,
  string $asn,
  string $osp,
  string $area,
  string $rp,
  array $spine_lo1s,
  array $leaf_lo0s
): string {
  $hn       = clean($s['hostname'] ?? 'spine');
  $lo0      = clean($s['lo0']     ?? '');
  $lo1      = clean($s['lo1']     ?? '');
  $l3_names = cleanArray($s['l3_intf'] ?? []);
  $l3_ips   = cleanArray($s['l3_ip']   ?? []);
  $l3_masks = cleanArray($s['l3_mask'] ?? []);
  $l3       = array_filter($l3_names);

  $c = [];
  $c[] = "! ================================================================";
  $c[] = "! Device  : $hn  (Spine)";
  $c[] = "! Generated: " . date('Y-m-d H:i:s T');
  $c[] = "! ================================================================";
  $c[] = "";
  $c[] = "hostname $hn";
  $c[] = "";

  $c[] = "! ── Features ───────────────────────────────────────────────────";
  $c[] = "feature bgp";
  $c[] = "feature ospf";
  $c[] = "feature nv overlay";
  $c[] = "feature pim";
  $c[] = "";

  $c[] = "! ── Loopbacks ──────────────────────────────────────────────────";
  if ($lo0) {
    $c[] = "interface loopback0";
    $c[] = "  ip address $lo0/32";
    $c[] = "  ip router ospf $osp area $area";
    $c[] = "  ip pim sparse-mode";
    $c[] = "";
  }
  if ($lo1) {
    $c[] = "interface loopback1";
    $c[] = "  ip address $lo1/32";
    $c[] = "  ip router ospf $osp area $area";
    $c[] = "  ip pim sparse-mode";
    $c[] = "";
  }

  if ($l3) {
    $c[] = "! ── L3 Uplink Interfaces ───────────────────────────────────";
    foreach ($l3_names as $n => $intf) {
      if (!$intf) continue;
      $ip   = $l3_ips[$n]   ?? '';
      $mask = $l3_masks[$n] ?? '30';
      $c[] = "interface $intf";
      $c[] = "  no switchport";
      $c[] = "  mtu 9216";
      $c[] = "  medium p2p";
      if ($ip) $c[] = "  ip address $ip/$mask";
      $c[] = "  ip router ospf $osp area $area";
      $c[] = "  ip ospf network point-to-point";
      $c[] = "  ip pim sparse-mode";
      $c[] = "  no shutdown";
      $c[] = "";
    }
  }

  $c[] = "! ── OSPF ───────────────────────────────────────────────────────";
  $c[] = "router ospf $osp";
  if ($lo0) $c[] = "  router-id $lo0";
  $c[] = "";

  $c[] = "! ── PIM (Anycast RP) ───────────────────────────────────────────";
  if ($rp) {
    $c[] = "ip pim rp-address $rp";
    foreach ($spine_lo1s as $rp_lo) {
      if ($rp_lo) $c[] = "ip pim anycast-rp $rp $rp_lo";
    }
  }
  $c[] = "";

  $c[] = "! ── NV Overlay EVPN ────────────────────────────────────────────";
  $c[] = "nv overlay evpn";
  $c[] = "";

  $c[] = "! ── BGP (Route Reflector) ──────────────────────────────────────";
  $c[] = "router bgp $asn";
  if ($lo0) $c[] = "  router-id $lo0";
  $c[] = "  address-family l2vpn evpn";
  $c[] = "  !";
  foreach ($leaf_lo0s as $lo) {
    if (!$lo) continue;
    $c[] = "  neighbor $lo";
    $c[] = "    remote-as $asn";
    $c[] = "    update-source loopback0";
    $c[] = "    address-family l2vpn evpn";
    $c[] = "      send-community both";
    $c[] = "      route-reflector-client";
    $c[] = "  !";
  }
  $c[] = "";

  $c[] = "copy running-config startup-config";
  $c[] = "";

  return implode("\n", $c);
}

function genLeaf(
  array $l,
  string $asn,
  string $osp,
  string $area,
  string $rp,
  string $anycast_mac,
  array $spine_lo0s,
  array $vrfs,
  array $vlans,
  string $trunk_vlans
): string {
  $hn       = clean($l['hostname'] ?? 'leaf');
  $lo0      = clean($l['lo0']     ?? '');
  $lo1      = clean($l['lo1']     ?? '');
  $l3_names = cleanArray($l['l3_intf'] ?? []);
  $l3_ips   = cleanArray($l['l3_ip']   ?? []);
  $l3_masks = cleanArray($l['l3_mask'] ?? []);
  $l3       = array_filter($l3_names);
  $l2       = array_filter(cleanArray($l['l2_intf'] ?? []));

  $c = [];
  $c[] = "! ================================================================";
  $c[] = "! Device  : $hn  (Leaf)";
  $c[] = "! Generated: " . date('Y-m-d H:i:s T');
  $c[] = "! ================================================================";
  $c[] = "";
  $c[] = "hostname $hn";
  $c[] = "";

  $c[] = "! ── Features ───────────────────────────────────────────────────";
  $c[] = "feature bgp";
  $c[] = "feature ospf";
  $c[] = "feature nv overlay";
  $c[] = "feature pim";
  $c[] = "feature interface-vlan";
  $c[] = "feature vn-segment-vlan-based";
  $c[] = "";

  $c[] = "! ── Anycast Gateway MAC ────────────────────────────────────────";
  $c[] = "fabric forwarding anycast-gateway-mac $anycast_mac";
  $c[] = "";

  $c[] = "! ── Loopbacks ──────────────────────────────────────────────────";
  if ($lo0) {
    $c[] = "interface loopback0";
    $c[] = "  ip address $lo0/32";
    $c[] = "  ip router ospf $osp area $area";
    $c[] = "  ip pim sparse-mode";
    $c[] = "";
  }
  if ($lo1) {
    $c[] = "interface loopback1";
    $c[] = "  ip address $lo1/32";
    $c[] = "  ip router ospf $osp area $area";
    $c[] = "  ip pim sparse-mode";
    $c[] = "";
  }

  if ($l3) {
    $c[] = "! ── L3 Uplink Interfaces (to spines) ───────────────────────";
    foreach ($l3_names as $n => $intf) {
      if (!$intf) continue;
      $ip   = $l3_ips[$n]   ?? '';
      $mask = $l3_masks[$n] ?? '30';
      $c[] = "interface $intf";
      $c[] = "  no switchport";
      $c[] = "  mtu 9216";
      $c[] = "  medium p2p";
      if ($ip) $c[] = "  ip address $ip/$mask";
      $c[] = "  ip router ospf $osp area $area";
      $c[] = "  ip ospf network point-to-point";
      $c[] = "  ip pim sparse-mode";
      $c[] = "  no shutdown";
      $c[] = "";
    }
  }

  if ($l2) {
    $c[] = "! ── L2 Host-facing Interfaces ──────────────────────────────";
    foreach ($l2 as $intf) {
      $c[] = "interface $intf";
      $c[] = "  switchport";
      $c[] = "  switchport mode trunk";
      if ($trunk_vlans) $c[] = "  switchport trunk allowed vlan $trunk_vlans";
      $c[] = "  no shutdown";
      $c[] = "";
    }
  }

  $c[] = "! ── OSPF ───────────────────────────────────────────────────────";
  $c[] = "router ospf $osp";
  if ($lo0) $c[] = "  router-id $lo0";
  $c[] = "";

  $c[] = "! ── PIM ────────────────────────────────────────────────────────";
  if ($rp) $c[] = "ip pim rp-address $rp";
  $c[] = "";

  $c[] = "! ── NV Overlay EVPN ────────────────────────────────────────────";
  $c[] = "nv overlay evpn";
  $c[] = "";

  if ($vlans || $vrfs) {
    $c[] = "! ── VLANs ──────────────────────────────────────────────────";
    foreach ($vlans as $v) {
      if (!$v['vlan_id'] || !$v['vni_id']) continue;
      $c[] = "vlan {$v['vlan_id']}";
      $c[] = "  vn-segment {$v['vni_id']}";
      $c[] = "";
    }
    foreach ($vrfs as $vrf) {
      if (!$vrf['transit_vlan'] || !$vrf['l3vni']) continue;
      $c[] = "vlan {$vrf['transit_vlan']}";
      $c[] = "  vn-segment {$vrf['l3vni']}";
      $c[] = "";
    }
  }

  if ($vrfs) {
    $c[] = "! ── VRF Contexts ───────────────────────────────────────────";
    foreach ($vrfs as $vrf) {
      if (!$vrf['name']) continue;
      $c[] = "vrf context {$vrf['name']}";
      $c[] = "  vni {$vrf['l3vni']}";
      $c[] = "  rd auto";
      $c[] = "  address-family ipv4 unicast";
      $c[] = "    route-target both auto evpn";
      $c[] = "";
    }
  }

  $c[] = "! ── NVE Interface (VTEP) ───────────────────────────────────────";
  $c[] = "interface nve1";
  $c[] = "  no shutdown";
  $c[] = "  host-reachability protocol bgp";
  $c[] = "  source-interface loopback0";
  foreach ($vlans as $v) {
    if (!$v['vni_id']) continue;
    $c[] = "  member vni {$v['vni_id']}";
    if ($v['mcast_grp']) $c[] = "    mcast-group {$v['mcast_grp']}";
    $c[] = "    suppress-arp";
  }
  foreach ($vrfs as $vrf) {
    if ($vrf['l3vni']) $c[] = "  member vni {$vrf['l3vni']} associate-vrf";
  }
  $c[] = "";

  if ($vlans) {
    $c[] = "! ── EVPN ───────────────────────────────────────────────────";
    $c[] = "evpn";
    foreach ($vlans as $v) {
      if (!$v['vni_id']) continue;
      $c[] = "  vni {$v['vni_id']} l2";
      $c[] = "    rd auto";
      $c[] = "    route-target import auto";
      $c[] = "    route-target export auto";
    }
    $c[] = "";
  }

  if ($vlans) {
    $c[] = "! ── L2VNI SVIs ─────────────────────────────────────────────";
    foreach ($vlans as $v) {
      if (!$v['vlan_id']) continue;
      $c[] = "interface vlan{$v['vlan_id']}";
      $c[] = "  no shutdown";
      if ($v['vrf'])    $c[] = "  vrf member {$v['vrf']}";
      if ($v['svi_ip']) $c[] = "  ip address {$v['svi_ip']}/{$v['mask']}";
      $c[] = "  fabric forwarding mode anycast-gateway";
      $c[] = "";
    }
  }

  if ($vrfs) {
    $c[] = "! ── L3VNI SVIs (Transit VLANs) ─────────────────────────────";
    foreach ($vrfs as $vrf) {
      if (!$vrf['transit_vlan']) continue;
      $c[] = "interface vlan{$vrf['transit_vlan']}";
      $c[] = "  no shutdown";
      $c[] = "  vrf member {$vrf['name']}";
      $c[] = "  ip forward";
      $c[] = "";
    }
  }

  $c[] = "! ── BGP ────────────────────────────────────────────────────────";
  $c[] = "router bgp $asn";
  if ($lo0) $c[] = "  router-id $lo0";
  $c[] = "  address-family l2vpn evpn";
  $c[] = "  !";
  foreach ($spine_lo0s as $lo) {
    if (!$lo) continue;
    $c[] = "  neighbor $lo";
    $c[] = "    remote-as $asn";
    $c[] = "    update-source loopback0";
    $c[] = "    address-family l2vpn evpn";
    $c[] = "      send-community both";
    $c[] = "  !";
  }
  foreach ($vrfs as $vrf) {
    if (!$vrf['name']) continue;
    $c[] = "  vrf {$vrf['name']}";
    $c[] = "    address-family ipv4 unicast";
    $c[] = "      advertise l2vpn evpn";
  }
  $c[] = "";

  $c[] = "copy running-config startup-config";
  $c[] = "";

  return implode("\n", $c);
}

// ── Generate all configs ──────────────────────────────────────────────────────

$configs = [];

foreach ($raw_spines as $i => $s) {
  $label = clean($s['hostname'] ?? "spine-" . ($i + 1));
  $cfg   = genSpine(
    $s,
    $asn,
    $ospf_process,
    $ospf_area,
    $rp_address,
    $spine_lo1s,
    $leaf_lo0s
  );
  $configs[] = ['label' => $label, 'type' => 'Spine', 'config' => $cfg];
}

foreach ($raw_leaves as $i => $l) {
  $label = clean($l['hostname'] ?? "leaf-" . ($i + 1));
  $cfg   = genLeaf(
    $l,
    $asn,
    $ospf_process,
    $ospf_area,
    $rp_address,
    $anycast_mac,
    $spine_lo0s,
    $vrfs,
    $vlans,
    $trunk_vlans
  );
  $configs[] = ['label' => $label, 'type' => 'Leaf', 'config' => $cfg];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Generated Configs &mdash; NX-OS VXLAN/EVPN</title>
  <link rel="stylesheet" href="css/style.css">
  <style>
    pre.config-block {
      background: #0b1929;
      color: #a8d8ea;
      border-radius: 6px;
      padding: 1.25rem 1.4rem;
      font-family: 'Cascadia Code', 'Fira Code', 'Consolas', 'Courier New', monospace;
      font-size: .8rem;
      line-height: 1.65;
      overflow-x: auto;
      overflow-y: auto;
      max-height: 520px;
      white-space: pre;
      margin: 0;
    }

    .config-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: .8rem;
      flex-wrap: wrap;
      gap: .5rem;
    }

    .config-title {
      display: flex;
      align-items: center;
      gap: .6rem;
    }

    .config-title h3 {
      font-size: .95rem;
      font-weight: 600;
    }

    .badge {
      font-size: .68rem;
      font-weight: 700;
      padding: .18rem .55rem;
      border-radius: 3px;
      text-transform: uppercase;
      letter-spacing: .05em;
    }

    .badge-spine {
      background: #112d4e;
      color: #4db8ff;
    }

    .badge-leaf {
      background: #0e3325;
      color: #3ddc84;
    }

    .btn-group {
      display: flex;
      gap: .45rem;
    }

    .btn-copy,
    .btn-dl {
      font-size: .78rem;
      padding: .28rem .75rem;
      border-radius: 4px;
      cursor: pointer;
      transition: background .15s;
      font-weight: 500;
    }

    .btn-copy {
      background: transparent;
      border: 1px solid #4db8ff;
      color: #4db8ff;
    }

    .btn-copy:hover {
      background: rgba(77, 184, 255, .12);
    }

    .btn-copy.done {
      border-color: #3ddc84;
      color: #3ddc84;
    }

    .btn-dl {
      background: transparent;
      border: 1px solid #3ddc84;
      color: #3ddc84;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
    }

    .btn-dl:hover {
      background: rgba(61, 220, 132, .12);
    }

    .back-link {
      display: inline-block;
      margin-bottom: 1.25rem;
      color: var(--accent);
      font-size: .875rem;
      text-decoration: none;
    }

    .back-link:hover {
      text-decoration: underline;
    }

    .summary-bar {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: .9rem 1.4rem;
      margin-bottom: 1.5rem;
      display: flex;
      gap: 1.8rem;
      flex-wrap: wrap;
      font-size: .875rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
    }

    .sum {
      display: flex;
      flex-direction: column;
    }

    .sum-label {
      font-size: .68rem;
      font-weight: 600;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .05em;
    }

    .sum-value {
      font-weight: 600;
      font-size: .95rem;
    }
  </style>
</head>

<body>

  <header>
    <div class="header-inner">
      <h1>NX-OS VXLAN/EVPN Config Generator</h1>
      <p>Generated configuration &mdash; <?= count($configs) ?> device(s)</p>
    </div>
  </header>

  <main>
    <a href="index.php" class="back-link">&larr; Back to form</a>

    <div class="summary-bar">
      <div class="sum"><span class="sum-label">ASN</span><span class="sum-value"><?= h($asn) ?></span></div>
      <div class="sum"><span class="sum-label">OSPF</span><span class="sum-value"><?= h($ospf_process) ?> / <?= h($ospf_area) ?></span></div>
      <div class="sum"><span class="sum-label">RP Address</span><span class="sum-value"><?= h($rp_address) ?: '&mdash;' ?></span></div>
      <div class="sum"><span class="sum-label">Anycast MAC</span><span class="sum-value"><?= h($anycast_mac) ?></span></div>
      <div class="sum"><span class="sum-label">Spines</span><span class="sum-value"><?= count($raw_spines) ?></span></div>
      <div class="sum"><span class="sum-label">Leaves</span><span class="sum-value"><?= count($raw_leaves) ?></span></div>
      <div class="sum"><span class="sum-label">VRFs</span><span class="sum-value"><?= count($vrfs) ?></span></div>
      <div class="sum"><span class="sum-label">VLANs/VNIs</span><span class="sum-value"><?= count($vlans) ?></span></div>
    </div>

    <?php foreach ($configs as $i => $item): ?>
      <section class="card">
        <div class="config-header">
          <div class="config-title">
            <span class="badge badge-<?= strtolower($item['type']) ?>"><?= $item['type'] ?></span>
            <h3><?= h($item['label']) ?></h3>
          </div>
          <div class="btn-group">
            <button class="btn-copy" id="copy-btn-<?= $i ?>" onclick="copyConfig(<?= $i ?>)">Copy</button>
            <a class="btn-dl" href="#" onclick="downloadConfig(<?= $i ?>, <?= json_encode($item['label']) ?>); return false;">&#8659; Download</a>
          </div>
        </div>
        <pre class="config-block" id="config-<?= $i ?>"><?= htmlspecialchars($item['config'], ENT_QUOTES, 'UTF-8') ?></pre>
      </section>
    <?php endforeach; ?>

    <?php if (empty($configs)): ?>
      <section class="card" style="text-align:center; padding:3rem; color:var(--muted);">
        No devices were submitted. <a href="index.php">Go back to the form</a>.
      </section>
    <?php endif; ?>

  </main>

  <script>
    function copyConfig(idx) {
      const text = document.getElementById('config-' + idx).textContent;
      const btn = document.getElementById('copy-btn-' + idx);
      navigator.clipboard.writeText(text).then(() => {
        btn.textContent = 'Copied!';
        btn.classList.add('done');
        setTimeout(() => {
          btn.textContent = 'Copy';
          btn.classList.remove('done');
        }, 2000);
      });
    }

    function downloadConfig(idx, name) {
      const text = document.getElementById('config-' + idx).textContent;
      const blob = new Blob([text], {
        type: 'text/plain'
      });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = name.replace(/[^a-zA-Z0-9_\-]/g, '_') + '.txt';
      a.click();
      URL.revokeObjectURL(url);
    }
  </script>

</body>

</html>