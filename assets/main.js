(function () {
  const fieldConfigNode = document.getElementById('field-config');
  const initialGeoipNode = document.getElementById('initial-geoip');
  const initialQueryIpNode = document.getElementById('initial-query-ip');
  const gridV4 = document.getElementById('grid-v4');
  const gridV6 = document.getElementById('grid-v6');
  const gridQuery = document.getElementById('grid-query');
  const gridDual = document.getElementById('grid-dual');
  const groupDual = document.getElementById('group-dual');
  const groupV4 = document.getElementById('group-v4');
  const groupV6 = document.getElementById('group-v6');
  const groupQuery = document.getElementById('group-query');
  const queryIpSummary = document.getElementById('query-ip-summary');
  const statusEl = document.getElementById('status');
  const queryForm = document.getElementById('ip-query-form');
  const queryInput = document.getElementById('ip-query-input');
  const heroFocusQueryBtn = document.getElementById('hero-focus-query');

  if (!fieldConfigNode || !statusEl || !gridV4 || !gridV6 || !gridQuery || !gridDual || !groupDual || !groupV4 || !groupV6 || !groupQuery) return;

  let fields = [];
  let initialData = null;
  let initialQueryIp = null;

  const fieldIconMap = {
    ip: 'fa-solid fa-network-wired',
    country_code: 'fa-solid fa-flag',
    continent: 'fa-solid fa-earth-asia',
    country: 'fa-solid fa-location-dot',
    province: 'fa-solid fa-map',
    city: 'fa-solid fa-buildings',
    district: 'fa-solid fa-map-location-dot',
    isp: 'fa-solid fa-router',
    longitude: 'fa-solid fa-arrows-left-right',
    latitude: 'fa-solid fa-arrows-up-down',
    area_code: 'fa-solid fa-hashtag',
    phone_code: 'fa-solid fa-phone',
    zip_code: 'fa-solid fa-envelopes-bulk',
    timezone: 'fa-solid fa-clock',
    currency: 'fa-solid fa-coins',
    asn: 'fa-solid fa-sitemap'
  };

  const fieldDescMap = {
    ip: '访问者的公网 IP 地址，可能是 IPv4 或 IPv6。',
    country_code: 'ISO 3166-1 两位国家代码，例如 US、CN。',
    continent: 'IP 所属的大洲。',
    country: 'IP 所属国家或地区。',
    province: 'IP 所在省/州（若数据库可识别）。',
    city: 'IP 所在城市（若数据库可识别）。',
    district: 'IP 所在区县（若数据库可识别）。',
    isp: '网络运营商/服务提供商信息。',
    longitude: '经度坐标（用于地理定位）。',
    latitude: '纬度坐标（用于地理定位）。',
    area_code: '行政区划代码（若有）。',
    phone_code: '电话区号（若有）。',
    zip_code: '邮政编码（若有）。',
    timezone: '该 IP 所在地时区。',
    currency: '该地区常用货币代码。',
    asn: '自治系统编号（Autonomous System Number）。'
  };

  try {
    fields = JSON.parse(fieldConfigNode.textContent || '[]');
  } catch (_) {
    fields = [];
  }

  try {
    initialData = JSON.parse(initialGeoipNode ? (initialGeoipNode.textContent || 'null') : 'null');
  } catch (_) {
    initialData = null;
  }

  try {
    initialQueryIp = JSON.parse(initialQueryIpNode ? (initialQueryIpNode.textContent || 'null') : 'null');
  } catch (_) {
    initialQueryIp = null;
  }

  function safeText(value) {
    if (value === null || value === undefined || value === '') return '—';
    return String(value);
  }

  function hasValue(value) {
    return !(value === null || value === undefined || value === '');
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function detectIpVersion(ip) {
    const text = String(ip || '').trim();
    if (/^\d{1,3}(?:\.\d{1,3}){3}$/.test(text)) return 'IPv4';
    if (text.includes(':')) return 'IPv6';
    return 'IP';
  }

  function encodeIpForPath(ip) {
    // Keep ":" readable in IPv6 URL path while preserving other unsafe chars.
    return encodeURIComponent(String(ip || '').trim()).replace(/%3A/gi, ':');
  }

  function isDirectAddressHost(host) {
    const text = String(host || '').trim();
    if (!text) return true;
    if (text === 'localhost') return true;
    if (/^\d{1,3}(?:\.\d{1,3}){3}$/.test(text)) return true;
    return text.includes(':');
  }

  function getProbeHost(prefix) {
    const currentHost = String(window.location.hostname || '').trim().toLowerCase();
    if (!currentHost || isDirectAddressHost(currentHost)) {
      return prefix + '.bluecdn.com';
    }

    const labels = currentHost.split('.');
    if (labels[0] === 'ipv4' || labels[0] === 'ipv6' || labels[0] === 'ip' || labels[0] === 'www') {
      labels.shift();
    }

    if (labels.length < 2) {
      return prefix + '.bluecdn.com';
    }

    return prefix + '.' + labels.join('.');
  }

  async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return;
    }

    const input = document.createElement('textarea');
    input.value = text;
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.focus();
    input.select();
    document.execCommand('copy');
    document.body.removeChild(input);
  }

  function renderValue(field, data) {
    if (field.key === 'ip') {
      const ipText = safeText(data.ip);
      return [
        '<span class="result-ip">',
        '<span class="ip-text">' + escapeHtml(ipText) + '</span>',
        '</span>'
      ].join('');
    }

    if (field.key === 'country') {
      const codeRaw = typeof data.country_code === 'string' ? data.country_code.trim() : '';
      const code = /^[a-zA-Z]{2}$/.test(codeRaw) ? codeRaw.toLowerCase() : '';
      const codeUpper = code ? code.toUpperCase() : '';
      const countryText = hasValue(data.country) ? escapeHtml(String(data.country)) : '—';
      if (code) {
        return [
          '<span class="result-country">',
          '<span class="fi fi-' + code + ' country-flag" aria-hidden="true"></span>',
          '<span>' + countryText + '</span>',
          '<span class="country-code-badge">' + codeUpper + '</span>',
          '</span>'
        ].join('');
      }
      return countryText;
    }

    return escapeHtml(safeText(data[field.key]));
  }

  function renderTo(gridEl, data) {
    const visibleFields = fields.filter((field) => {
      if (field.key === 'ip') {
        if (gridEl.id === 'grid-query') return false;
        return hasValue(data.ip);
      }
      if (field.key === 'country') return hasValue(data.country) || hasValue(data.country_code);
      return hasValue(data[field.key]);
    });

    const html = visibleFields.map((field) => {
      const iconClass = fieldIconMap[field.key] || 'fa-solid fa-circle-info';
      const desc = fieldDescMap[field.key] || '';
      const tipAttrs = desc ? ' title="' + escapeHtml(desc) + '" data-tip="' + escapeHtml(desc) + '"' : '';
      const labelHtml = field.key === 'ip'
        ? (function () {
            const version = detectIpVersion(safeText(data.ip));
            const badgeClass = version === 'IPv6' ? 'ip-badge ip-badge-v6' : 'ip-badge ip-badge-v4';
            return '<span class="k k-ip field-help"' + tipAttrs + '><span class="' + badgeClass + '">' + version + '</span></span>';
          })()
        : '<span class="k field-help"' + tipAttrs + '><i class="' + iconClass + ' field-icon" aria-hidden="true"></i>' + field.label + '</span>';
      const rowClass = field.key === 'ip' ? 'result-row result-row-ip' : 'result-row';
      const ipCopyBtnHtml = field.key === 'ip'
        ? '<button class="ip-copy-btn ip-copy-icon-btn" type="button" data-ip="' + escapeHtml(safeText(data.ip)) + '" aria-label="复制 IP"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>'
        : '';
      return [
        '<div class="' + rowClass + '">',
        labelHtml,
        '<span class="v">' + renderValue(field, data) + '</span>',
        ipCopyBtnHtml,
        '</div>'
      ].join('');
    }).join('');

    gridEl.innerHTML = html;
  }

  function renderDualTo(gridEl, dataV4, dataV6) {
    const visibleFields = fields.filter((field) => {
      if (field.key === 'country') {
        return hasValue(dataV4.country) || hasValue(dataV4.country_code) || hasValue(dataV6.country) || hasValue(dataV6.country_code);
      }
      return hasValue(dataV4[field.key]) || hasValue(dataV6[field.key]);
    });

    const headHtml = [
      '<div class="result-row result-row-dual result-row-dual-head">',
      '<span class="k">字段</span>',
      '<span class="v v4-col"><span class="ip-badge ip-badge-v4">IPv4</span></span>',
      '<span class="v v6-col"><span class="ip-badge ip-badge-v6">IPv6</span></span>',
      '</div>'
    ].join('');

    const rowsHtml = visibleFields.map((field) => {
      const iconClass = fieldIconMap[field.key] || 'fa-solid fa-circle-info';
      const desc = fieldDescMap[field.key] || '';
      const tipAttrs = desc ? ' title="' + escapeHtml(desc) + '" data-tip="' + escapeHtml(desc) + '"' : '';
      const labelHtml = '<span class="k field-help"' + tipAttrs + '><i class="' + iconClass + ' field-icon" aria-hidden="true"></i>' + field.label + '</span>';
      return [
        '<div class="result-row result-row-dual">',
        labelHtml,
        '<span class="v v4-col">' + renderValue(field, dataV4) + '</span>',
        '<span class="v v6-col">' + renderValue(field, dataV6) + '</span>',
        '</div>'
      ].join('');
    }).join('');

    gridEl.innerHTML = headHtml + rowsHtml;
  }

  function hideQueryIpSummary() {
    if (!queryIpSummary) return;
    queryIpSummary.hidden = true;
    queryIpSummary.innerHTML = '';
  }

  function renderQueryIpSummary(ip) {
    if (!queryIpSummary) return;
    const ipText = safeText(ip);
    if (!ipText || ipText === '—') {
      hideQueryIpSummary();
      return;
    }
    const version = detectIpVersion(ipText);
    const badgeClass = version === 'IPv6' ? 'ip-badge ip-badge-v6' : 'ip-badge ip-badge-v4';
    queryIpSummary.innerHTML = [
      '<span class="query-ip-summary-inner">',
      '<span class="' + badgeClass + '">' + version + '</span>',
      '<span class="query-ip-summary-text">' + escapeHtml(ipText) + '</span>',
      '<button class="ip-copy-btn" type="button" data-ip="' + escapeHtml(ipText) + '">复制</button>',
      '</span>'
    ].join('');
    queryIpSummary.hidden = false;
  }

  function setStatus(text, ok) {
    statusEl.textContent = text;
    statusEl.classList.remove('ok', 'err');
    statusEl.classList.add(ok ? 'ok' : 'err');
  }

  function setStatusHtml(html, ok) {
    statusEl.innerHTML = html;
    statusEl.classList.remove('ok', 'err');
    statusEl.classList.add(ok ? 'ok' : 'err');
  }

  function setQuerySuccessStatus(ip) {
    const ipText = safeText(ip);
    const version = detectIpVersion(ipText);
    const badgeClass = version === 'IPv6' ? 'ip-badge ip-badge-v6' : 'ip-badge ip-badge-v4';
    const html = [
      '<span class="status-success-wrap">',
      '<span>查询成功：</span>',
      '<span class="' + badgeClass + '">' + version + '</span>',
      '<span class="status-success-ip">' + escapeHtml(ipText) + '</span>',
      '</span>'
    ].join('');
    setStatusHtml(html, true);
  }

  async function fetchGeoip(urlPath) {
    const res = await fetch(urlPath, {
      headers: { Accept: 'application/json' },
      cache: 'no-store'
    });
    if (!res.ok) {
      let message = 'HTTP ' + res.status;
      try {
        const err = await res.json();
        if (err && err.error) message = err.error;
      } catch (_) {
        // ignore json parse errors
      }
      throw new Error(message);
    }

    return res.json();
  }

  async function getIpFromHost(host) {
    const res = await fetch('https://' + host, { cache: 'no-store' });
    if (!res.ok) throw new Error(host + ' HTTP ' + res.status);
    const text = (await res.text()).trim();
    return text.replace(/^IPv[46]:\s*/i, '').trim();
  }

  async function loadLocalIps() {
    hideQueryIpSummary();
    groupDual.hidden = true;
    groupV4.hidden = true;
    groupV6.hidden = true;
    groupQuery.hidden = true;

    const jobs = [
      { version: 'v4', host: getProbeHost('ipv4'), grid: gridV4, group: groupV4 },
      { version: 'v6', host: getProbeHost('ipv6'), grid: gridV6, group: groupV6 }
    ];

    const results = await Promise.allSettled(jobs.map(async (job) => {
      const ip = await getIpFromHost(job.host);
      if (!ip) throw new Error('Empty IP from ' + job.host);
      const data = await fetchGeoip('/geoip/' + encodeURIComponent(ip));
      return { ...job, ip, data };
    }));

    let shown = 0;
    results.forEach((result) => {
      if (result.status === 'fulfilled') {
        renderTo(result.value.grid, result.value.data);
        result.value.group.hidden = false;
        shown += 1;
      }
    });

    if (shown === 2) {
      groupDual.hidden = true;
      groupV4.hidden = false;
      groupV6.hidden = false;
      groupQuery.hidden = true;
      hideQueryIpSummary();
      setStatus('已识别本机 IPv4 + IPv6', true);
      return;
    }

    if (shown === 1) {
      const successItem = results.find((item) => item.status === 'fulfilled');
      if (successItem && successItem.status === 'fulfilled') {
        groupDual.hidden = true;
        groupV4.hidden = true;
        groupV6.hidden = true;
        renderTo(gridQuery, successItem.value.data);
        renderQueryIpSummary(successItem.value.data.ip);
        groupQuery.hidden = false;
        setStatus('已识别本机 IP（' + (successItem.value.version === 'v6' ? 'IPv6' : 'IPv4') + '）', true);
        return;
      }
      return;
    }

    try {
      const fallback = await fetchGeoip('/geoip');
      groupDual.hidden = true;
      renderTo(gridQuery, fallback);
      groupQuery.hidden = false;
      hideQueryIpSummary();
      setStatus('本机 IPv4/IPv6 不可用，已回退到 /geoip', true);
    } catch (err) {
      setStatus('加载失败：' + (err && err.message ? err.message : '未知错误'), false);
    }
  }

  async function searchByIp(ip) {
    const clean = (ip || '').trim();
    if (!clean) {
      setStatus('请输入 IP 地址', false);
      return;
    }

    try {
      const data = await fetchGeoip('/geoip/' + encodeURIComponent(clean));
      groupDual.hidden = true;
      groupV4.hidden = true;
      groupV6.hidden = true;
      renderTo(gridQuery, data);
      groupQuery.hidden = false;
      renderQueryIpSummary(data.ip || clean);
      setQuerySuccessStatus(data.ip || clean);
      history.pushState({ ip: clean }, '', '/ip/' + encodeIpForPath(clean));
    } catch (err) {
      setStatus('查询失败：' + (err && err.message ? err.message : '未知错误'), false);
    }
  }

  function highlightAndFocusQueryInput() {
    if (!queryInput) return;
    queryInput.focus({ preventScroll: true });
    queryInput.classList.remove('query-focus-attention');
    void queryInput.offsetWidth;
    queryInput.classList.add('query-focus-attention');
    window.setTimeout(function () {
      queryInput.classList.remove('query-focus-attention');
    }, 500);
  }

  if (initialData && typeof initialData === 'object') {
    groupDual.hidden = true;
    renderTo(gridQuery, initialData);
    groupQuery.hidden = false;
    hideQueryIpSummary();
  }

  if (initialQueryIp && typeof initialQueryIp === 'string' && initialQueryIp.trim() !== '') {
    if (queryInput) queryInput.value = initialQueryIp;
    groupDual.hidden = true;
    groupV4.hidden = true;
    groupV6.hidden = true;
    groupQuery.hidden = false;
    renderQueryIpSummary(initialData && initialData.ip ? initialData.ip : initialQueryIp);
    setQuerySuccessStatus(initialData && initialData.ip ? initialData.ip : initialQueryIp);
  } else {
    setStatus('正在识别本机 IPv4 / IPv6 ...', true);
  }

  if (queryForm && queryInput) {
    queryForm.addEventListener('submit', function (event) {
      event.preventDefault();
      searchByIp(queryInput.value);
    });
  }

  if (heroFocusQueryBtn) {
    heroFocusQueryBtn.addEventListener('click', function () {
      const queryPanel = document.getElementById('query-panel');
      if (queryPanel) {
        queryPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      window.setTimeout(highlightAndFocusQueryInput, 220);
    });
  }

  document.addEventListener('click', async function (event) {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const btn = target.closest('.ip-copy-btn');
    if (!btn) return;

    const ip = btn.getAttribute('data-ip') || '';
    if (!ip || ip === '—') return;

    try {
      await copyText(ip);
      setStatus('已复制 IP：' + ip, true);
      const icon = btn.querySelector('i');
      if (icon) {
        icon.className = 'fa-regular fa-check';
      }
      btn.classList.add('copied');
      window.setTimeout(function () {
        const resetIcon = btn.querySelector('i');
        if (resetIcon) {
          resetIcon.className = 'fa-regular fa-copy';
        }
        btn.classList.remove('copied');
      }, 1200);
    } catch (_) {
      setStatus('复制失败，请手动复制', false);
    }
  });

  if (!(initialQueryIp && typeof initialQueryIp === 'string' && initialQueryIp.trim() !== '')) {
    loadLocalIps();
  }

  window.setTimeout(highlightAndFocusQueryInput, 240);
})();
