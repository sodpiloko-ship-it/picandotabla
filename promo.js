/* Generado por tools/build_catalog.py desde data/catalogo.json. No editar. */
(function(){
  var P={"hasta": "2026-09-30", "banner": "PROMOCIÓN · Caja de tapas GRATIS en todos los pedidos · hasta el 30 de septiembre"};
  function vigente(){ if(!P.hasta) return false; var d=new Date(), h=P.hasta.split('-'); return d<=new Date(+h[0],+h[1]-1,+h[2],23,59,59); }
  window.PT_PROMO_EXTENDIDA=vigente();
  if(!window.PT_PROMO_EXTENDIDA) return;
  function aplicar(){
    document.querySelectorAll('.promo').forEach(function(el){ if(/Caja de tapas GRATIS/.test(el.textContent)) el.textContent=P.banner; });
    document.querySelectorAll('[data-promo-ext]').forEach(function(el){ el.textContent=el.getAttribute('data-promo-ext'); });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',aplicar); else aplicar();
})();
