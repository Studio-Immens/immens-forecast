# Immens Forecast — Design (spec)

Data: 2026-11-01 · Autore: Studio Immens + agente · Stato: proposta per revisione

## 1. Contesto e obiettivi

Studio Immens ha messo in produzione `timestral-api`, un microservizio di time-series
forecasting (modello Mistral Timestral, zero-shot, CPU) raggiungibile su rete interna e
tailnet. Obiettivo di questo progetto: trasformarlo in un **prodotto** distribuibile —
un plugin WordPress **indipendente**, con versione free pubblicabile su WordPress.org e
una versione Pro della suite.

Modello scelto (Approccio A):

- **`immens-forecast`** — free, GPL-2.0-or-later, su w.org e GitHub: motore di forecasting
  autonomo, provider-agnostico, funzionante anche **senza alcun servizio esterno**
  (provider "baseline" locale).
- **`immens-forecast-pro`** — plugin separato, proprietario (licenza STL via siapi,
  aggiornamenti GitHub): si aggancia al free **solo via hook/filter pubblici**.
- **Cloud Studio Immens** (fase 3) — API pubblica a quote, esposta come un normale
  provider "endpoint" preconfigurato; nessuna modifica al free.

Successo atteso: free utile da solo (Woo/CSV + baseline offline + qualunque endpoint BYO),
Pro come upsell naturale della suite, cloud come convenienza.

## 2. Identità e requisiti w.org

- Nome: **Immens Forecast – Sales & Time Series Forecasting**; slug/testo dominio
  `immens-forecast`; namespace PHP `ImmensForecast\`; prefissi `immens_forecast_`,
  costanti `IMMENS_FORECAST_VERSION|PATH|URL|FILE`.
- Licenza GPL-2.0-or-later, LICENSE inclusa; header completo con `Requires at least: 6.4`,
  `Requires PHP: 7.4`, `Tested up to` aggiornato al rilascio.
- Conformità: nessun phone-home, nessuna licenza, nessun feature locking (niente
  trialware), servizi esterni **opzionali e documentati** nel readme (disclosure:
  il plugin funziona offline; opzionalmente si collega a un endpoint di forecasting
  scelto dall'utente), niente codice Pro nel free.
- Discipline: capability `manage_options`, nonce, sanitizzazione, escaping, i18n completa.
- Verifica: `Plugin Check` a 0 errori + PHPCS WordPress prima della release.

## 3. Architettura

```
                 ┌────────────────────────────────────────────┐
                 │ immens-forecast (free, GPL, w.org)         │
 Admin UI ──────►│  Admin (page/AJAX)                         │
                 │  Engine ──► SourceRegistry ──► WooSource   │
                 │          │                   └ ManualSource│
                 │          ├──► ProviderRegistry             │
                 │          │      ├ BaselineProvider (PHP)   │
                 │          │      └ EndpointProvider (HTTP)──┼──► timestral-api (self-host / cloud)
                 │          └──► Cache (transient)            │
                 └───────────────▲────────────────────────────┘
                                 │ hook/filter pubblici
                 ┌───────────────┴────────────────────────────┐
                 │ immens-forecast-pro (proprietario, STL)    │
                 │  sources: CRM, Calendar, SKU, Traffico     │
                 │  automazioni cron+email, export, multi-serie│
                 │  bridge Immens Integration/CRM, cloud cfg  │
                 └────────────────────────────────────────────┘
```

Estensione: sia il Pro sia sviluppatori terzi registrano sorgenti/provider tramite i
filter documentati in `docs/hooks.md`. Il free non conosce il Pro.

Dipendenza Pro: richiede `immens-forecast` attivo (notice di dipendenza se assente),
nessuna modifica ai file del free.

## 4. Contratti

### 4.1 ProviderInterface

```php
namespace ImmensForecast\Forecast\Providers;

