<?php
/**
 * Verificacao das correcoes de seguranca do sp_viewer.php.
 *
 * Rode com:  php tests/test_sp_viewer.php
 *
 * Sem framework e sem dependencia, de proposito: o projeto e um arquivo so e
 * nao tinha suite. Nao da para incluir `sp_viewer.php` aqui, porque incluir
 * executa a aplicacao inteira (sessao, config, conexao, roteador), entao a
 * funcao pura e recortada do arquivo REAL por ancora. Assim o teste exercita o
 * codigo que vai para producao, e nao uma copia que pode envelhecer.
 */
declare(strict_types=1);

$ALVO = dirname(__DIR__) . '/sp_viewer.php';
$src  = file_get_contents($ALVO);
if ($src === false) { fwrite(STDERR, "nao consegui ler $ALVO\n"); exit(2); }

$falhas = 0;
function want(string $caso, $obtido, $esperado): void {
    global $falhas;
    if ($obtido === $esperado) { printf("  ok      %s\n", $caso); return; }
    $falhas++;
    printf("  FALHOU  %s\n            esperava %s\n            veio     %s\n",
        $caso, var_export($esperado, true), var_export($obtido, true));
}
function want_true(string $caso, bool $ok): void { want($caso, $ok, true); }

/* ── recorte por ancora: so a funcao like_term ────────────────────────── */
if (!preg_match('/^function like_term\(.*?\n\}/ms', $src, $m)) {
    fwrite(STDERR, "ABORTADO: nao achei like_term() em sp_viewer.php\n");
    exit(2);
}
eval($m[0]);

echo "── achado 3: curinga do LIKE escapado ──\n";
want('termo comum vira %termo%',            like_term('suporte'),  '%suporte%');
want('porcento do usuario deixa de ser curinga', like_term('%'),   '%\\%%');
want('sublinhado deixa de ser curinga',     like_term('a_b'),      '%a\\_b%');
want('contrabarra e escapada primeiro',     like_term('a\\b'),     '%a\\\\b%');
want('combinacao dos tres',                 like_term('\\%_'),     '%\\\\\\%\\_%');
want('termo vazio ainda casa tudo (por desenho)', like_term(''),   '%%');
// A ordem importa: escapar `%` antes da contrabarra produziria `\\%`, que e uma
// contrabarra literal seguida de curinga, ou seja, o curinga voltaria a valer.
want_true('a contrabarra e escapada ANTES dos curingas',
    like_term('\\%') === '%\\\\\\%%');

echo "\n── achado 3: os dois pontos de LIKE usam o helper ──\n";
want_true('busca principal usa like_term',  (bool)preg_match('/\$like\s*=\s*like_term\(\$q\)/', $src));
want_true('autocomplete usa like_term',     (bool)preg_match("/bindValue\(':t',\s*like_term\(\\\$term\)\)/", $src));
want('nenhum LIKE montado a mao fora do helper',
    preg_match_all("/'%'\s*\.\s*\\\$/", $src), 0);

echo "\n── achado 2: o 404 de midia nao vaza caminho do disco ──\n";
if (!preg_match('/if \(\$path === \'\'\) \{.*?\n    \}/ms', $src, $b)) {
    fwrite(STDERR, "ABORTADO: nao achei o bloco de 404 do serve_media\n");
    exit(2);
}
$bloco = $b[0];
want_true('o bloco existe e foi recortado',      $bloco !== '');
want('a resposta nao imprime os candidatos',     preg_match('/exit\([^)]*\$candidates/', $bloco), 0);
want('a resposta nao imprime SP_STORAGE',        preg_match('/exit\([^)]*\$base/', $bloco), 0);
want_true('os candidatos vao para o error_log',  (bool)preg_match('/error_log\(.*\$candidates/s', $bloco));
want_true('a mensagem ao usuario continua generica',
    (bool)preg_match("/exit\('Arquivo não localizado no disco\.'\)/", $bloco));

/* ── limite de tentativas de login (M3 de 2026-09-05, refeito na v1.1.0) ─ */
if (!preg_match('/^const LOGIN_MAX_FALHAS.*?\n(?=\/\*\*)/ms', $src, $c)
    || !preg_match('/^function login_throttle\(.*?\n\}/ms', $src, $f)) {
    fwrite(STDERR, "ABORTADO: nao achei login_throttle() em sp_viewer.php\n");
    exit(2);
}
ini_set('error_log', '/dev/null'); // a falha fechada registra no log; nao sujar a saida
eval($c[0] . $f[0]);
$dir = sys_get_temp_dir() . '/sp_viewer_test_' . getmypid();
@mkdir($dir);
$t0  = 1_800_000_000;
echo "── limite de login: reserva antes de verificar ──\n";
want('IP novo esta liberado',                    login_throttle('10.0.0.1', null,  $t0, $dir), 0);
$r = [];
for ($i = 1; $i <= 5; $i++) { $r[] = login_throttle('10.0.0.1', false, $t0 + $i, $dir); }
want('as 5 primeiras tentativas sao liberadas',  $r, [0, 0, 0, 0, 0]);
want('a 6a tentativa e bloqueada sem contar',    login_throttle('10.0.0.1', false, $t0 + 6, $dir), 899);
want('consulta mostra o bloqueio de 15 min',     login_throttle('10.0.0.1', null,  $t0 + 6, $dir), 899);
want('outro IP nao e afetado',                   login_throttle('10.0.0.2', null,  $t0 + 6, $dir), 0);
want('bloqueio expira 15 min apos a ultima tentativa', login_throttle('10.0.0.1', null, $t0 + 5 + 901, $dir), 0);
login_throttle('10.0.0.1', false, $t0 + 2000, $dir);
login_throttle('10.0.0.1', true,  $t0 + 2001, $dir);
want('sucesso zera o contador',                  login_throttle('10.0.0.1', null,  $t0 + 2002, $dir), 0);

