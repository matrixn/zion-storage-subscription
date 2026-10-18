<section><h2>Comunicare API</h2>
<p>Cereri către Stripe, Oblio, Immich și CAPTCHA, plus webhookurile Stripe primite. Se înregistrează reușitele și erorile, inclusiv la plăți, sincronizări și operațiuni automate. Ultimele 30 de zile, maximum 10.000 de înregistrări.</p>
<p>Cheile, antetele de autentificare, parametrii și corpurile integrale ale cererilor/răspunsurilor nu sunt salvate. Mesajele de diagnostic sunt filtrate. Pentru Stripe, o înregistrare include reîncercările interne ale SDK-ului.</p>
<form method="get" class="search api-log-filters"><input type="hidden" name="section" value="api-log">
<?php foreach(['provider'=>['Serviciu',['stripe'=>'Stripe','oblio'=>'Oblio','immich'=>'Immich','captcha'=>'CAPTCHA']],'status'=>['Rezultat',['error'=>'Erori','ok'=>'Reușite']],'mode'=>['Mediu Stripe',['test'=>'TEST','live'=>'LIVE']],'direction'=>['Direcție',['out'=>'Trimise','in'=>'Primite']]] as $key=>[$label,$options]): ?>
<label><?= e($label) ?><select name="<?= e($key) ?>"><option value="">Toate</option><?php foreach($options as $value=>$text): ?><option value="<?= e($value) ?>" <?= ($apiFilters[$key]??'')===$value?'selected':'' ?>><?= e($text) ?></option><?php endforeach ?></select></label>
<?php endforeach ?><button>Filtrează</button><a href="/admin?section=api-log">Resetează</a></form>
<?php if(!$apiEntries['available']): ?><p role="alert" class="alert">Jurnalul nu poate fi citit. Verifică permisiunile directorului bazei de date pentru utilizatorul PHP.</p>
<?php elseif(!$apiEntries['rows']): ?><p>Nu există comunicații API pentru filtrele alese. Jurnalizarea începe după instalarea acestei versiuni. Repetă testul de conexiune pentru a înregistra eroarea curentă.</p>
<?php else: ?><p><?= (int)$apiEntries['total'] ?> înregistrări · Pagina <?= (int)$apiEntries['page'] ?></p>
<div class="table api-log-table"><table><thead><tr><th>Data / Serviciu</th><th>Cerere</th><th>Rezultat</th><th>Diagnostic</th></tr></thead><tbody>
<?php foreach($apiEntries['rows'] as $entry): ?><tr>
<td data-label="Data / Serviciu"><?= e($formatDate((int)$entry['created_at'])) ?><br><strong><?= e(ucfirst($entry['provider'])) ?></strong> <?= e(strtoupper($entry['mode'])) ?><br><small><?= $entry['direction']==='in'?'Primită':'Trimisă' ?></small></td>
<td data-label="Cerere"><strong><?= e($entry['method']) ?></strong><br><code><?= e($entry['endpoint']) ?></code></td>
<td data-label="Rezultat"><span class="badge <?= $entry['status']==='error'?'amber':'green' ?>"><?= $entry['status']==='error'?'Eroare':'Reușită' ?></span><br><?= $entry['http_status']?'HTTP '.(int)$entry['http_status']:'Fără răspuns HTTP' ?><br><small><?= (int)$entry['duration_ms'] ?> ms</small></td>
<td data-label="Diagnostic"><strong><?= e($entry['error_code']) ?></strong><p><?= nl2br(e($entry['message'])) ?></p><?php if($entry['request_id']): ?><small>Stripe Request ID: <code><?= e($entry['request_id']) ?></code></small><?php endif ?>
<?php if(preg_match('/curl_6\b|resolve host/i',$entry['error_code'].' '.$entry['message'])): ?><small>Verifică rezolvarea DNS din containerul sau serverul PHP.</small>
<?php elseif(preg_match('/curl_(35|60|77)\b|SSL|certificate/i',$entry['error_code'].' '.$entry['message'])): ?><small>Verifică certificatele CA și configurația TLS a serverului PHP; păstrează verificarea certificatelor activă.</small>
<?php elseif(preg_match('/curl_(7|28)\b|timed? out|connect/i',$entry['error_code'].' '.$entry['message'])): ?><small>Verifică accesul HTTPS de ieșire, firewallul, proxy-ul și timeouturile.</small><?php endif ?></td>
</tr><?php endforeach ?></tbody></table></div>
<nav class="filter-tabs" aria-label="Paginare jurnal API"><?php foreach([-1=>'← Anterior',1=>'Următor →'] as $offset=>$label): $target=$apiEntries['page']+$offset;if($target<1 || ($target-1)*50>=$apiEntries['total'])continue; ?><a href="/admin?<?= e(http_build_query(['section'=>'api-log']+$apiFilters+['page'=>$target])) ?>"><?= e($label) ?></a><?php endforeach ?></nav>
<?php endif ?></section>