interface ProviderInterface {
    public function get_id(): string;          // 'baseline' | 'endpoint' | …
    public function get_label(): string;
    public function get_description(): string;
    public function get_capabilities(): array; // vedi sotto
    public function is_available(): bool;      // es. endpoint configurato
    public function forecast( array $series, int $horizon, array $options ): array|\WP_Error;
    public function health(): array|\WP_Error; // per "Test connessione"
}
```

Capability dichiarate: `max_horizon`, `max_series`, `max_context`, `supports_multi_series`,
`supports_known_covariates`, `supports_past_covariates`, `supports_categorical`,
`quantile_levels` (array di livelli supportati), `requires_remote` (bool).

### 4.2 Formato serie (input normalizzato)

```php
[
  'id'    => 'revenue',
  'values'=> [ 120.0, 98.5, null, … ],          // null = dato mancante
  'past_covariates'      => [ 'dow' => […] ]|null,
  'known_covariates'     => [ 'dow' => […futuro…] ]|null,   // lunghezza n+horizon
  'categorical_past_covariates'  => …|null,
  'categorical_known_covariates' => …|null,
]
```

### 4.3 Risultato normalizzato

```php
[
  'provider' => 'endpoint', 'model' => '209m',
  'horizon' => 30,
  'quantile_levels' => [0.1, 0.5, 0.9],
  'fallback' => false, 'fallback_reason' => null,
  'series' => [
    [ 'id' => 'revenue', 'context_used' => 365,
      'median' => [ …30 valori… ],
      'quantiles' => [ '0.1' => [ … ], '0.5' => [ … ], '0.9' => [ … ] ] ],
  ],
]
```

### 4.4 Contratto endpoint (BYO, riferito a timestral-api)

`POST {base}/v1/forecast` con `Authorization: Bearer <token>`:

```json
{ "series": [ { "id": "revenue", "values": [1, 2, null],
                "past_covariates": { "dow": [1,2,3] },
                "known_covariates": { "dow": [4,5,6] } } ],
  "horizon": 30, "model": "209m", "quantiles": [0.1, 0.5, 0.9] }
```

Risposta:

```json
{ "model": { "alias": "209m", "hub_id": "mistralai/Timestral-209M" },
  "horizon": 30, "quantile_levels": [0.1, 0.5, 0.9],
  "series": [ { "id": "revenue", "context_used": 365,
                "median": [ … ], "quantiles": { "0.1": [ … ], "0.5": [ … ], "0.9": [ … ] } } ],
  "elapsed_ms": 812 }
