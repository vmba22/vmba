(function(){
  'use strict';

  const path = location.pathname.toLowerCase();
  const isList = /\/activistas\.php$/.test(path);
  const isDetail = /\/activista_detalle\.php$/.test(path);
  const isAttendance = /\/actividad_asistencia\.php$/.test(path);
  if (!isList && !isDetail && !isAttendance) return;

  document.body.classList.add('e360-v11');
  if (isList) document.body.classList.add('e360-v11-list');
  if (isDetail) document.body.classList.add('e360-v11-detail');
  if (isAttendance) document.body.classList.add('e360-v11-attendance');

  const script = Array.from(document.scripts).find(s => /expediente360_v11\.js/.test(s.src));
  const base = script ? script.src.replace(/assets\/js\/expediente360_v11\.js.*$/,'') : location.pathname.replace(/[^/]+$/,'');
  const apiUrl = base + 'api/expediente360_v11.php';

  const esc = value => String(value == null ? '' : value).replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
  const digits = value => String(value || '').replace(/\D+/g,'');
  const first = (selectors, root=document) => {
    for (const selector of selectors) {
      const node = root.querySelector(selector);
      if (node) return node;
    }
    return null;
  };

  function toast(message, error=false){
    const old = document.querySelector('.e360-v11-toast');
    if (old) old.remove();
    const node = document.createElement('div');
    node.className = 'e360-v11-toast' + (error ? ' is-error' : '');
    node.textContent = message;
    document.body.appendChild(node);
    setTimeout(() => node.remove(), 4200);
  }

  async function api(action, params={}, method='GET'){
    let response;
    if (method === 'GET') {
      const url = new URL(apiUrl, location.href);
      url.searchParams.set('action', action);
      Object.entries(params).forEach(([key,value]) => {
        if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, value);
      });
      response = await fetch(url.toString(), {credentials:'same-origin', headers:{'Accept':'application/json'}});
    } else {
      const body = new FormData();
      body.append('action', action);
      Object.entries(params).forEach(([key,value]) => body.append(key, value == null ? '' : value));
      response = await fetch(apiUrl, {method:'POST', body, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
    }
    let json;
    try { json = await response.json(); }
    catch (e) { throw new Error('El servidor devolvió una respuesta inválida.'); }
    if (!response.ok || !json.ok) throw new Error(json.error || 'No fue posible completar la operación.');
    return json;
  }

  function updateMenuLabel(){
    document.querySelectorAll('a[href*="activistas.php"]').forEach(link => {
      const textNodes = Array.from(link.childNodes).filter(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim());
      if (textNodes.length) textNodes[textNodes.length - 1].textContent = ' Expediente 360°';
      else {
        const label = link.querySelector('span:last-child');
        if (label) label.textContent = 'Expediente 360°';
      }
      link.setAttribute('title','Expediente 360°');
    });
  }

  function mobileBar(){
    if (document.querySelector('.e360-v11-mobilebar')) return;
    const bar = document.createElement('div');
    bar.className = 'e360-v11-mobilebar';
    const title = isList ? 'Expediente 360°' : isDetail ? 'Detalle del expediente' : 'Registro de asistentes';
    const back = isList ? (base + 'actividades.php') : isDetail ? (base + 'activistas.php') : (base + 'actividades.php');
    bar.innerHTML = `<a href="${esc(back)}" aria-label="Volver">‹</a><div><strong>${esc(title)}</strong><small>VPNACIONAL · trazabilidad nacional</small></div>`;
    document.body.insertBefore(bar, document.body.firstChild);
  }

  function findFilterForm(){
    return Array.from(document.forms).find(form => {
      const fields = form.querySelectorAll('select,input[type="search"],input[type="text"]');
      return fields.length >= 2 && !form.querySelector('input[name*="password" i]');
    }) || null;
  }

  function enhanceFilterForm(){
    const form = findFilterForm();
    if (!form || form.classList.contains('e360-v11-filter-form')) return form;
    form.classList.add('e360-v11-filter-form');
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'e360-v11-filter-toggle';
    button.innerHTML = '☷ <span>Mostrar filtros</span>';
    button.addEventListener('click', () => {
      const open = form.classList.toggle('is-open');
      button.querySelector('span').textContent = open ? 'Ocultar filtros' : 'Mostrar filtros';
    });
    form.parentNode.insertBefore(button, form);
    return form;
  }

  function locateKpiCards(){
    return Array.from(document.querySelectorAll('.card,.stat,.kpi,.metric,[class*="stat"],[class*="metric"]')).filter(card => {
      const text = card.textContent.toLowerCase();
      return /expediente|activista|no rep|última actividad|ultima actividad|activos/.test(text);
    });
  }

  function setCard(card, label, value, note){
    const candidates = Array.from(card.querySelectorAll('strong,b,h2,h3,[class*="value"],[class*="number"]'));
    const numberNode = candidates.find(node => /[\d.,]+/.test(node.textContent)) || candidates[candidates.length-1];
    if (numberNode) numberNode.textContent = Number(value || 0).toLocaleString('es-VE');
    const labelNode = Array.from(card.querySelectorAll('span,small,p,div')).find(node => node.children.length === 0 && /expediente|activista|no rep|última actividad|ultima actividad|activos|simpatizante/i.test(node.textContent));
    if (labelNode) labelNode.textContent = label;
    if (note) {
      const small = Array.from(card.querySelectorAll('small,p')).pop();
      if (small && small !== labelNode) small.textContent = note;
    }
  }

  async function loadStats(anchor){
    try {
      const {stats} = await api('stats');
      const cards = locateKpiCards();
      const definitions = [
        ['Total de expedientes', stats.total, 'Personas únicas por cédula'],
        ['Activistas', stats.activistas, 'Una vinculación organizativa activa'],
        ['Simpatizantes', stats.simpatizantes, 'Sin cargo organizativo activo'],
        ['No REP', stats.no_rep, 'Pendientes de verificación electoral'],
        ['Actividad reciente', stats.actividad_7d, 'Participaron en los últimos 7 días']
      ];
      if (cards.length >= 4) {
        definitions.forEach((def,index) => { if (cards[index]) setCard(cards[index],def[0],def[1],def[2]); });
        if (stats.inconsistencias > 0 && !document.querySelector('.e360-v11-stat-inconsistency')) {
          const grid = cards[0].parentElement;
          if (grid) {
            const c = document.createElement('div');
            c.className = 'e360-v11-stat e360-v11-stat-inconsistency is-red';
            c.innerHTML = `<span>Inconsistencias activas</span><strong>${stats.inconsistencias.toLocaleString('es-VE')}</strong>`;
            grid.appendChild(c);
          }
        }
      } else if (anchor) {
        const grid = document.createElement('section');
        grid.className = 'e360-v11-dashboard-grid';
        grid.innerHTML = `
          <article class="e360-v11-stat is-orange"><span>Personas únicas</span><strong>${stats.total.toLocaleString('es-VE')}</strong></article>
          <article class="e360-v11-stat is-orange"><span>Activistas</span><strong>${stats.activistas.toLocaleString('es-VE')}</strong></article>
          <article class="e360-v11-stat is-blue"><span>Simpatizantes</span><strong>${stats.simpatizantes.toLocaleString('es-VE')}</strong></article>
          <article class="e360-v11-stat"><span>No REP</span><strong>${stats.no_rep.toLocaleString('es-VE')}</strong></article>
          <article class="e360-v11-stat is-green"><span>Actividad reciente</span><strong>${stats.actividad_7d.toLocaleString('es-VE')}</strong></article>
          <article class="e360-v11-stat is-red"><span>Inconsistencias</span><strong>${stats.inconsistencias.toLocaleString('es-VE')}</strong></article>`;
        anchor.parentNode.insertBefore(grid, anchor.nextSibling);
      }
    } catch (error) {
      console.warn('Expediente 360 stats:', error);
    }
  }

  function getSearchValue(){
    const input = first([
      'input[type="search"]',
      'input[placeholder*="cédula" i]',
      'input[placeholder*="cedula" i]',
      'input[name="q"]',
      'input[name*="buscar" i]'
    ]);
    return input ? input.value.trim() : '';
  }

  function getStateValue(){
    const selects = Array.from(document.querySelectorAll('select'));
    const state = selects.find(select => /estado/i.test(select.name || select.id || select.previousElementSibling?.textContent || ''));
    return state ? state.value : '';
  }

  function initials(name){
    const parts = String(name || '?').trim().split(/\s+/).filter(Boolean);
    return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || '?';
  }

  function personRow(person){
    const classification = person.clasificacion === 'ACTIVISTA' ? 'ACTIVISTA' : 'SIMPATIZANTE';
    const place = [person.estado,person.municipio].filter(Boolean).join(' · ') || 'Sin ubicación electoral';
    const secondary = [person.parroquia,person.centro].filter(Boolean).join(' · ') || 'Sin parroquia o centro';
    const warning = Number(person.inconsistencias || 0) > 0 ? '<span class="e360-v11-badge is-warning">⚠ Revisar vinculación</span>' : '';
    return `<article class="e360-v11-person-row" data-cedula="${esc(person.cedula_normalizada)}">
      <div class="e360-v11-person-main"><div class="e360-v11-avatar">${esc(initials(person.nombre_completo))}</div><div><div class="e360-v11-name">${esc(person.nombre_completo || 'SIN NOMBRE')}</div><div class="e360-v11-sub">C.I. ${esc(person.cedula_normalizada)} · ${esc(person.telefono_principal || 'Sin teléfono')}</div></div></div>
      <div class="e360-v11-place">${esc(place)}<small>${esc(secondary)}</small></div>
      <span class="e360-v11-badge ${classification === 'ACTIVISTA' ? 'is-activista' : 'is-simpatizante'}">${classification}</span>
      <div class="e360-v11-activity-count">${Number(person.cantidad_actividades || 0).toLocaleString('es-VE')} actividades</div>
      <div>${warning}</div>
      <a class="e360-v11-open" href="${base}activista_detalle.php?cedula=${encodeURIComponent(person.cedula_normalizada)}" aria-label="Abrir expediente">›</a>
    </article>`;
  }

  async function loadPeople(state, results, countNode){
    results.innerHTML = '<div class="e360-v11-loading">Cargando expedientes…</div>';
    try {
      const response = await api('list', {
        page:state.page,
        per_page:50,
        clasificacion:state.classification,
        q:getSearchValue(),
        estado:getStateValue()
      });
      const data = response.data;
      countNode.textContent = `${Number(data.total).toLocaleString('es-VE')} expedientes`;
      const rows = data.rows.map(personRow).join('');
      results.innerHTML = `<div class="e360-v11-results-head"><span>Persona</span><span>Ubicación</span><span>Clasificación</span><span>Participación</span><span>Control</span><span></span></div>${rows || '<div class="e360-v11-empty">No se encontraron expedientes con esos filtros.</div>'}<div class="e360-v11-pagination"><button type="button" data-page="prev" ${data.page <= 1 ? 'disabled' : ''}>← Anterior</button><strong>Página ${data.page} de ${data.pages}</strong><button type="button" data-page="next" ${data.page >= data.pages ? 'disabled' : ''}>Siguiente →</button></div>`;
      results.querySelector('[data-page="prev"]')?.addEventListener('click',()=>{state.page--;loadPeople(state,results,countNode);});
      results.querySelector('[data-page="next"]')?.addEventListener('click',()=>{state.page++;loadPeople(state,results,countNode);});
    } catch (error) {
      results.innerHTML = `<div class="e360-v11-empty">${esc(error.message)}</div>`;
    }
  }

  function listPage(){
    updateMenuLabel();
    mobileBar();
    const form = enhanceFilterForm();
    const main = first(['main','.main-content','.content','.container','.dashboard-container']) || document.body;
    const header = first(['h1','h2'], main) || main.firstElementChild;
    loadStats(header);

    const oldTable = document.querySelector('table');
    const oldWrap = oldTable ? (oldTable.closest('.card,.panel,.table-wrapper,section') || oldTable) : null;
    if (oldTable) oldTable.classList.add('e360-v11-source-table');
    if (oldWrap) oldWrap.classList.add('e360-v11-old-results','is-hidden');

    const state = {classification:'',page:1};
    const bar = document.createElement('div');
    bar.className = 'e360-v11-classbar';
    bar.innerHTML = `<button type="button" class="e360-v11-filter-pill is-active" data-class="">Todos</button><button type="button" class="e360-v11-filter-pill" data-class="ACTIVISTA">Activistas</button><button type="button" class="e360-v11-filter-pill" data-class="SIMPATIZANTE">Simpatizantes</button><span class="e360-v11-count">Cargando…</span>`;
    const countNode = bar.querySelector('.e360-v11-count');
    const results = document.createElement('section');
    results.className = 'e360-v11-results';

    const anchor = form || oldWrap || header;
    if (anchor && anchor.parentNode) {
      anchor.parentNode.insertBefore(bar, anchor.nextSibling);
      bar.parentNode.insertBefore(results, bar.nextSibling);
    } else {
      main.appendChild(bar); main.appendChild(results);
    }

    bar.querySelectorAll('[data-class]').forEach(button => button.addEventListener('click',()=>{
      bar.querySelectorAll('[data-class]').forEach(item=>item.classList.remove('is-active'));
      button.classList.add('is-active');
      state.classification = button.dataset.class || '';
      state.page = 1;
      loadPeople(state,results,countNode);
    }));

    if (form) {
      form.addEventListener('submit', event => {
        event.preventDefault();
        state.page = 1;
        loadPeople(state,results,countNode);
      });
      form.querySelectorAll('select').forEach(select => select.addEventListener('change',()=>{state.page=1;loadPeople(state,results,countNode);}));
    }
    const topSearch = first(['input[type="search"]','input[placeholder*="cédula" i]']);
    if (topSearch && (!form || !form.contains(topSearch))) {
      let timer;
      topSearch.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>{state.page=1;loadPeople(state,results,countNode);},350);});
    }
    loadPeople(state,results,countNode);
  }

  function findCedulaOnDetail(){
    const url = new URL(location.href);
    const query = digits(url.searchParams.get('cedula'));
    if (query) return query;
    const text = document.body.innerText;
    const match = text.match(/(?:C\.?\s*I\.?|CÉDULA|CEDULA)\s*[:#]?\s*([VE-]?\s*[\d.]{5,})/i);
    return match ? digits(match[1]) : '';
  }

  function updateDetailLabels(detail){
    const classification = detail.clasificacion.clasificacion;
    document.querySelectorAll('*').forEach(node => {
      if (node.children.length) return;
      const value = node.textContent.trim();
      if (/^activista encontrado$/i.test(value) || /^activista$/i.test(value) || /^simpatizante$/i.test(value)) {
        node.textContent = classification === 'ACTIVISTA' ? 'Activista' : 'Simpatizante';
      }
    });
  }

  async function detailPage(){
    updateMenuLabel();
    mobileBar();
    const cedula = findCedulaOnDetail();
    if (!cedula) return;
    try {
      const response = await api('detail',{cedula});
      const detail = response.data;
      updateDetailLabels(detail);
      const classification = detail.clasificacion.clasificacion;
      const link = detail.clasificacion.vinculacion_actual;
      const banner = document.createElement('section');
      banner.className = 'e360-v11-detail-banner';
      banner.innerHTML = `<div class="e360-v11-detail-icon">360°</div><div><div class="e360-v11-detail-title">${classification === 'ACTIVISTA' ? 'Activista con vinculación organizativa activa' : 'Simpatizante del partido'}</div><div class="e360-v11-detail-meta">${link ? esc(`${link.tipo} · ${link.cargo || 'Sin cargo identificado'}`) : 'No pertenece activamente a Estructuras, Redes Populares ni equipos de Centros de Votación.'}</div></div><span class="e360-v11-badge ${classification === 'ACTIVISTA' ? 'is-activista' : 'is-simpatizante'}">${classification}</span>`;
      const h1 = document.querySelector('h1');
      const hero = h1 ? (h1.closest('.card,.panel,.hero,.profile-header,section') || h1.parentElement) : first(['main','.main-content','.container']);
      if (hero && hero.parentNode) hero.parentNode.insertBefore(banner, hero.nextSibling);
      else document.body.insertBefore(banner, document.body.children[1] || null);
      if (detail.clasificacion.inconsistencia) {
        const alert = document.createElement('div');
        alert.className = 'e360-v11-alert';
        alert.textContent = 'Esta persona aparece con más de una vinculación organizativa activa. Debe regularizarse mediante un traslado y dejar una sola instancia activa.';
        banner.parentNode.insertBefore(alert,banner.nextSibling);
      }
    } catch (error) {
      console.warn('Detalle Expediente 360:',error);
    }
  }

  function findField(form, candidates, labelPattern){
    for (const selector of candidates) {
      const field = form.querySelector(selector);
      if (field) return field;
    }
    const labels = Array.from(form.querySelectorAll('label'));
    const label = labels.find(item => labelPattern.test(item.textContent));
    if (label) {
      const forId = label.getAttribute('for');
      if (forId) return form.querySelector('#' + CSS.escape(forId));
      return label.querySelector('input') || label.parentElement?.querySelector('input');
    }
    return null;
  }

  function attendancePage(){
    mobileBar();
    const params = new URL(location.href).searchParams;
    document.querySelectorAll('form').forEach(form => {
      const cedula = findField(form,['input[name="cedula"]','input[name*="cedula" i]'],/cédula|cedula/i);
      const phone = findField(form,['input[name="telefono"]','input[name="telefono_reportado"]','input[name*="telefono" i]'],/teléfono|telefono/i);
      if (!cedula || !phone || form.dataset.e360v11Bound) return;
      const name = findField(form,['input[name="nombre"]','input[name="nombre_completo"]','input[name*="nombre" i]'],/nombre/i);
      const activity = form.querySelector('input[name="actividad_id"]');
      form.dataset.e360v11Bound = '1';

      let lookupTimer;
      cedula.addEventListener('input',()=>{
        clearTimeout(lookupTimer);
        if (name) { name.readOnly = false; name.removeAttribute('aria-readonly'); }
        lookupTimer = setTimeout(async()=>{
          const value = digits(cedula.value);
          if (value.length < 5) return;
          try {
            const response = await api('lookup_rep',{cedula:value});
            if (response.found && name) {
              name.value = response.nombre;
              name.readOnly = true;
              name.setAttribute('aria-readonly','true');
            } else if (name) {
              name.value = '';
              name.readOnly = false;
              name.focus();
              toast('La persona no aparece en el REP. Escriba nombre y apellido.',true);
            }
          } catch (error) {}
        },450);
      });

      form.addEventListener('submit', async event => {
        if (form.dataset.e360v11Submit === '1') return;
        event.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        if (submit) submit.disabled = true;
        try {
          const response = await api('prepare_attendee',{
            actividad_id:activity?.value || params.get('id') || params.get('actividad_id') || '',
            cedula:cedula.value,
            telefono:phone.value,
            nombre:name?.value || ''
          },'POST');
          if (name) {
            name.value = response.data.nombre;
            if (response.data.rep_encontrado) name.readOnly = true;
          }
          let hidden = form.querySelector('input[name="persona_id"]');
          if (!hidden) { hidden=document.createElement('input');hidden.type='hidden';hidden.name='persona_id';form.appendChild(hidden); }
          hidden.value = response.data.persona_id;
          form.dataset.e360v11Submit = '1';
          toast(`${response.data.nombre} · ${response.data.clasificacion}`);
          HTMLFormElement.prototype.submit.call(form);
        } catch (error) {
          toast(error.message,true);
          if (/nombre/i.test(error.message) && name) { name.readOnly=false;name.focus(); }
          if (submit) submit.disabled = false;
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded',()=>{
    if (isList) listPage();
    if (isDetail) detailPage();
    if (isAttendance) attendancePage();
  });
})();
