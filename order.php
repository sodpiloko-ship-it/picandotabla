<?php
// Captura de pedidos de Picando Tabla: guarda el pedido y AVISA a Jessica y David.
// Lo llama el checkout (placeOrder) al confirmar. El cliente ademas abre WhatsApp con el pedido.
// PII: los pedidos viven en data/ (protegido por .htaccess + gitignored). Generado por PATO.
header('Content-Type: application/json; charset=utf-8');

// A QUIEN avisa PATO de cada pedido: Jessica (contacto@, buzon real del dominio = entrega local confiable) + David.
$NOTIFY = ['contacto@picandotabla.com', 'sodpiloko@gmail.com'];
$FROM   = 'Picando Tabla <contacto@picandotabla.com>';  // buzon REAL -> mejor entregabilidad (evita rechazo/spam)

$raw = file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d) || empty($d['items'])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'sin items']); exit; }

$nombre = substr(strip_tags(trim($d['nombre']   ?? '')), 0, 120);
$wa     = substr(strip_tags(trim($d['whatsapp'] ?? '')), 0, 40);
$zona   = substr(strip_tags(trim($d['zona']     ?? '')), 0, 80);
$fecha  = substr(strip_tags(trim($d['fecha']    ?? '')), 0, 60);
$total  = is_numeric($d['total'] ?? null) ? (float)$d['total'] : 0;

$lines = array();
foreach ($d['items'] as $it) {
  $qn = intval($it['qty'] ?? 0);
  $nm = substr(strip_tags($it['nombre'] ?? ''), 0, 80);
  $pr = is_numeric($it['precio'] ?? null) ? (float)$it['precio'] : 0;
  if ($nm !== '') $lines[] = $qn.'x '.$nm.' ($'.number_format($pr, 0).')';
}
if (!count($lines)) { http_response_code(400); echo json_encode(array('ok'=>false)); exit; }

$rec = array('at'=>date('c'),'nombre'=>$nombre,'whatsapp'=>$wa,'zona'=>$zona,'fecha'=>$fecha,
             'total'=>$total,'items'=>$lines,'ip'=>$_SERVER['REMOTE_ADDR'] ?? '');
$dir = __DIR__.'/data';
@mkdir($dir, 0755, true);
@file_put_contents($dir.'/.htaccess', "Require all denied\nDeny from all\n");
@file_put_contents($dir.'/orders.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND|LOCK_EX);

$body = "Nuevo pedido en Picando Tabla\n\n".implode("\n", $lines)."\n\n".
        "Total: $".number_format($total, 0)."\n".
        "Nombre: ".$nombre."\n".
        "WhatsApp del cliente: ".$wa."\n".
        "Zona (CDMX): ".$zona."\n".
        "Entrega deseada: ".$fecha."\n\n".
        "(El cliente tambien te lo envia por WhatsApp al confirmar.)";
$subj = "Nuevo pedido Picando Tabla: $".number_format($total, 0)." - ".$nombre;
$headers = "From: ".$FROM."\r\nContent-Type: text/plain; charset=UTF-8";
foreach ($NOTIFY as $to) { @mail($to, $subj, $body, $headers); }

echo json_encode(array('ok'=>true));