```

Health: `GET {base}/health` (senza auth). Modelli: `GET {base}/v1/models` (con auth) →
alias disponibili (riferimento: `5m|28m|123m|209m`).

Limiti di riferimento del servizio: orizzonte 1–1024, quantili 0.01–0.99 (21 livelli),
minimo 16 punti/serie, NaN = mancante, contesto max 32768, batch interno 64.
Default client: timeout 60 s, `sslverify` true (filter per dev), quantili richiesti
intersecati con quelli restituiti; se manca `median` si usa il quantile 0.5.

Il contratto è documentato in `docs/provider-contract.md` e nella pagina Impostazioni
(link "come collegare un endpoint", incluso il self-host di `timestral-api`).

## 5. Sorgenti v1 (free)

### 5.1 SourceInterface

```php
interface SourceInterface {
    public function get_id(): string;      // 'woo', 'manual'
    public function get_label(): string;
    public function get_description(): string;
    public function is_available(): bool;
    public function get_metrics(): array;  // id => label, + granularità supportate
    public function get_series( array $args ): array|\WP_Error; // args: metric, granularity,
                                                                // history_start, horizon
}
```

### 5.2 WooSource

- Metriche: `revenue` (netto), `orders` (conteggio), `items` (pezzi).
- Status considerati: `completed` + `processing`; **revenue netto** = totale ordini −
  rimborsi (righe refund/parent), incluse le spese di spedizione e al netto delle imposte
  se l'opzione Woo "prezzi con imposte incluse" è attiva (test dedicato).
- Aggregazione per giorno (o settimana ISO da lunedì) nel fuso del sito.
- Implementazione: aggregazione SQL preparata su `{$wpdb->prefix}wc_order_stats` (+
  `wc_order_product_lookup` per `items`) quando le tabelle esistono; **fallback**
  `wc_get_orders()` paginato a chunk mensili. Entrambi i percorsi coperti da test con
  fixture e confrontati tra loro.
- HPOS: compatibilità dichiarata via `FeaturesUtil::declare_compatibility` quando Woo attivo.
- Covariata nota `dow` (0–6) inclusa per la parte futura.

### 5.3 ManualSource (CSV / incolla)

- Datasets in tabella dedicata (vedi §10): `name`, `granularity` (day|week), `points`
  JSON `[{"d":"YYYY-MM-DD","v":number|null}, …]`.
- Inserimento: incolla `data,valore` per riga oppure solo valori (date generate
  all'indietro); validazione stretta (date reali, `is_numeric`, max 5000 punti,
  max 20 dataset).
- UI: crea/rinomina/elimina/carica; selezione dataset come sorgente.

## 6. BaselineProvider (zero-config, offline)

Deterministico, in PHP puro; è il provider **default** finché l'endpoint non è configurato.

- Requisiti: ≥16 punti e ≥4 valori finiti, altrimenti
  `WP_Error('immens_forecast_insufficient_data')` con conteggio ("servono almeno 16
  punti, presenti N").
- Livello `L` = mediana degli ultimi 28 valori finiti (tutti se meno).
- Stagionalità giornaliera (periodo 7): indice `s(k)` = mediana di `y_i / M` sui punti con
  dow = k, con `M` = mediana generale, clamp `s ∈ [0.2, 5]`; se la storia copre meno di 2
  settimane complete, `s(k) = 1`. Settimanale (periodo 52): solo se ≥104 punti, altrimenti
  solo livello.
- Previsione puntuale: `ŷ_t = L · s(dow_t)`.
- Residui in-sample: ultimi min(90, n) punti con fitted approssimato (`L_i` = mediana dei
  28 valori precedenti · `s(dow_i)`).
- Quantili: empirici (interpolazione lineare) se ≥10 residui; altrimenti normale con
  `σ = 1.4826 · MAD` (banda degenere se `σ = 0`). Monotonia garantita; clamp a 0 se la
  metrica è non negativa (default per revenue/orders/items).
- Capability: `max_horizon` 180, `max_series` 20, quantili 0.01–0.99, offline.

## 7. Engine, cache, rate-limit

1. `Engine::run( $params )`: valida i parametri contro i registry (whitelist), ottiene le
   serie dalla sorgente, verifica le capability del provider, consulta la cache.
2. Cache: transient `immens_forecast_<hash>`; chiave = md5 di
   `sorgente|metrica|granularità|history_start|horizon|provider|modello|quantili|hash(ultimo punto)|versione`.
   TTL default 12 h (filter `immens_forecast_cache_ttl`); nuovi dati invalidano da soli
   tramite l'hash dell'ultimo punto. "Aggiorna ora" bypassa con lock 60 s per utente.
3. Provider remoto: `wp_remote_post` server-side (il token non raggiunge mai il browser),
   timeout configurabile (5–300 s, default 60).
4. **Fallback**: qualunque errore endpoint (rete, timeout, 401/403, 4xx/5xx, risposta
   malformata, 429) → risultato dal Baseline con `fallback: true` e `fallback_reason`;
   la UI mostra il badge "Fallback locale". Anche il 429 produce fallback, con reason
   "rate limit (riprova tra Xs)" ricavata da `Retry-After` quando presente.
5. Lock anti doppio-click: transient 30 s per utente su `run`.

### Codici errore

`immens_forecast_insufficient_data`, `immens_forecast_endpoint_unreachable`,
`immens_forecast_endpoint_timeout`, `immens_forecast_endpoint_unauthorized`,
`immens_forecast_endpoint_http`, `immens_forecast_endpoint_bad_response`,
`immens_forecast_rate_limited`, `immens_forecast_locked`.

## 8. UI/UX

- Menu top-level **"Forecast"** (`dashicons-chart-line`, pos. 30), submenu Forecast +
  Impostazioni; `manage_options` (filter `immens_forecast_capability`). Pro aggiunge
  submenu via `immens_forecast_admin_menu`.
- Pagina Forecast: form (sorgente, metrica, granularità, periodo storico predefinito
  ultimi 365 giorni disponibili, orizzonte default 30, provider, quantili default
  0.1/0.5/0.9) → "Genera previsione" + "Aggiorna ora".
- Risultato: grafico **Chart.js** (storico continuo + mediana tratteggiata + banda
  P10–P90 con fill), 3 KPI (totale previsto nel periodo, delta % vs periodo precedente
  di pari durata, ampiezza intervallo), tabella valori (data, mediana, P10, P90),
  badge provider/modello/tempo e avviso fallback se attivo.
- Accessibilità: label, `aria-live` per risultati/errori, contrasto AA, responsive.
- Impostazioni: provider attivo; endpoint (URL, token, modello con "Test connessione" e
  "Carica modelli"); quantili default; opzione "elimina dati alla disinstallazione".

## 9. Sicurezza

- Capability `manage_options` (filter), nonce `immens_forecast` su tutte le AJAX
  (`check_ajax_referer`), sanitizzazione stretta (whitelist da registry; `absint`;
  `sanitize_text_field`; `esc_url_raw` per l'endpoint; CSV validato).
- Token endpoint **cifrato a riposo**: `sodium_crypto_secretbox` (fallback
  OpenSSL AES-256-CBC + HMAC), chiave derivata da `AUTH_KEY|AUTH_SALT|immens-forecast`;
  mai restituito al client (placeholder; invio vuoto = mantieni).
- JS: nessun segreto lato client; tutte le chiamate al provider passano dal server.
- SSRF: URL endpoint modificabile solo da admin (già privilegiato); schemi ammessi solo
  `http`/`https`; endpoint su localhost consentito **di proposito** (self-host).
- Query DB: `$wpdb->prepare()` ovunque; output sempre escapato; JSON via `wp_send_json_*`.

## 10. Dati e ciclo di vita

- Tabella `{$prefix}immens_forecast_datasets` (dbDelta on activate, versionata in
  `immens_forecast_db_version`):

```sql
id BIGINT UNSIGNED AUTO_INCREMENT,
name VARCHAR(190) NOT NULL,
granularity VARCHAR(10) NOT NULL DEFAULT 'day',
points LONGTEXT NOT NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY (id)
```

- Option `immens_forecast_settings` (attiva provider, endpoint_url, endpoint_token_enc,
  endpoint_model, endpoint_timeout, quantili default, delete_data_on_uninstall).
- Uninstall: cancella option, transient `immens_forecast_%`, e — solo se l'utente ha
  scelto di eliminare i dati — i dataset e la tabella; su multisite opera per sito.
- Nessun cron nel free (le automazioni sono Pro).

## 11. i18n

Testo dominio `immens-forecast`; `.pot` + `it_IT` + `en_US` (workflow skill
`plugin-i18n`); stringhe JS tradotte con `wp_set_script_translations`. README e UI in
inglese di default, italiano completo.

## 12. Testing e CI

- **PHPUnit** (wp-phpunit, WP latest): BaselineProvider (stagionalità, monotonia quantili,
  casi limite), EndpointProvider (mock `pre_http_request`: 200 / 401 / timeout /
  malformato / 429), aggregazione Woo (fixture sintetiche, confronto percorso
  `wc_order_stats` vs `wc_get_orders`), cache (hit/miss/TTL/invalidazione), CSV parsing,
  Crypto round-trip, AJAX (nonce/capability/sanitize).
- **Statici**: `php -l` (7.4, 8.0, 8.3), PHPCS WordPress, `Plugin Check` 0 errori.
- **E2E manuali** (Playwright MCP): attivazione su locale (localhost:10004); run con
  baseline offline; run contro `http://100.102.37.90:8770` via tailnet; verifica grafico,
  KPI, fallback (endpoint spento), token errato; disinstallazione → zero residui.