echo "\n── limite de login: falha fechada ──\n";
want('diretorio do contador inexistente bloqueia a reserva',
    login_throttle('10.0.0.3', false, $t0, $dir . '/nao_existe'), LOGIN_JANELA);
want('diretorio do contador inexistente bloqueia a consulta',
    login_throttle('10.0.0.3', null,  $t0, $dir . '/nao_existe'), LOGIN_JANELA);

echo "\n── limite de login: IPv6 conta por /64 ──\n";
want('dois enderecos do mesmo /64 dividem o contador',
    login_throttle_chave('2001:db8:1:2::1'), login_throttle_chave('2001:db8:1:2:ffff::9'));
want_true('/64 diferente tem contador proprio',
    login_throttle_chave('2001:db8:1:2::1') !== login_throttle_chave('2001:db8:1:3::1'));
want('IPv4 segue por endereco exato',            login_throttle_chave('192.0.2.7'), '192.0.2.7');

echo "\n── limite de login: 20 reservas simultaneas liberam no maximo 5 ──\n";
$filho = "$dir/filho.php";
file_put_contents($filho, "<?php\n" . $c[0] . $f[0] . "\necho login_throttle('10.0.0.9', false, $t0, " . var_export($dir, true) . ") === 0 ? 'L' : 'B';\n");
$procs = [];
for ($i = 0; $i < 20; $i++) {
    $procs[] = proc_open([PHP_BINARY, $filho], [1 => ['pipe', 'w']], $pp[$i]);
}
$saida = '';
foreach ($procs as $i => $p) { $saida .= stream_get_contents($pp[$i][1]); proc_close($p); }
want('20 processos responderam',                 strlen($saida), 20);
want('exatamente 5 liberadas',                   substr_count($saida, 'L'), 5);
want('15 bloqueadas',                            substr_count($saida, 'B'), 15);
unlink($filho);

echo "\n── fluxo de login, logout e redirecionamento (leitura do fonte) ──\n";
$bl = preg_match('/if \(empty\(\$_SESSION\[\'auth\'\]\)\) \{.*?render_login\(\$err\);/s', $src, $lb) ? $lb[0] : '';
want_true('o login reserva a tentativa antes do password_verify',
    strpos($bl, 'login_throttle($ip, false)') !== false && strpos($bl, 'login_throttle($ip, false)') < strpos($bl, 'password_verify'));
want('a tentativa e contada uma unica vez',      substr_count($bl, 'login_throttle($ip, false)'), 1);
want_true('sucesso zera o contador',             strpos($bl, 'login_throttle($ip, true)') !== false);
want_true('bloqueio responde 429',               strpos($bl, 'http_response_code(429)') !== false);
want_true('senha precisa ser string',            strpos($bl, "is_string(\$_POST['password'])") !== false);
want('nenhum cast de array para string na senha', preg_match('/\(string\)\s*\$_POST\[\'password\'\]/', $src), 0);
want('nenhum Location montado com REQUEST_URI',  preg_match('/header\([^;]*REQUEST_URI/', $src), 0);
want('logout por GET nao existe mais',           preg_match('/\$_GET\[\'logout\'\]/', $src), 0);
want_true('logout exige POST e token da sessao',
    (bool)preg_match('/REQUEST_METHOD\'\] === \'POST\' && isset\(\$_POST\[\'logout\'\]\).*?hash_equals\(\$ref, \$tok\)/s', $src));
want('nenhum link de logout por GET na pagina',  substr_count($src, '?logout'), 0);

echo "\n── navegador: CSP com nonce e nada de manipulador em atributo ──\n";
want_true('display_errors desligado no inicio',  strpos($src, "ini_set('display_errors', '0')") !== false);
want_true('CSP emitida pelo PHP com nonce',      (bool)preg_match('/Content-Security-Policy: default-src \'self\'.*script-src \'nonce-/s', $src));
want('CSP do PHP sem unsafe-inline em script',   preg_match('/script-src[^;"]*unsafe-inline/', $src), 0);
want('nenhum <script> sem nonce',                preg_match('/(^|\')<script>/m', $src), 0);
want('nenhum atributo onclick ou onload emitido', preg_match('/\son(click|load|input|submit)="/', $src), 0);
want('autocomplete nao monta HTML com dado do banco', preg_match('/innerHTML\s*=\s*[^\';]*r\.name/', $src), 0);
want_true('cookie de sessao sempre Secure',      (bool)preg_match("/'secure'\s*=>\s*true,/", $src));
want_true('iframe de terceiro tem politica propria',
    strpos($src, "default-src \\'none\\'; img-src \\'self\\' data:") !== false);
