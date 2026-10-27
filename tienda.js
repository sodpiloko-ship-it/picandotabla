/* Componente único de compra: todas las páginas comparten este panel y el catálogo.
   Intake de UNA sola hoja (2026-10-27, pedido de Jessica): la tarjeta abre el pedido completo
   en una sola página desplazable — tu tabla · complementos PRIMERO (Premium y extras, a la vista
   nada más abrir) · qué incluye (plegable) · entrega · fecha y pago (la fecha se elige al final,
   justo antes de pagar).
   Lo que se manda a pago/pedido.php no cambia. */
(function(){
  'use strict';
  if(!window.PICANDO_CATALOGO_V1) return;
  var WA='525623632404';
  function open(msg){ window.open('https://wa.me/'+WA+'?text='+encodeURIComponent(msg),'_blank','noopener'); }
  function track(ev,params){try{if(window.gtag) window.gtag('event',ev,params||{});}catch(e){}}
  document.body.insertAdjacentHTML('beforeend', [
  '<dialog id="ptModalBg" aria-labelledby="ptmNombre" onclick="if(event.target===this)cerrarDetalles()">',
  ' <div id="ptModal">',
  '  <header class="pt-sheet-header"><div class="pt-grip" aria-hidden="true"></div>',
  '   <div class="pt-sheet-top"><div><p class="pt-sheet-step" id="ptmPaso">Tu pedido</p><h2 id="ptmNombre"></h2></div>',
  '    <button type="button" id="ptmClose" onclick="cerrarDetalles()" aria-label="Cerrar pedido">✕</button></div>',
  '  </header>',
  '  <div class="pt-sheet-body">',
  // 1. Tu tabla
  '   <section class="pt-bloque">',
  '    <div class="pt-mini"><img id="ptmMiniImg" alt=""><div><b id="ptmMiniNombre"></b><span id="ptmMiniMeta"></span></div><div class="pt-mini-precio"><strong id="ptmMiniPrecio"></strong><small id="ptmPrecioNota"></small></div></div>',
  '    <div id="ptmPersonasBox" style="display:none"><label class="q-lbl" for="ptmPersonas">¿Para cuántas personas?</label><input id="ptmPersonas" class="q-in" type="number" inputmode="numeric" step="1" oninput="ptmRecalc()" onchange="ptmRecalc()"><div id="ptmPersonasNota" class="pt-ayuda" style="margin-top:6px"></div></div>',
  '   </section>',
  // 2. Complementos: lo PRIMERO que se ve al abrir, justo debajo de la tabla (David, 2026-10-27).
  '   <section class="pt-bloque" id="ptmBloqueUps">',
  '    <div class="pt-q">Súmale algo</div>',
  '    <div class="pt-ayuda" id="ptmToque"></div>',
  '    <label class="pt-up" id="ptmPremBox"><input type="checkbox" id="ptmPrem" onchange="ptmRecalc()">',
  '     <div class="pt-up-txt"><div class="pt-up-t"><span id="ptmPremTitulo">Hazla Premium</span></div>',
  '      <div class="pt-up-d" id="ptmPremDesc">La misma cantidad con una selección de mayor categoría (por ejemplo, quesos importados o charcutería de reserva, según disponibilidad). No es una tabla más grande.</div></div>',
  '     <span class="pt-up-p" id="ptmPremPrecio"></span></label>',
  '    <div id="ptmUpsBox"><div id="ptmUps" style="display:flex;flex-direction:column;gap:10px"></div></div>',
  '   </section>',
  // Qué incluye: plegable, después de los complementos (abierto solo al tocar la tarjeta).
  '   <section class="pt-bloque">',
  '    <details class="pt-detalle" id="ptmDetalle"><summary>Qué incluye tu tabla</summary>',
  '     <p id="ptmDesc"></p>',
  '     <div class="pt-incluye"><ul id="ptmInc"></ul></div>',
  '     <div class="pt-pronta" id="ptmPronta"></div>',
  '    </details>',
  '   </section>',
  // 3. Entrega
  '   <section class="pt-bloque">',
  '    <div class="pt-q">¿A quién y a dónde?</div>',
  '    <div class="pt-grid2">',
  '     <div class="pt-full"><label class="q-lbl" for="ptmCliente">Nombre</label><input id="ptmCliente" class="q-in" autocomplete="name" maxlength="120" placeholder="Tu nombre"></div>',
  '     <div><label class="q-lbl" for="ptmWhatsapp">Tu WhatsApp</label><input id="ptmWhatsapp" class="q-in" type="tel" inputmode="tel" autocomplete="tel" maxlength="25" placeholder="55 0000 0000"></div>',
  '     <div><label class="q-lbl" for="ptmZona">Colonia o municipio</label><input id="ptmZona" class="q-in" autocomplete="address-level3" maxlength="160" placeholder="Ej. Del Valle"></div>',
  '     <div class="pt-full"><label class="q-lbl" for="ptmNotas">Ajustes <span style="font-weight:400;color:#6b645b">(opcional)</span></label>',
  '      <textarea id="ptmNotas" class="q-in" rows="2" maxlength="600" placeholder="Ocasión, restricciones o sabores que prefieres"></textarea></div>',
  '    </div>',
  '   </section>',
  // 4. Fecha y pago (la fecha va al final, junto al pago)
  '   <section class="pt-bloque" id="ptmBloquePago">',
  '    <div class="pt-q">¿Qué día la quieres?</div>',
  '    <div class="pt-ayuda" id="ptmRegla"></div>',
  '    <div class="pt-dias" id="ptmDias" role="group" aria-label="Días de entrega disponibles"></div>',
  '    <label class="pt-calendario" for="ptmFecha"><span>Otra fecha · abrir calendario</span><input id="ptmFecha" type="date" onchange="ptmRecalc()" aria-describedby="ptmRegla"></label>',
  '    <div id="ptmModo" role="radiogroup" aria-label="¿Cómo quieres pagar?">',
  '     <div class="pt-q">¿Cómo quieres pagar?</div>',
  '     <label class="pt-up on" id="ptmModoCompleto"><input type="radio" name="ptmModo" value="completo" checked onchange="ptmRecalc()">',
  '      <div class="pt-up-txt"><div class="pt-up-t">Pago completo</div><div class="pt-up-d" id="ptmModoCompletoTxt"></div></div><span class="pt-up-p" id="ptmModoCompletoMonto"></span></label>',
  '     <label class="pt-up" id="ptmModoAnticipo"><input type="radio" name="ptmModo" value="anticipo" onchange="ptmRecalc()">',
  '      <div class="pt-up-txt"><div class="pt-up-t">Aparta con anticipo</div><div class="pt-up-d" id="ptmModoAnticipoTxt"></div></div><span class="pt-up-p" id="ptmModoAnticipoMonto"></span></label>',
  '    </div>',
  '    <div class="pt-resumen"><div id="ptmLineas" style="display:flex;flex-direction:column;gap:8px"></div>',
  '     <div class="pt-l pt-tot"><span>Total</span><span id="ptmResumenTotal"></span></div>',
  '     <div class="pt-ayuda" id="ptmEntregaTxt"></div></div>',
  '    <p class="pt-ayuda" id="ptmIntro" style="margin:0;font-size:13px"></p>',
  '   </section>',
  '  </div>',
  '  <footer class="pt-sheet-footer">',
  '   <div class="pt-total-line" id="ptmTotalLine"><span>Total con envío <span id="ptmEnvio"></span></span><span id="ptmTotal" aria-live="polite"></span></div>',
  '   <div class="pt-botones">',
  '    <button type="button" id="ptmPagar" class="pt-btn pt-btn-vino" onclick="pagarDetalles()" style="display:none">Pagar con Mercado Pago</button>',
  '   </div>',
  '   <button type="button" id="ptmWaBtn" onclick="pedirDetalles()"><span id="ptmWaTxt">Pedirla por WhatsApp</span></button>',
  '   <p id="ptmError" role="alert" aria-live="polite"></p>',
  '   <p id="ptmNota"></p>',
  '  </footer>',
  ' </div>',
  '</dialog>'].join('\n'));
  function lockPayment(locked){
    document.querySelectorAll('#ptModalBg input,#ptModalBg textarea,#ptModalBg button').forEach(function(el){el.disabled=locked;});
  }
  // --- Proyección del catálogo canónico de Picando Tabla para esta interfaz ---
  var CATALOGO=window.PICANDO_CATALOGO_V1;
  var PREMIUM=CATALOGO.modifiers.filter(function(m){return m.key==='premium';})[0];
  var REGALO=CATALOGO.promotions.filter(function(p){return p.id==='picandotabla:promotion:caja-tapas-regalo';})[0];
  // Extensión temporal: hasta extension.all_orders_until la caja va en cualquier tabla (pago/lib.php aplica lo mismo).
  var PROMO_EXT=(function(){ var e=REGALO&&REGALO.extension; if(!e||!e.all_orders_until) return false;
    var h=e.all_orders_until.split('-'); return new Date()<=new Date(+h[0],+h[1]-1,+h[2],23,59,59); })();
  var MENSAJERIA=CATALOGO.delivery.price_mxn;
  var LOGISTICA=CATALOGO.logistics;
  var TABLAS={};
  CATALOGO.products.forEach(function(product){
    var comp=product.composition_text||(product.composition?product.composition.cheeses+' quesos · '+product.composition.meats+' carnes':'');
    TABLAS[product.key]={
      id:product.id,
      n:product.title,
      v:product.price_mxn,
      regular:product.regular_price_mxn||null,
      prem:PREMIUM.prices_mxn_by_product_key[product.key],
      premOk:(PREMIUM.confirmed_product_keys||[]).indexOf(product.key)>-1,
      // Premium solo donde el catálogo lo ofrece (Tabla de Quesos ya es Premium; el almuerzo no lleva).
      premAvail:(product.modifier_ids||[]).indexOf(PREMIUM.id)>-1,
      pp:product.per_person||null,
      bundle:product.bundle||null,
      premText:product.premium_text||null,
      addons:product.addons!==false&&!product.bundle,
      people:product.presentation.people_text||'',
      comp:comp,
      img:'/'+product.media.image.replace(/^\//,''),
      tag:product.presentation.tag,
      badge:(product.package||product.bundle)?(product.package||product.bundle).card_label:(product.presentation.featured?'La favorita':product.presentation.tag),
      ev:product.is_event,
      d:product.description,
      inc:product.included,
      pkg:product.package||null,
      quote:product.quote||null,
      regalo:!!REGALO&&(PROMO_EXT||REGALO.eligible_product_keys.indexOf(product.key)>-1)
    };
  });
  var UPSELLS=CATALOGO.extras.filter(function(extra){return extra.status==='active';}).map(function(extra){
    return {id:extra.key,n:extra.title,v:extra.price_mxn,d:extra.description,c:extra.choice||null};
  });
  var fmt=function(v){ return '$'+v.toLocaleString('en-US'); };
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  // Extras con choice (mermelada o dip): opción elegida, o '' si falta.
  function opcionDe(u){ var r=document.querySelector('input[name="ptmCh_'+u.id+'"]:checked'); return r?r.value:''; }
  function marcado(u){ var cb=document.querySelector('input[data-up="'+u.id+'"]'); return !!(cb&&cb.checked); }
  function nombreExtra(u){ var op=u.c?opcionDe(u):''; return u.n+(op?': '+op:''); }
  var modalId=null, returnFocus=null;
  var drafts={};
  // --- Pago en línea (Mercado Pago): se enciende solo si el servidor tiene la llave (pago/estado.php) ---
  var PAGO_ONLINE=false, pagando=false;
  try{
    fetch('/pago/estado.php',{cache:'no-store'}).then(function(r){ return r.ok?r.json():{}; })
      .then(function(d){ PAGO_ONLINE=!!(d&&d.activo); if(modalId) ptmRecalc(); }).catch(function(){});
  }catch(e){}
  window.addEventListener('pageshow',function(){ pagando=false; lockPayment(false); var b=document.getElementById('ptmPagar'); if(b) b.disabled=false; if(modalId) ptmRecalc(); });
  // Fechas: mismas reglas que pago/lib.php (ptpg_cotizar). delivery_days_iso usa ISO: 5=viernes, 6=sábado.
  function isoLocal(d){ return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }
  function fechaDe(iso){ var p=String(iso||'').split('-'); return p.length===3?new Date(+p[0],+p[1]-1,+p[2]):null; }
  function diasHasta(iso){ var d=fechaDe(iso), h=new Date(); h.setHours(0,0,0,0); return d?Math.round((d-h)/864e5):NaN; }
  function diaIso(d){ return ((d.getDay()+6)%7)+1; }
  function minDias(t){ return t.ev?LOGISTICA.event_lead_time_days:Math.ceil(LOGISTICA.standard_lead_time_hours/24); }
  function primeraFecha(t){
    var d=new Date(); d.setHours(0,0,0,0); d.setDate(d.getDate()+minDias(t));
    for(var i=0;i<7&&LOGISTICA.delivery_days_iso.indexOf(diaIso(d))<0;i++) d.setDate(d.getDate()+1);
    return d;
  }
  function fechaLarga(iso){ var d=fechaDe(iso); return d&&!isNaN(d)?d.toLocaleDateString('es-MX',{weekday:'long',day:'numeric',month:'long'}):String(iso||''); }
  function fechaCorta(d){ return d.toLocaleDateString('es-MX',{weekday:'short',day:'numeric',month:'short'}).replace(/\./g,''); }
  function errorFecha(t, iso){
    var d=fechaDe(iso);
    if(!d||isNaN(d)||isoLocal(d)!==iso) return 'Elige el día de entrega.';
    if(diasHasta(iso)>120) return 'Elige una fecha dentro de los próximos 120 días.';
    if(LOGISTICA.delivery_days_iso.indexOf(diaIso(d))<0) return 'Ese día no entregamos. Elige '+LOGISTICA.delivery_days_labels.join(', ')+'.';
    if(diasHasta(iso)<minDias(t)) return t.ev?'Las tablas de evento se piden con '+LOGISTICA.event_lead_time_days+' días de anticipación.':'Pide con al menos '+minDias(t)+' días de anticipación.';
    return '';
  }
  // Lo que se paga hoy según lo que elige el cliente (mismas reglas que pago/lib.php).
  function modoElegido(){ var r=document.querySelector('input[name="ptmModo"]:checked'); return r&&r.value==='anticipo'?'anticipo':'completo'; }
  function cobroDe(total){
    if(modoElegido()==='anticipo') return {tipo:'anticipo', monto:Math.round(total*LOGISTICA.payment.deposit.pct/100)};
    return {tipo:'total', monto:total};
  }
  function restoTexto(){
    var dd=LOGISTICA.payment.deposit.balance_due_days_before_delivery;
    return dd>0?'a más tardar '+dd+(dd===1?' día':' días')+' antes de la entrega':'al recibir tu tabla';
  }
  // Productos por persona: el precio de la tabla es unitario × personas (pago/lib.php hace lo mismo).
  function personas(){ var n=parseInt(document.getElementById('ptmPersonas').value,10); return isNaN(n)?0:n; }
  function base(t){ return t.pp?t.v*Math.max(personas(),0):t.v; }
  function totalDe(t){
    var prem=document.getElementById('ptmPrem').checked, total=base(t)+MENSAJERIA+(prem?t.prem:0);
    UPSELLS.forEach(function(u){ var cb=document.querySelector('input[data-up="'+u.id+'"]'); if(cb&&cb.checked) total+=u.v; });
    return total;
  }
  // Lee el formulario. Devuelve los datos validados o null; con silencioso=true no marca el error
  // (sirve para saber si el pedido ya está completo sin molestar al cliente).
  function leerDatos(t, silencioso){
    var v=function(id){ return document.getElementById(id).value.trim(); };
    var d={prem:document.getElementById('ptmPrem').checked, personas:personas(), nombre:v('ptmCliente'), whatsapp:v('ptmWhatsapp'),
           zona:v('ptmZona'), fecha:v('ptmFecha'), notas:v('ptmNotas')};
    // Mismo orden que la hoja: tabla → complementos → entrega → fecha.
    var checks=[
      {bad:!!t.pp&&d.personas<t.pp.min_people,id:'ptmPersonas',message:t.pp?'El '+t.n+' se pide desde '+t.pp.min_people+' personas.':''}
    ];
    UPSELLS.forEach(function(u){
      if(u.c&&marcado(u)) checks.push({bad:!opcionDe(u),id:'ptmCh_'+u.id+'_0',
        message:'Elige si quieres '+u.c.options.join(' o ').toLowerCase()+' en '+u.n+'.'});
    });
    checks.push(
      {bad:!d.nombre,id:'ptmCliente',message:'Escribe tu nombre para continuar.'},
      {bad:d.whatsapp.replace(/\D/g,'').length<10||d.whatsapp.replace(/\D/g,'').length>15,id:'ptmWhatsapp',message:'Escribe tu WhatsApp (10 dígitos) para continuar.'},
      {bad:!d.zona,id:'ptmZona',message:'Escribe tu colonia para confirmar la entrega.'},
      {bad:!!errorFecha(t,d.fecha),id:'ptmFecha',message:errorFecha(t,d.fecha)}
    );
    var missing=checks.filter(function(c){ return c.bad; })[0];
    var error=document.getElementById('ptmError');
    if(missing){
      if(silencioso) return null;
      error.textContent=missing.message;
      error.style.display='block';
      var el=document.getElementById(missing.id);
      // La fecha se elige en la tira de días: llevamos la vista ahí, no al input oculto del calendario.
      var target=missing.id==='ptmFecha'?document.getElementById('ptmBloquePago'):el;
      if(target&&target.scrollIntoView) target.scrollIntoView({block:'start',behavior:'smooth'});
      if(missing.id!=='ptmFecha') el.focus({preventScroll:true});
      return null;
    }
    error.style.display='none';
    return d;
  }
  function pintarDias(t){
    var cont=document.getElementById('ptmDias'), d=primeraFecha(t), html=[];
    for(var i=0;html.length<7&&i<30;i++){
      if(LOGISTICA.delivery_days_iso.indexOf(diaIso(d))>-1){
        var iso=isoLocal(d);
        html.push('<button type="button" class="pt-dia" data-fecha="'+iso+'" aria-label="'+esc(fechaLarga(iso))+'">'+
          '<small>'+esc(d.toLocaleDateString('es-MX',{weekday:'short'}).replace(/\./g,''))+'</small><b>'+d.getDate()+'</b></button>');
      }
      d.setDate(d.getDate()+1);
    }
    cont.innerHTML=html.join('');
  }
  document.addEventListener('click',function(e){
    var b=e.target.closest&&e.target.closest('#ptmDias .pt-dia'); if(!b) return;
    document.getElementById('ptmFecha').value=b.dataset.fecha; ptmRecalc();
  });
  // Abre la hoja de pedido. preset: {premium, paso} — paso 0 (tocar la tarjeta) deja «Qué incluye» abierto;
  // «Elegir y pedir» lo deja plegado para que los complementos queden a la vista.
  window.verDetalles=function(id,preset){
    var t=TABLAS[id]; if(!t) return;
    if(t.quote){ track('select_quote',{tabla:id}); window.location.href=t.quote.url; return; }
    if(pagando) return;
    rememberDraft();
    modalId=id;
    returnFocus=document.activeElement;
    var draft=drafts[id];
    var meta=[t.people,t.comp].filter(Boolean).join(' · ');
    document.getElementById('ptmMiniImg').src=t.img;
    document.getElementById('ptmMiniImg').alt=t.n;
    document.getElementById('ptmMiniMeta').textContent=meta;
    document.getElementById('ptmNombre').textContent=t.n;
    document.getElementById('ptmMiniNombre').textContent=t.badge||t.tag||'';
    document.getElementById('ptmPrecioNota').textContent=t.pp?'por persona · + envío '+fmt(MENSAJERIA):'+ envío '+fmt(MENSAJERIA);
    document.getElementById('ptmDesc').textContent=t.d;
    document.getElementById('ptmInc').innerHTML=t.inc.map(function(i){return '<li>'+esc(i)+'</li>';}).join('')+
      (t.regalo?'<li><strong style="color:#7a2434">'+esc(REGALO.title)+' incluida sin costo</strong></li>':'');
    document.getElementById('ptmPronta').innerHTML='<div><strong>Entrega más pronta: '+esc(fechaLarga(isoLocal(primeraFecha(t))))+'</strong><br>'+
      (LOGISTICA.delivery_days_iso.length===7?'Entregamos todos los días':'Entregamos '+esc(LOGISTICA.delivery_days_labels.join(', ')))+
      ', con '+minDias(t)+' días de anticipación'+(t.ev?' (tabla de evento)':'')+'.</div>';
    document.getElementById('ptmDetalle').open=!!(preset&&preset.paso===0);
    document.getElementById('ptmPrem').checked=!!(t.premAvail&&draft&&draft.premium);
    document.getElementById('ptmPremBox').style.display=t.premAvail?'':'none';
    // Mejora con texto propio (Tabla de Quesos: quesos raros); las demás usan el Premium genérico.
    var premTit=document.getElementById('ptmPremTitulo'), premDesc=document.getElementById('ptmPremDesc');
    if(!premDesc.dataset.base) premDesc.dataset.base=premDesc.textContent;
    premTit.textContent=t.premText?t.premText.title:'Hazla Premium';
    premDesc.textContent=t.premText?t.premText.description:premDesc.dataset.base;
    // Sin complementos: paquete cerrado (Celebración) o producto que ya incluye todo (Tabla de Quesos).
    document.getElementById('ptmUpsBox').style.display=t.addons?'':'none';
    document.getElementById('ptmBloqueUps').style.display=(t.premAvail||t.addons||t.bundle)?'':'none';
    document.getElementById('ptmToque').textContent=t.bundle
      ?'Paquete cerrado: ya incluye '+t.bundle.card_items.join(', ').toLowerCase()+'. Si necesitas un ajuste, escríbelo más abajo.'
      :(t.premAvail||t.addons?'Tu tabla ya incluye '+(t.comp||'lo de la tarjeta')+'. Marca lo que quieras sumar:':'');
    document.getElementById('ptmPersonasBox').style.display=t.pp?'block':'none';
    if(t.pp){
      var pin=document.getElementById('ptmPersonas');
      pin.min=t.pp.min_people; pin.value=(draft&&draft.personas)||t.pp.min_people;
      document.getElementById('ptmPersonasNota').textContent=fmt(t.v)+' por persona · desde '+t.pp.min_people+' personas.';
    }
    document.getElementById('ptmEnvio').textContent='('+fmt(MENSAJERIA)+')';
    document.getElementById('ptmPremBox').classList.remove('on');
    if(t.premAvail) document.getElementById('ptmPremPrecio').textContent='+'+fmt(t.prem)+(t.premOk?'':' *');
    document.getElementById('ptmUps').innerHTML=UPSELLS.map(function(u){
      return '<label class="pt-up" id="ptmUp_'+u.id+'"><input type="checkbox" data-up="'+u.id+'" onchange="ptmRecalc()">'+
        '<div class="pt-up-txt"><div class="pt-up-t">'+esc(u.n)+'</div><div class="pt-up-d">'+esc(u.d)+'</div></div><span class="pt-up-p">+'+fmt(u.v)+'</span></label>'+
        (u.c?'<div id="ptmCh_'+u.id+'" class="pt-elige" role="radiogroup" aria-label="'+esc(u.c.label)+'">'+
          '<span>'+esc(u.c.label)+'</span>'+
          u.c.options.map(function(o,i){ return '<label><input type="radio" id="ptmCh_'+u.id+'_'+i+'" name="ptmCh_'+u.id+'" value="'+esc(o)+'" onchange="ptmRecalc()"> '+esc(o)+'</label>'; }).join('')+
          '</div>':'');
    }).join('');
    ['ptmCliente','ptmWhatsapp','ptmZona','ptmFecha','ptmNotas'].forEach(function(field){ document.getElementById(field).value=(draft&&draft.fields[field])||''; });
    document.getElementById('ptmError').style.display='none';
    var fechaIn=document.getElementById('ptmFecha'), tope=new Date();
    tope.setDate(tope.getDate()+120);
    fechaIn.min=isoLocal(primeraFecha(t));
    fechaIn.max=isoLocal(tope);
    pintarDias(t);
    var todos=LOGISTICA.delivery_days_iso.length===7;
    document.getElementById('ptmRegla').innerHTML=esc((todos?'Entregamos todos los días':'Entregamos '+LOGISTICA.delivery_days_labels.join(', '))
      +', con al menos '+minDias(t)+' días de anticipación'+(t.ev?' (tabla de evento).':'.'))+' La más pronta es <strong style="color:#26282a">'+esc(fechaLarga(isoLocal(primeraFecha(t))))+'</strong>.';
    var completo=document.querySelector('input[name="ptmModo"][value="completo"]'); if(completo) completo.checked=true;
    if(draft){
      UPSELLS.forEach(function(u){
        document.querySelector('input[data-up="'+u.id+'"]').checked=draft.extras.indexOf(u.id)>-1;
        var r=u.c&&draft.opciones[u.id]&&document.querySelector('input[name="ptmCh_'+u.id+'"][value="'+draft.opciones[u.id]+'"]'); if(r) r.checked=true;
      });
      document.querySelector('input[name="ptmModo"][value="'+draft.mode+'"]').checked=true;
    }
    if(!t.addons) UPSELLS.forEach(function(u){ document.querySelector('input[data-up="'+u.id+'"]').checked=false; });
    if(!draft&&t.pkg){
      document.getElementById('ptmPrem').checked=!!t.pkg.premium;
      UPSELLS.forEach(function(u){ if(t.pkg.extra_keys.indexOf(u.id)>-1) document.querySelector('input[data-up="'+u.id+'"]').checked=true; });
    }
    if(preset&&preset.premium&&t.premAvail) document.getElementById('ptmPrem').checked=true;
    var dialog=document.getElementById('ptModalBg');
    if(!dialog.open) dialog.showModal();
    document.body.classList.add('pt-order-open');
    document.querySelector('.pt-sheet-body').scrollTop=0;
    ptmRecalc();
    document.getElementById('ptmClose').focus();
    track('view_product',{item_name:t.n, value:t.v, currency:'MXN'});
    track('view_item',{item_name:t.n, value:t.v, currency:'MXN'});
    if(!(preset&&preset.paso===0)) track('select_item',{item_name:t.n, value:t.v, currency:'MXN'});
    try{ if(window.fbq) fbq('track','ViewContent',{content_name:t.n, value:t.v, currency:'MXN'}); }catch(e){}
  };
  function rememberDraft(){
    if(!modalId) return;
    var fields={};
    ['ptmCliente','ptmWhatsapp','ptmZona','ptmFecha','ptmNotas'].forEach(function(id){ fields[id]=document.getElementById(id).value; });
    drafts[modalId]={fields:fields,premium:document.getElementById('ptmPrem').checked,personas:personas(),
      extras:UPSELLS.filter(function(u){return document.querySelector('input[data-up="'+u.id+'"]').checked;}).map(function(u){return u.id;}),
      opciones:UPSELLS.reduce(function(o,u){ if(u.c&&opcionDe(u)) o[u.id]=opcionDe(u); return o; },{}),mode:modoElegido()};
  }
  window.cerrarDetalles=function(){
    if(pagando) return;
    rememberDraft();
    document.getElementById('ptModalBg').close();
    document.body.classList.remove('pt-order-open');
    modalId=null;
    if(returnFocus&&returnFocus.isConnected) returnFocus.focus({preventScroll:true});
  };
  window.ptmRecalc=function(){
    var t=TABLAS[modalId]; if(!t) return;
    var total=base(t)+MENSAJERIA;
    var prem=document.getElementById('ptmPrem');
    document.getElementById('ptmPremBox').classList.toggle('on', prem.checked);
    if(prem.checked) total+=t.prem;
    var precioTabla=t.pp?fmt(t.v)+' por persona':fmt(t.v+(prem.checked?t.prem:0));
    document.getElementById('ptmMiniPrecio').textContent=precioTabla;
    var lineas=[[t.n+(t.pp?' · '+personas()+' personas':'')+(prem.checked?' · '+(t.premText?t.premText.line:'Premium'):''), base(t)+(prem.checked?t.prem:0)]];
    UPSELLS.forEach(function(u){
      var cb=document.querySelector('input[data-up="'+u.id+'"]');
      document.getElementById('ptmUp_'+u.id).classList.toggle('on', !!(cb&&cb.checked));
      if(u.c) document.getElementById('ptmCh_'+u.id).style.display=cb&&cb.checked?'flex':'none';
      if(cb&&cb.checked){ total+=u.v; lineas.push([nombreExtra(u),u.v]); }
    });
    lineas.push(['Mensajería CDMX',MENSAJERIA]);
    document.getElementById('ptmLineas').innerHTML=lineas.map(function(l){ return '<div class="pt-l"><span>'+esc(l[0])+'</span><span>'+fmt(l[1])+'</span></div>'; }).join('');
    document.getElementById('ptmTotal').textContent=fmt(total);
    document.getElementById('ptmResumenTotal').textContent=fmt(total);
    var fecha=document.getElementById('ptmFecha').value;
    document.querySelectorAll('#ptmDias .pt-dia').forEach(function(b){ var on=b.dataset.fecha===fecha; b.classList.toggle('on',on); b.setAttribute('aria-pressed',on?'true':'false'); });
    var fechaOk=!!fecha&&!errorFecha(t,fecha);
    document.getElementById('ptmEntregaTxt').textContent=fechaOk?'Entrega el '+fechaLarga(fecha)+'.':'Elige arriba el día de entrega.';
    // Pago en línea: botón principal si el servidor lo tiene encendido y el precio está confirmado.
    var cobro=cobroDe(total);
    var premPend=prem.checked&&!t.premOk;
    var online=PAGO_ONLINE&&!premPend;
    var pagar=document.getElementById('ptmPagar'), waBtn=document.getElementById('ptmWaBtn');
    pagar.style.display=online?'inline-flex':'none';
    if(!pagando) pagar.textContent='Pagar '+fmt(cobro.monto)+' →';
    var PAGO=LOGISTICA.payment, pct=PAGO.deposit.pct, anticipo=Math.round(total*pct/100);
    document.getElementById('ptmModo').style.display=online?'flex':'none';
    document.getElementById('ptmModoCompleto').classList.toggle('on', cobro.tipo==='total');
    document.getElementById('ptmModoAnticipo').classList.toggle('on', cobro.tipo==='anticipo');
    document.getElementById('ptmModoCompletoMonto').textContent=fmt(total);
    document.getElementById('ptmModoCompletoTxt').textContent='Tarjeta, OXXO o transferencia. Con tarjeta de crédito, hasta '+PAGO.full.installments_max+' meses (con intereses).';
    document.getElementById('ptmModoAnticipoMonto').textContent=fmt(anticipo);
    document.getElementById('ptmModoAnticipoTxt').textContent='Pagas el '+pct+' % hoy y el resto, '+fmt(total-anticipo)+', '+restoTexto()+'.';
    // WhatsApp: principal si no hay pago en línea; si lo hay, queda como alternativa (pedido completo o dudas).
    waBtn.className=online?'':'pt-btn';
    var fila=document.querySelector('#ptModalBg .pt-botones');
    if(!online){ if(waBtn.parentNode!==fila) fila.appendChild(waBtn); }
    else if(waBtn.parentNode===fila) fila.parentNode.insertBefore(waBtn,fila.nextSibling);
    document.getElementById('ptmWaTxt').textContent=online?'¿Dudas o prefieres WhatsApp? Escríbenos':'Pedirla por WhatsApp';
    document.getElementById('ptmIntro').textContent=online
      ?'Pagas en línea con Mercado Pago: tarjeta, OXXO o transferencia. Te escribimos por WhatsApp para confirmar tu entrega.'
      :(PAGO_ONLINE&&premPend
        ?'La versión Premium de esta tabla se confirma por WhatsApp: te abrimos el chat con tu pedido listo.'
        :'Al pedir abrimos WhatsApp con la tabla y el total listos para confirmar.');
    document.getElementById('ptmNota').textContent=online
      ?'Pagas en Mercado Pago. Si no tenemos tu fecha, te devolvemos lo que hayas pagado.'
      :'Pago completo o anticipo del '+pct+' % al confirmar tu pedido.';
  };
  window.pagarDetalles=function(){
    var t=TABLAS[modalId]; if(!t||pagando||!PAGO_ONLINE||(document.getElementById('ptmPrem').checked&&!t.premOk)) return;
    var d=leerDatos(t); if(!d) return;
    var btn=document.getElementById('ptmPagar'), error=document.getElementById('ptmError');
    var cobro=cobroDe(totalDe(t));
    var extras=UPSELLS.filter(function(u){ var cb=document.querySelector('input[data-up="'+u.id+'"]'); return cb&&cb.checked; })
      .map(function(u){ return u.id; });
    var opciones={};
    UPSELLS.forEach(function(u){ if(u.c&&marcado(u)) opciones[u.id]=opcionDe(u); });
    var utm={};
    try{ new URLSearchParams(window.location.search).forEach(function(v,k){ if(/^(utm_\w+|fbclid|gclid)$/.test(k)) utm[k]=v; }); }catch(e){}
    pagando=true; lockPayment(true); btn.disabled=true; btn.textContent='Abriendo Mercado Pago…';
    var paymentController=new AbortController();
    var paymentTimeout=setTimeout(function(){paymentController.abort();},20000);
    track('begin_checkout',{origen:'pago_en_linea', tabla:modalId, value:cobro.monto, currency:'MXN'});
    try{ if(window.fbq) fbq('track','InitiateCheckout',{content_name:t.n, value:cobro.monto, currency:'MXN'}); }catch(e){}
    fetch('/pago/pedido.php',{signal:paymentController.signal,method:'POST', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({tabla:modalId, premium:d.prem, personas:d.personas, extras:extras, nombre:d.nombre, whatsapp:d.whatsapp,
                           zona:d.zona, fecha:d.fecha, notas:d.notas, modo:modoElegido(), opciones:opciones, utm:utm})})
      .then(function(r){ return r.json().catch(function(){ return {}; }); })
      .then(function(res){
        clearTimeout(paymentTimeout);
        if(res&&res.ok&&res.url){ window.location.href=res.url; return; }
        pagando=false; lockPayment(false); btn.disabled=false; ptmRecalc();
        error.textContent=((res&&res.error)||'No pudimos abrir Mercado Pago.')+(res&&res.error&&/WhatsApp/.test(res.error)?'':' También puedes pedirla por WhatsApp.');
        error.style.display='block';
      })
      .catch(function(){
        clearTimeout(paymentTimeout);
        pagando=false; lockPayment(false); btn.disabled=false; ptmRecalc();
        error.textContent='No pudimos abrir Mercado Pago. Revisa tu conexión o pídela por WhatsApp.';
        error.style.display='block';
      });
  };
  window.pedirDetalles=function(){
    var t=TABLAS[modalId]; if(!t||pagando) return;
    var prem=document.getElementById('ptmPrem').checked;
    var online=PAGO_ONLINE&&!(prem&&!t.premOk);
    // Con pago en línea, WhatsApp es la alternativa: si el pedido ya está completo lo mandamos armado;
    // si no, abrimos el chat para dudas sin pedirle nada al cliente.
    var d=online?leerDatos(t,true):leerDatos(t);
    if(!d){
      if(!online) return;
      open('¡Hola! Tengo una duda sobre '+(t.pp?'el ':'la ')+t.n+'.');
      track('whatsapp_click',{origen:'detalles_duda', tabla:modalId});
      try{ if(window.fbq) fbq('track','Contact',{content_name:t.n}); }catch(e){}
      return;
    }
    var nombre=d.nombre, whatsapp=d.whatsapp, zona=d.zona, fecha=fechaLarga(d.fecha), notas=d.notas;
    var total=base(t)+(prem?t.prem:0);
    var premLinea=t.premText?t.premText.line:'Premium';
    var nombreTabla=t.n+(t.pp?' · '+d.personas+' personas':'')+(prem?' ('+premLinea+')':'');
    var items=[
      {qty:1,nombre:nombreTabla,precio:total}
    ];
    var lines=['¡Hola! Quiero '+(t.pp?'el ':'la ')+t.n+(t.pp?' para '+d.personas+' personas':'')+(prem?' en versión '+premLinea:'')+' ('+fmt(total)+')'];
    if(t.regalo){ items.push({qty:1,nombre:REGALO.title+' (incluida)',precio:0}); lines.push('+ '+REGALO.title+' — REGALO'); }
    UPSELLS.forEach(function(u){
      var cb=document.querySelector('input[data-up="'+u.id+'"]');
      if(cb&&cb.checked){ total+=u.v; items.push({qty:1,nombre:nombreExtra(u),precio:u.v}); lines.push('+ '+nombreExtra(u)+' ('+fmt(u.v)+')'); }
    });
    total+=MENSAJERIA;
    items.push({qty:1,nombre:'Mensajería CDMX',precio:MENSAJERIA});
    lines.push('+ Mensajería CDMX ('+fmt(MENSAJERIA)+')');
    lines.push('Nombre: '+nombre);
    lines.push('Mi WhatsApp: '+whatsapp);
    lines.push('Colonia: '+zona);
    lines.push('Entrega: '+fecha);
    if(notas) lines.push('Notas: '+notas);
    lines.push('Total: '+fmt(total));
    try{
      var pl=JSON.stringify({nombre:nombre, whatsapp:whatsapp, zona:zona, fecha:fecha, notas:notas, origen:'landing_popup_producto', total:total, items:items});
      if(navigator.sendBeacon){ navigator.sendBeacon('/order.php', new Blob([pl],{type:'application/json'})); }
    }catch(e){}
    track('begin_checkout',{origen:'detalles', tabla:modalId, value:total, currency:'MXN'});
    open(lines.join('\n'));
    track('click_whatsapp',{origen:'detalles', tabla:modalId, premium:prem, value:total, currency:'MXN'});
    track('whatsapp_click',{origen:'detalles', tabla:modalId, premium:prem, value:total, currency:'MXN'});
    try{ if(window.fbq) fbq('track','Lead',{content_name:t.n, value:total, currency:'MXN'}); }catch(e){}
    cerrarDetalles();
  };

  document.getElementById('ptModalBg').addEventListener('cancel',function(e){e.preventDefault();cerrarDetalles();});
  // En cuanto el cliente corrige algo, el aviso de «falta X» desaparece.
  document.getElementById('ptModalBg').addEventListener('input',function(){ document.getElementById('ptmError').style.display='none'; });
  function productLink(el){
    if(el.dataset.ptBuy) return {key:el.dataset.ptBuy,paso:1};
    if(el.tagName!=='A') return null;
    var url=new URL(el.href,location.href);
    if(url.origin!==location.origin) return null;
    var key=url.searchParams.get('tabla');
    if(key&&TABLAS[key]) return {key:key,premium:url.searchParams.get('premium')==='1',paso:1};
    var match=url.pathname.match(/^\/tablas\/(para-dos|anfitriona|fiesta|celebracion)\/$/);
    if(match&&!el.closest('.crumbs,.nav-links,footer,.site-header')) return {key:match[1]==='para-dos'?'dos':match[1],premium:url.hash==='#premium',paso:1};
    return null;
  }
  document.querySelectorAll('a[href],button[data-pt-buy]').forEach(function(el){
    var product=productLink(el); if(!product) return;
    el.setAttribute('aria-haspopup','dialog'); el.setAttribute('aria-controls','ptModalBg');
    if(el.tagName==='A'&&!el.querySelector('img')&&/Pagar|Comprar|Elegir|Pedir|Ver detalles/i.test(el.textContent)) el.textContent='Elegir y pedir';
  });
  document.addEventListener('click',function(event){
    if(event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey) return;
    var trigger=event.target.closest('[data-pt-buy],a[href]');
    if(!trigger){
      // Tocar la tarjeta (fuera de sus botones) abre el pedido con «Qué incluye» desplegado.
      var card=event.target.closest('.pt-product-card[data-pt-product]');
      if(card&&TABLAS[card.dataset.ptProduct]&&!TABLAS[card.dataset.ptProduct].quote){ verDetalles(card.dataset.ptProduct,{paso:0}); }
      return;
    }
    var product=productLink(trigger); if(!product) return;
    event.preventDefault(); event.stopImmediatePropagation();
    verDetalles(product.key,product);
  },true);
  // «Entrega más pronta» en cada tarjeta, con las mismas reglas del pedido.
  document.querySelectorAll('.pt-product-card[data-pt-product] [data-pt-lead]').forEach(function(el){
    var t=TABLAS[el.closest('[data-pt-product]').dataset.ptProduct]; if(!t) return;
    el.innerHTML='Entrega más pronta: <strong>'+esc(fechaCorta(primeraFecha(t)))+'</strong>';
  });
  var requested=new URLSearchParams(window.location.search).get('tabla');
  if(requested&&TABLAS[requested]) verDetalles(requested,{premium:new URLSearchParams(window.location.search).get('premium')==='1'});
})();