- **CI GitHub Actions**: lint+phpcs+phpunit+plugin-check su push/PR; release job che
  produce lo zip e lo allega alla GitHub Release.
- **Sicurezza**: giro `wp-security-audit` prima della release.

## 13. Packaging e release

- Repo `Studio-Immens/immens-forecast` (semver, `CHANGELOG.md`, `.distignore`,
  `.gitattributes`), build zip senza dev files.
- w.org: SVN trunk/tags + assets (icon 128/256, banner 772x250/1544x500), readme.txt EN
  con sezione "External services" chiara; deploy via Action dedicata.
- Verifica disponibilità dello slug `immens-forecast` prima del submit (fallback:
  varianti `immens-forecast-ts`, `immens-forecasting`).

## 14. Fasi

- **F1 (questo piano)** — plugin free completo: skeleton, impostazioni+crypto, tabella
  dataset+CSV, WooSource, BaselineProvider, EndpointProvider, Engine+cache+fallback, UI
  con Chart.js, AJAX, uninstall, readme.txt, i18n, test, PHPCS/Plugin Check puliti,
  `docs/hooks.md` + `docs/provider-contract.md`.
- **F2** — pubblicazione OSS di `timestral-api` (repo + Docker + README self-host);
  submit su w.org; `immens-forecast-pro` (sorgenti CRM/Calendar/SKU/traffico, automazioni
  cron+email, export CSV, multi-serie, bridge Immens Integration/CRM, provider cloud
  preconfigurato).
