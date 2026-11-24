# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).
Este projeto segue [versionamento semântico](https://semver.org/lang/pt-BR/).

## [1.1.0] - 2026-11-24

Correções da auditoria de segurança de 2026-11-24 (detalhe em
`DOCUMENTATION.md` §6.6).

### Segurança
- Limite de tentativas de login passou a ser atômico (`flock`, reserva antes
  de conferir a senha) e falha fechada; IPv6 conta por /64.
- Logout só por `POST` com token da sessão; `?logout=1` deixou de existir.
- Redirecionamentos não usam mais `REQUEST_URI`.
- `password` que não seja texto é recusado; `display_errors` desligado no
  código e tratador global de exceção com resposta genérica.
- `Content-Security-Policy` com nonce enviada pelo PHP, sem
  `'unsafe-inline'` em script; demais cabeçalhos de segurança também saem
  do PHP.
- Conteúdo de tickets e artigos ganhou política própria dentro do iframe
  (sem requisição externa, sem envio de formulário).
- Autocomplete de tags sem `innerHTML`.
- Cookie de sessão `SPVSESSID`, sempre `Secure`.
- Limite de 20 s por consulta no banco.

### Alterado
- A aplicação exige HTTPS (cookie sempre `Secure`).
- `.htaccess.example`: a CSP saiu do Apache; ficou só o HSTS.
- `.gitignore` cobre cópias de segurança de `.htaccess` e
  `sp_local_config.php`.

### Atualização
- Conferir se o diretório temporário do PHP é gravável antes de trocar o
  arquivo. Quem estiver logado precisa entrar de novo uma vez.

## [1.0.0] - 2026-07-13

Primeira versão numerada, consolidando a auditoria de segurança completa e a
responsividade da interface.

### Segurança
- Corrige XSS armazenado crítico: anexos com mime perigoso (`text/html`,
  `image/svg+xml`) eram servidos com `Content-Disposition: inline`, sem
  allowlist, executando no domínio da aplicação. `serve_media()` agora força
  download para qualquer mime fora de `SAFE_INLINE_MIMES`.
- Cookie de sessão com `Secure`/`HttpOnly`/`SameSite` e timeout de
  inatividade (30 min).
- Headers de segurança: `Content-Security-Policy`, `X-Frame-Options`,
  `X-Content-Type-Options`, `Referrer-Policy`, `Strict-Transport-Security`.
- Bloqueio de dotfiles por prefixo no `.htaccess` (`.htaccess.bak.*` e afins
  deixam de ser serváveis).
- Corrige checagem de containment em `serve_media()` (comparação por
  prefixo de string, não de diretório).
- Corrige regressão do próprio CSP acima: faltava `script-src 'self'
  'unsafe-inline'`, o que bloqueava todo o JavaScript da aplicação
  (troca de tema, autocomplete de tags, redimensionamento de iframe).

### Adicionado
- Mecanismo de configuração `sp_local_config.php`, fora do webroot,
  alternativa recomendada ao `SetEnv` (não depende de `mod_env` nem de
  regra de bloqueio de dotfile do host).
- Interface responsiva: tabelas com rolagem horizontal contida e colunas
  secundárias ocultas em telas estreitas; formulário de login fluido;
  conteúdo de tickets/artigos adaptado a qualquer largura de tela.
- Versão e link de changelog na tela de login.
- `LICENSE` (MIT).

### Corrigido
- Bug de colisão de variável (`$st` reaproveitado) em `list_tickets()` que
  quebrava a paginação em toda listagem de tickets.
- Redimensionamento do iframe de conteúdo passou a usar `ResizeObserver`
  em vez de medir a altura uma única vez no `onload` (que deixava de fora
  imagens carregadas depois desse evento).

## [0.x] - antes do versionamento formal

Histórico anterior a esta numeração não foi versionado formalmente; ver o
[histórico de commits](https://github.com/sierti/consulta-supportpal/commits/main)
no GitHub.