array_map('unlink', glob("$dir/sp_login_*") ?: []); @rmdir($dir);

echo "\n── allowlist de IP (L-01 a L-05) ──\n";
foreach (['ip_bin', 'ip_regras', 'ip_casa', 'client_ip'] as $fn) {
    if (!preg_match('/^function ' . $fn . '\(.*?\n\}/ms', $src, $mm)) {
        fwrite(STDERR, "ABORTADO: nao achei $fn() em sp_viewer.php\n"); exit(2);
    }
    eval($mm[0]);
}
function libera(string $remoto, string $lista, string $proxies = '', ?string $xff = null): bool {
    global $CFG;
    $CFG = ['trusted_proxies' => $proxies];
    $_SERVER['REMOTE_ADDR'] = $remoto;
    if ($xff === null) { unset($_SERVER['HTTP_X_FORWARDED_FOR']); } else { $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff; }
    $regras = @ip_regras($lista, 'teste');
    $cli = ip_bin(@client_ip());
    return $regras && $cli !== null && ip_casa($cli, $regras);
}
ini_set('error_log', '/dev/null');
want('IP exato listado passa',                    libera('203.0.113.10', '203.0.113.10'), true);
want('IP fora e negado',                          libera('198.51.100.9', '203.0.113.10'), false);
want('prefixo de texto nao casa',                 libera('203.0.113.10', '203.0.113.1'), false);
want('faixa CIDR: dentro passa',                  libera('203.0.113.10', '203.0.113.0/24'), true);
want('faixa CIDR: fora e negado',                 libera('203.0.114.10', '203.0.113.0/24'), false);
want('/20 com bits parciais: dentro',             libera('203.0.127.1', '203.0.112.0/20'), true);
want('/20 com bits parciais: fora',               libera('203.0.128.1', '203.0.112.0/20'), false);
want('IPv6 em maiusculas casa',                   libera('2001:db8::1', '2001:DB8::1'), true);
want('IPv6 por extenso casa',                     libera('2001:db8::1', '2001:0db8:0:0:0:0:0:1'), true);
want('IPv4 mapeado (::ffff:) casa com o IPv4',    libera('::ffff:203.0.113.10', '203.0.113.10'), true);
want('IPv4 nao casa com faixa IPv6',              libera('203.0.113.10', '2001:db8::/32'), false);
want('virgula sobrando nao libera IP vazio',      libera('', '203.0.113.10,'), false);
want('lista so com item invalido nega',           libera('203.0.113.10', 'abc,203.0.113.0/99'), false);
want('item invalido nao derruba os validos',      libera('203.0.113.10', 'abc,203.0.113.10'), true);
want('sem proxy confiavel, X-Forwarded-For e ignorado', libera('198.51.100.9', '203.0.113.10', '', '203.0.113.10'), false);
want('proxy confiavel: cliente listado passa',    libera('192.0.2.5', '203.0.113.10', '192.0.2.0/24', '203.0.113.10'), true);
want('proxy confiavel: cliente fora e negado',    libera('192.0.2.5', '203.0.113.10', '192.0.2.0/24', '198.51.100.9'), false);
want('IP forjado a esquerda do XFF nao vale',     libera('192.0.2.5', '203.0.113.10', '192.0.2.0/24', '203.0.113.10, 198.51.100.9'), false);
want('dois proxies na cadeia: cliente listado',   libera('192.0.2.5', '203.0.113.10', '192.0.2.0/24', '203.0.113.10, 192.0.2.9'), true);
want('lixo no XFF nega',                          libera('192.0.2.5', '203.0.113.10', '192.0.2.0/24', '203.0.113.10, lixo'), false);
want('proxy fora da lista confiavel: XFF ignorado', libera('192.0.3.5', '203.0.113.10', '192.0.2.0/24', '203.0.113.10'), false);
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
want_true('env_val le tambem REDIRECT_<nome>',    strpos($src, "\$r = 'REDIRECT_' . \$k;") !== false);
$p_allow = strpos($src, "if (\$CFG['allow_ips'] !== '') {");
$p_sess  = strpos($src, 'session_start();');
want_true('sessao so abre depois da allowlist',   $p_allow !== false && $p_sess !== false && $p_allow < $p_sess);
want('session_start aparece uma vez so',          substr_count($src, 'session_start();'), 1);
want_true('limite de login usa o mesmo IP da allowlist', (bool)preg_match('/\$ip\s*=\s*client_ip\(\);/', $src));

echo $falhas === 0
    ? "\nOK, todas as verificacoes passaram\n"
    : "\n$falhas verificacao(oes) falharam\n";
exit($falhas === 0 ? 0 : 1);