- **F3** — API pubblica a quote (HTTPS, auth per licenza, rate limit); adapter provider
  forecast dedicati (TimeGPT & co.); tool MCP; eventuale widget dashboard.

## 15. Rischi e mitigazioni

| Rischio | Mitigazione |
|---|---|
| Dipendenza da tabelle Woo interne (`wc_order_stats`) | Fallback `wc_get_orders` + test incrociati |
| Review w.org | Free funziona offline; nessun phone-home; disclosure servizi nel readme |
| Slug occupato | Varianti di nome + verifica pre-submit |
| Store grandi/lenti | Cap dataset, aggregazioni SQL, cache 12 h, indicatori di caricamento |
| Token in chiaro | Cifratura a riposo + mai esposto al client |
| Feature creep | Scope F1 chiuso; Pro/cloud esplicitamente fuori |

## 16. Appendice — struttura file (F1) e hook

```
immens-forecast/
├── immens-forecast.php        bootstrap (GPL header, costanti, autoloader, hook)
├── uninstall.php              pulizia opzionale
├── readme.txt                 w.org (EN)
├── LICENSE                    GPL-2.0
├── composer.json              dev: phpcs, phpunit
├── phpcs.xml.dist
├── docs/superpowers/specs/2026-11-01-immens-forecast-design.md
├── docs/{provider-contract.md,hooks.md}
├── includes/
│   ├── Plugin.php
│   ├── Admin/{Page.php,Settings.php,Ajax.php}
│   ├── Forecast/{Engine.php,Series.php,Result.php,Cache.php,Crypto.php}
│   ├── Providers/{ProviderInterface.php,ProviderRegistry.php,BaselineProvider.php,EndpointProvider.php}
│   └── Sources/{SourceInterface.php,SourceRegistry.php,WooSource.php,ManualSource.php}
├── templates/{forecast-page.php,settings-page.php}
├── assets/css/forecast.css · assets/js/forecast.js · assets/vendor/chart.umd.js (+MIT)
├── languages/
└── tests/{bootstrap.php,unit/…,fixtures/…}
```

Hook pubblici v1: `immens_forecast_providers`, `immens_forecast_sources`,
`immens_forecast_capability`, `immens_forecast_cache_ttl`, `immens_forecast_sslverify`,
`immens_forecast_endpoint_headers`, `immens_forecast_result`,
`immens_forecast_quantile_levels`, `immens_forecast_admin_menu` (action),
`immens_forecast_forecast_generated` (action).
