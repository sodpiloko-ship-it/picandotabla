/* Componente único de compra: todas las páginas comparten este panel y el catálogo. */
(function(){
  'use strict';
  if(!window.PICANDO_CATALOGO_V1) return;
  var WA='525623632404';
  function open(msg){ window.open('https://wa.me/'+WA+'?text='+encodeURIComponent(msg),'_blank','noopener'); }
  function track(ev,params){try{if(window.gtag) window.gtag('event',ev,params||{});}catch(e){}}
  document.body.insertAdjacentHTML('beforeend', "  <!-- Modal de detalles de producto -->\n  <dialog id=\"ptModalBg\" aria-labelledby=\"ptmNombre\" onclick=\"if(event.target===this)cerrarDetalles()\">\n    <div id=\"ptModal\"><header class=\"pt-sheet-header\"><span>Tu tabla, a tu gusto</span><button type=\"button\" id=\"ptmClose\" onclick=\"cerrarDetalles()\" aria-label=\"Cerrar pedido\">×</button></header>\n      <div id=\"ptmImg\" style=\"height:190px;background:#d3d5d4 center/cover no-repeat;border-radius:16px 16px 0 0;position:relative\">\n        \n      </div>\n      <div class=\"pt-sheet-body\">\n        <div id=\"ptmTag\" style=\"font-size:11px;letter-spacing:1.5px;color:#7c2d3e;font-weight:600;text-transform:uppercase;margin-bottom:6px\"></div>\n        <div style=\"display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:10px\">\n          <h2 id=\"ptmNombre\" style=\"font-family:Lora,serif;font-size:24px;font-weight:600;color:#26282a\"></h2>\n          <div id=\"ptmPrecio\" style=\"font-family:Lora,serif;font-size:22px;color:#26282a;flex:none\"></div>\n        </div>\n<details class=\"pt-includes\"><summary>Qué incluye tu tabla</summary>        <p id=\"ptmDesc\" style=\"font-size:14.5px;line-height:1.6;color:#55585a;margin:0 0 14px\"></p>\n        <div style=\"font-size:12px;letter-spacing:1.5px;color:#75797a;font-weight:600;text-transform:uppercase;margin-bottom:8px\">¿Qué lleva?</div>\n        <ul id=\"ptmInc\" style=\"margin:0 0 18px;padding:0 0 0 18px;font-size:13.5px;line-height:1.7;color:#55585a\"></ul>\n\n</details><div id=\"ptmPersonasBox\" style=\"display:none;margin:0 0 16px\"><label class=\"q-lbl\" for=\"ptmPersonas\">¿Para cuántas personas?</label><input id=\"ptmPersonas\" class=\"q-in\" type=\"number\" inputmode=\"numeric\" step=\"1\" oninput=\"ptmRecalc()\" onchange=\"ptmRecalc()\"><div id=\"ptmPersonasNota\" style=\"font-size:12px;color:#75797a;margin-top:6px\"></div></div><h3 class=\"pt-step\" id=\"ptmToque\">1. Dale tu toque <small>Opcional</small></h3>        <label class=\"pt-up\" id=\"ptmPremBox\" style=\"margin-bottom:16px;background:#fdf9f4;border-color:#c9a44a\">\n          <input type=\"checkbox\" id=\"ptmPrem\" onchange=\"ptmRecalc()\">\n          <div>\n            <div style=\"font-weight:600;font-size:14.5px;color:#26282a\"><span id=\"ptmPremTitulo\">Hazla Premium</span> <span id=\"ptmPremPrecio\" style=\"color:#7c2d3e\"></span> <span style=\"background:#c9a44a;color:#fff;font-size:10px;font-weight:700;letter-spacing:1px;padding:2px 8px;border-radius:999px;text-transform:uppercase;vertical-align:2px\">Premium</span></div>\n            <div id=\"ptmPremDesc\" style=\"font-size:13px;color:#75797a;line-height:1.5\">La misma cantidad con una selección de mayor categoría (por ejemplo, quesos importados o charcutería de reserva, según disponibilidad). No es una tabla más grande.</div>\n          </div>\n        </label>\n\n        <div id=\"ptmUpsBox\"><div style=\"font-size:12px;letter-spacing:1.5px;color:#75797a;font-weight:600;text-transform:uppercase;margin-bottom:8px\">Complétala <span style=\"letter-spacing:0;text-transform:none;font-weight:500\">· si quieres algo extra</span></div>\n        <div id=\"ptmUps\" style=\"display:flex;flex-direction:column;gap:10px;margin-bottom:18px\"></div></div>\n\n        <div style=\"font-size:12px;letter-spacing:1.5px;color:#75797a;font-weight:600;text-transform:uppercase;margin:2px 0 10px\">2. Entrega y contacto</div>\n        <p id=\"ptmIntro\" style=\"font-size:13px;line-height:1.5;color:#75797a;margin:0 0 14px\">Déjanos tus datos y abriremos WhatsApp con la tabla y el total listos para confirmar.</p>\n        <div class=\"ptm-intake-grid\" style=\"display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px\">\n          <div>\n            <label class=\"q-lbl\" for=\"ptmCliente\">Nombre</label>\n            <input id=\"ptmCliente\" class=\"q-in\" autocomplete=\"name\" maxlength=\"120\" placeholder=\"Tu nombre\">\n          </div>\n          <div>\n            <label class=\"q-lbl\" for=\"ptmWhatsapp\">Tu WhatsApp</label>\n            <input id=\"ptmWhatsapp\" class=\"q-in\" type=\"tel\" inputmode=\"tel\" autocomplete=\"tel\" maxlength=\"25\" placeholder=\"55 0000 0000\">\n          </div>\n        </div>\n        <div class=\"ptm-intake-grid\" style=\"display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px\">\n          <div>\n            <label class=\"q-lbl\" for=\"ptmZona\">Colonia o municipio</label>\n            <input id=\"ptmZona\" class=\"q-in\" autocomplete=\"address-level3\" maxlength=\"160\" placeholder=\"Ej. Del Valle\">\n          </div>\n          <div>\n            <label class=\"q-lbl\" for=\"ptmFecha\">¿Qué día la entregamos?</label>\n            <input id=\"ptmFecha\" class=\"q-in\" type=\"date\" onchange=\"ptmRecalc()\" aria-describedby=\"ptmRegla\">\n          </div>\n        </div>\n        <div style=\"margin-bottom:14px\">\n          <label class=\"q-lbl\" for=\"ptmNotas\">Notas <span style=\"font-weight:400;color:#9a9ea0\">(opcional)</span></label>\n          <textarea id=\"ptmNotas\" class=\"q-in\" rows=\"2\" maxlength=\"600\" placeholder=\"Ocasión, restricciones o sabores que prefieres\" style=\"resize:vertical\"></textarea>\n        </div>\n        <div style=\"margin-bottom:14px\">\n          <div id=\"ptmRegla\" style=\"font-size:12px;color:#75797a;margin-top:6px;line-height:1.5\"></div>\n        </div>\n        <div style=\"display:flex;align-items:center;justify-content:space-between;border-top:1px solid #d3d5d4;padding-top:12px;margin-bottom:4px\">\n          <span style=\"font-size:13px;color:#75797a\">Mensajería a toda la CDMX y la zona oriente</span>\n          <span style=\"font-family:Lora,serif;font-size:15px;color:#55585a\" id=\"ptmEnvio\"></span>\n        </div>\n        <div id=\"ptmModo\" role=\"radiogroup\" aria-label=\"¿Cómo quieres pagar?\" style=\"display:none;flex-direction:column;gap:10px;margin:0 0 16px\">\n          <div style=\"font-size:12px;letter-spacing:1.5px;color:#75797a;font-weight:600;text-transform:uppercase\">¿Cómo quieres pagar?</div>\n          <label class=\"pt-up on\" id=\"ptmModoCompleto\"><input type=\"radio\" name=\"ptmModo\" value=\"completo\" checked onchange=\"ptmRecalc()\">\n            <div><div style=\"font-weight:600;font-size:14.5px;color:#26282a\">Pago completo <span id=\"ptmModoCompletoMonto\" style=\"color:#7c2d3e\"></span></div>\n            <div id=\"ptmModoCompletoTxt\" style=\"font-size:13px;color:#75797a;line-height:1.5\"></div></div></label>\n          <label class=\"pt-up\" id=\"ptmModoAnticipo\"><input type=\"radio\" name=\"ptmModo\" value=\"anticipo\" onchange=\"ptmRecalc()\">\n            <div><div style=\"font-weight:600;font-size:14.5px;color:#26282a\">Anticipo <span id=\"ptmModoAnticipoMonto\" style=\"color:#7c2d3e\"></span></div>\n            <div id=\"ptmModoAnticipoTxt\" style=\"font-size:13px;color:#75797a;line-height:1.5\"></div></div></label>\n        </div>\n</div><footer class=\"pt-sheet-footer\">        <div class=\"pt-total-line\" style=\"display:flex;align-items:center;justify-content:space-between;margin-bottom:14px\">\n          <span style=\"font-size:14px;color:#55585a;font-weight:600\">Total con envío</span>\n          <span id=\"ptmTotal\" aria-live=\"polite\" style=\"font-family:Lora,serif;font-size:24px;color:#26282a\"></span>\n        </div>\n        <button id=\"ptmPagar\" class=\"ptbtn\" onclick=\"pagarDetalles()\" style=\"display:none;width:100%;align-items:center;justify-content:center;gap:10px;background:#26282a;color:#fff;padding:15px;border-radius:999px;font-weight:600;font-size:16px;border:none;cursor:pointer;margin-bottom:10px\">Pagar con Mercado Pago</button>\n        <button id=\"ptmWaBtn\" class=\"ptbtn\" onclick=\"pedirDetalles()\" style=\"width:100%;display:inline-flex;align-items:center;justify-content:center;gap:10px;background:#26282a;color:#fff;padding:15px;border-radius:999px;font-weight:600;font-size:16px;border:none;cursor:pointer\">\n          <span style=\"width:18px;height:18px;border-radius:50%;background:#25D366;display:inline-block\"></span><span id=\"ptmWaTxt\">Pedirla por WhatsApp</span></button>\n        <p id=\"ptmError\" role=\"alert\" aria-live=\"polite\" style=\"display:none;text-align:center;font-size:12.5px;color:#7c2d3e;font-weight:600;margin:10px 0 0\"></p>\n        <p id=\"ptmNota\" style=\"text-align:center;font-size:12px;color:#75797a;margin:12px 0 0;line-height:1.5\"></p>\n      </footer>\n    </div>\n  </dialog>");
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
      img:'/'+product.media.image.replace(/^\//,''),
      tag:product.presentation.tag,
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
  // Datos del formulario ya validados, o null (y muestra el error).
  function leerDatos(t){
    var v=function(id){ return document.getElementById(id).value.trim(); };
    var d={prem:document.getElementById('ptmPrem').checked, personas:personas(), nombre:v('ptmCliente'), whatsapp:v('ptmWhatsapp'),
           zona:v('ptmZona'), fecha:v('ptmFecha'), notas:v('ptmNotas')};
    var checks=[
      {bad:!!t.pp&&d.personas<t.pp.min_people,id:'ptmPersonas',message:t.pp?'El '+t.n+' se pide desde '+t.pp.min_people+' personas.':''},
      {bad:!d.nombre,id:'ptmCliente',message:'Escribe tu nombre para continuar.'},
      {bad:d.whatsapp.replace(/\D/g,'').length<10||d.whatsapp.replace(/\D/g,'').length>15,id:'ptmWhatsapp',message:'Escribe tu WhatsApp (10 dígitos) para continuar.'},
      {bad:!d.zona,id:'ptmZona',message:'Escribe tu colonia para confirmar la entrega.'},
      {bad:!!errorFecha(t,d.fecha),id:'ptmFecha',message:errorFecha(t,d.fecha)}
    ];
    UPSELLS.forEach(function(u){
      if(u.c&&marcado(u)) checks.unshift({bad:!opcionDe(u),id:'ptmCh_'+u.id+'_0',
        message:'Elige si quieres '+u.c.options.join(' o ').toLowerCase()+' en '+u.n+'.'});
    });
    var missing=checks.filter(function(c){ return c.bad; })[0];
    var error=document.getElementById('ptmError');
    if(missing){
      error.textContent=missing.message;
      error.style.display='block';
      document.getElementById(missing.id).focus();
      return null;
    }
    error.style.display='none';
    return d;
  }
  window.verDetalles=function(id,preset){
    var t=TABLAS[id]; if(!t) return;
    if(t.quote){ track('select_quote',{tabla:id}); window.location.href=t.quote.url; return; }
    if(pagando) return;
    rememberDraft();
    modalId=id;
    returnFocus=document.activeElement;
    var draft=drafts[id];
    document.getElementById('ptmImg').style.background='#d3d5d4 url(\''+t.img+'\') center/cover no-repeat';
    document.getElementById('ptmTag').textContent=t.tag+(t.people?' · '+t.people:'');
    document.getElementById('ptmNombre').textContent=t.n;
    document.getElementById('ptmPrecio').textContent=t.pp?fmt(t.v)+' por persona':fmt(t.v);
    document.getElementById('ptmDesc').textContent=t.d;
    document.getElementById('ptmInc').innerHTML=t.inc.map(function(i){return '<li>'+i+'</li>';}).join('')+
      (t.regalo?'<li><strong style="color:#7c2d3e">'+REGALO.title+' incluida sin costo</strong></li>':'');
    document.getElementById('ptmPrem').checked=!!(t.premAvail&&draft&&draft.premium);
    document.getElementById('ptmPremBox').style.display=t.premAvail?'':'none';
    // Mejora con texto propio (Tabla de Quesos: quesos raros); las demás usan el Premium genérico.
    var premTit=document.getElementById('ptmPremTitulo'), premDesc=document.getElementById('ptmPremDesc');
    if(!premDesc.dataset.base) premDesc.dataset.base=premDesc.textContent;
    premTit.textContent=t.premText?t.premText.title:'Hazla Premium';
    premDesc.textContent=t.premText?t.premText.description:premDesc.dataset.base;
    // Paquete cerrado (Celebración): sin Premium ni extras; lo incluido ya va en el precio.
    // Sin complementos: paquete cerrado (Celebración) o producto que ya incluye todo (Tabla de Quesos).
    document.getElementById('ptmUpsBox').style.display=t.addons?'':'none';
    document.getElementById('ptmToque').style.display=t.bundle?'none':'';
    document.getElementById('ptmPersonasBox').style.display=t.pp?'block':'none';
    if(t.pp){
      var pin=document.getElementById('ptmPersonas');
      pin.min=t.pp.min_people; pin.value=(draft&&draft.personas)||t.pp.min_people;
      document.getElementById('ptmPersonasNota').textContent=fmt(t.v)+' por persona · desde '+t.pp.min_people+' personas.';
    }
    document.getElementById('ptmEnvio').textContent=fmt(MENSAJERIA);
    document.getElementById('ptmPremBox').classList.remove('on');
    if(t.premAvail) document.getElementById('ptmPremPrecio').textContent='+'+fmt(t.prem)+' · queda en '+fmt(t.v+t.prem)+(t.premOk?'':' (se confirma por WhatsApp)');
    document.getElementById('ptmUps').innerHTML=UPSELLS.map(function(u){
      return '<label class="pt-up" id="ptmUp_'+u.id+'"><input type="checkbox" data-up="'+u.id+'" onchange="ptmRecalc()">'+
        '<div><div style="font-weight:600;font-size:14.5px;color:#26282a">'+u.n+' <span style="color:#7c2d3e">+'+fmt(u.v)+'</span></div>'+
        '<div style="font-size:13px;color:#75797a;line-height:1.5">'+u.d+'</div></div></label>'+
        (u.c?'<div id="ptmCh_'+u.id+'" role="radiogroup" aria-label="'+u.c.label+'" style="display:none;flex-wrap:wrap;align-items:center;gap:8px;margin:-2px 0 2px 34px">'+
          '<span style="font-size:13px;color:#55585a;font-weight:600">'+u.c.label+'</span>'+
          u.c.options.map(function(o,i){ return '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13.5px;color:#26282a;background:#fff;border:1px solid #d3d5d4;border-radius:999px;padding:6px 14px;cursor:pointer">'+
            '<input type="radio" id="ptmCh_'+u.id+'_'+i+'" name="ptmCh_'+u.id+'" value="'+o+'" onchange="ptmRecalc()"> '+o+'</label>'; }).join('')+
          '</div>':'');
    }).join('');
    ['ptmCliente','ptmWhatsapp','ptmZona','ptmFecha','ptmNotas'].forEach(function(field){ document.getElementById(field).value=(draft&&draft.fields[field])||''; });
    document.getElementById('ptmError').style.display='none';
    var fechaIn=document.getElementById('ptmFecha'), tope=new Date();
    tope.setDate(tope.getDate()+120);
    fechaIn.min=isoLocal(primeraFecha(t));
    fechaIn.max=isoLocal(tope);
    var todos=LOGISTICA.delivery_days_iso.length===7;
    document.getElementById('ptmRegla').textContent = (todos?'Entregamos todos los días':'Entregamos '+LOGISTICA.delivery_days_labels.join(', '))
      +', con al menos '+minDias(t)+' días de anticipación'+(t.ev?' (tabla de evento).':'.');
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
    ptmRecalc();
    var dialog=document.getElementById('ptModalBg');
    if(!dialog.open) dialog.showModal();
    document.body.classList.add('pt-order-open');
    document.querySelector('.pt-sheet-body').scrollTop=0;
    document.getElementById('ptmClose').focus();
    track('view_product',{item_name:t.n, value:t.v, currency:'MXN'});
    track('view_item',{item_name:t.n, value:t.v, currency:'MXN'});
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
    document.getElementById('ptmPrecio').textContent=t.pp?fmt(t.v)+' por persona':fmt(t.v+(prem.checked?t.prem:0));
    UPSELLS.forEach(function(u){
      var cb=document.querySelector('input[data-up="'+u.id+'"]');
      document.getElementById('ptmUp_'+u.id).classList.toggle('on', !!(cb&&cb.checked));
      if(u.c) document.getElementById('ptmCh_'+u.id).style.display=cb&&cb.checked?'flex':'none';
      if(cb&&cb.checked) total+=u.v;
    });
    document.getElementById('ptmTotal').textContent=fmt(total);
    // Pago en línea: botón principal si el servidor lo tiene encendido y el precio está confirmado.
    var cobro=cobroDe(total);
    var premPend=prem.checked&&!t.premOk;
    var online=PAGO_ONLINE&&!premPend;
    var pagar=document.getElementById('ptmPagar'), waBtn=document.getElementById('ptmWaBtn');
    pagar.style.display=online?'inline-flex':'none';
    if(!pagando) pagar.textContent='Pagar '+fmt(cobro.monto)+' con Mercado Pago';
    var PAGO=LOGISTICA.payment, pct=PAGO.deposit.pct, anticipo=Math.round(total*pct/100);
    document.getElementById('ptmModo').style.display=online?'flex':'none';
    document.getElementById('ptmModoCompleto').classList.toggle('on', cobro.tipo==='total');
    document.getElementById('ptmModoAnticipo').classList.toggle('on', cobro.tipo==='anticipo');
    document.getElementById('ptmModoCompletoMonto').textContent=fmt(total);
    document.getElementById('ptmModoCompletoTxt').textContent='Con tarjeta de crédito puedes pagarlo hasta en '+PAGO.full.installments_max+' meses (con intereses). También tarjeta de débito, OXXO o transferencia.';
    document.getElementById('ptmModoAnticipoMonto').textContent=fmt(anticipo)+' hoy ('+pct+' %)';
    document.getElementById('ptmModoAnticipoTxt').textContent='Apartas tu fecha y el resto, '+fmt(total-anticipo)+', lo liquidas '+restoTexto()+'.';
    waBtn.style.background=online?'transparent':'#26282a';
    waBtn.style.color=online?'#26282a':'#fff';
    waBtn.style.border=online?'1.5px solid #26282a':'none';
    document.getElementById('ptmWaTxt').textContent=online?'¿Dudas? Escríbenos por WhatsApp':'Pedirla por WhatsApp';
    document.getElementById('ptmIntro').textContent=online
      ?'Déjanos tus datos y paga en línea con Mercado Pago: tarjeta, OXXO o transferencia. Te escribimos por WhatsApp para confirmar tu entrega.'
      :(PAGO_ONLINE&&premPend
        ?'La versión Premium de esta tabla se confirma por WhatsApp: te abrimos el chat con tu pedido listo.'
        :'Déjanos tus datos y abriremos WhatsApp con la tabla y el total listos para confirmar.');
    document.getElementById('ptmNota').textContent=online
      ?'Si no tenemos tu fecha, te devolvemos lo que hayas pagado.'
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
    var d=leerDatos(t); if(!d) return;
    var prem=d.prem, nombre=d.nombre, whatsapp=d.whatsapp, zona=d.zona, fecha=fechaLarga(d.fecha), notas=d.notas;
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
  function productLink(el){
    if(el.dataset.ptBuy) return {key:el.dataset.ptBuy};
    if(el.tagName!=='A') return null;
    var url=new URL(el.href,location.href);
    if(url.origin!==location.origin) return null;
    var key=url.searchParams.get('tabla');
    if(key&&TABLAS[key]) return {key:key,premium:url.searchParams.get('premium')==='1'};
    var match=url.pathname.match(/^\/tablas\/(para-dos|anfitriona|fiesta|celebracion)\/$/);
    if(match&&!el.closest('.crumbs,.nav-links,footer,.site-header')) return {key:match[1]==='para-dos'?'dos':match[1],premium:url.hash==='#premium'};
    return null;
  }
  document.querySelectorAll('a[href],button[data-pt-buy]').forEach(function(el){
    var product=productLink(el); if(!product) return;
    el.setAttribute('aria-haspopup','dialog'); el.setAttribute('aria-controls','ptModalBg');
    if(el.tagName==='A'&&!el.querySelector('img')&&/Pagar|Comprar|Elegir|Pedir|Ver detalles/i.test(el.textContent)) el.textContent='Elegir y pedir';
  });
  document.addEventListener('click',function(event){
    if(event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey) return;
    var trigger=event.target.closest('[data-pt-buy],a[href]'); if(!trigger) return;
    var product=productLink(trigger); if(!product) return;
    event.preventDefault(); event.stopImmediatePropagation();
    verDetalles(product.key,product);
  },true);
  var requested=new URLSearchParams(window.location.search).get('tabla');
  if(requested&&TABLAS[requested]) verDetalles(requested,{premium:new URLSearchParams(window.location.search).get('premium')==='1'});
})();
