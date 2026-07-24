# Specifica funzionale e tecnica

## 1. Scopo

Questa specifica definisce il comportamento richiesto per:

- la release ponte EasyRankly 2.1;
- EasyRankly Multilingual 1.0 con backend Multisite;
- EasyRankly Multilingual 1.1 con backend Single Site;
- la rimozione definitiva dell'implementazione dal core EasyRankly 3.0.

Un requisito identificato come `MUST` è un gate di rilascio. Un requisito `SHOULD` può essere derogato solo con decisione documentata e test che coprano il comportamento alternativo.

## 2. Perimetro della versione 1.x

### 2.1 Incluso

- registro e gestione delle lingue;
- una lingua per sito nel backend Multisite;
- una lingua per post/termine nel backend Single Site;
- gruppi espliciti di traduzione per post, pagine, CPT pubblici e termini di tassonomie pubbliche;
- home, pagina articoli, archivi, ricerca, 404, feed e sitemap coerenti con la lingua;
- URL a directory sul Single Site;
- Gutenberg, Classic Editor e gestione tassonomie;
- creazione vuota, duplicazione assistita, collegamento e scollegamento;
- switcher, translation notice e associazione menu per lingua;
- valori SEO globali localizzati tramite registro chiuso;
- canonical, hreflang, robots, schema e sitemap coerenti;
- REST, WP-CLI, export/import, Site Health e migrazioni a batch;
- adozione zero-copy dello storage Multisite esistente.

### 2.2 Escluso

- traduzione automatica o integrazione obbligatoria con servizi AI;
- editor visuale sul frontend;
- intercettazione/output buffering dell'HTML;
- traduzione generica di stringhe di temi e plugin;
- domini o sottodomini diversi per lingua sul Single Site;
- slug identici nella stessa gerarchia WordPress per lingue diverse;
- bridge nativi completi per Polylang, WPML, TranslatePress o MultilingualPress;
- migrazione automatica TranslatePress verso documenti distinti;
- workflow editoriale avanzato, agenzie di traduzione e ruoli dedicati;
- WooCommerce profondo: carrello, checkout, email, valute e sincronizzazione prodotti;
- media distinti per lingua; gli allegati sono condivisi nella 1.x;
- alternate `xhtml:link` dentro XML sitemap nella 1.x.
- root/selettore neutrale autonomo per `x-default`; la 1.x può puntare solo a una lingua del set.

## 3. Requisiti generali

### GEN-001 — Un solo owner

In ogni richiesta DEVE esistere al massimo un provider multilingua selezionato. Esso possiede relazioni, contesto e output hreflang. Una doppia registrazione non può degradare in un doppio boot.

Il core invoca il provider una volta e applica i filtri pubblici dopo il risultato. Il provider selezionato non si registra nuovamente sugli stessi filtri alternate/localized URL.

### GEN-002 — Topologia persistente

Il backend viene scelto durante l'attivazione e registrato nelle impostazioni. Un successivo cambio di topologia WordPress non provoca una conversione automatica: l'add-on entra in modalità `safe-readonly` e richiede una migrazione esplicita.

EasyRankly Multilingual 1.0 su Single Site usa `unsupported-topology`: mostra soltanto l'avviso di versione, non crea tabelle e non registra routing/query filter. `single-linked` è disponibile dalla 1.1.

### GEN-003 — Oggetti WordPress reali

Nel Single Site ogni traduzione DEVE restare un normale post o termine WordPress. Disattivare l'add-on non cancella né fonde contenuti.

### GEN-004 — Nessun dual-write

Il core incorporato e l'add-on non possono scrivere nello stesso storage nella stessa fase di ownership. Lo stato `claimed` è fail-closed.

### GEN-005 — Caricamento contestuale

Classi amministrative, editor, REST, CLI e frontend vengono registrate solo nel contesto appropriato. CSS e JavaScript non vengono caricati globalmente.

### GEN-006 — Fallback espliciti

Ogni fallback DEVE dichiarare la propria provenienza (`explicit`, `inferred`, `default`, `unassigned-compat`). UI, diagnostica e resolver non possono confondere una relazione esplicita con una corrispondenza inferita.

## 4. Lingue

### LANG-001 — Identità

La lingua ha un ID opaco immutabile. Locale WordPress, codice hreflang e slug URL sono attributi modificabili e non sono chiavi relazionali.

Nel Single Site l'ID pubblico è l'UUID; il BIGINT usato nelle join è soltanto una PK interna. Configurazione, REST ed export usano `default_language_uuid` / `x_default_language_uuid`, mai la PK locale.

### LANG-002 — Validazione

- `locale` DEVE corrispondere a un locale WordPress valido o esplicitamente installabile.
- `hreflang` DEVE essere un codice BCP 47 normalizzato e univoco tra tutte le lingue registrate, incluse quelle disabilitate.
- `slug` DEVE essere lowercase, sanitizzato, non vuoto e univoco.
- Gli slug `all`, `all-enabled`, `all_enabled`, `current`, `default` e `none` sono riservati, oltre ai segmenti tecnici WordPress.
- Nome e nome nativo DEVONO essere non vuoti.
- Direzione DEVE essere `ltr` o `rtl`.

### LANG-003 — Default

Esiste esattamente una lingua predefinita, che DEVE essere abilitata. `x-default` non è una lingua: nella 1.x può essere disabilitato oppure puntare a una lingua registrata.

La configurazione usa `x_default_mode=disabled|language`; `x_default_language_uuid` è obbligatorio soltanto in modalità `language`. Il default iniziale punta alla lingua predefinita. Per ogni entità, `x-default` usa la URL del membro/virtual route di quella lingua e viene omesso se la destinazione non esiste.

### LANG-004 — Eliminazione

Una lingua referenziata da oggetti, gruppi, valori localizzati, homepage, pagina articoli o menu non può essere eliminata. L'amministratore deve prima scegliere una lingua di riassegnazione o completare una rimozione guidata.

Default e `x-default` devono puntare a lingue abilitate. Disabilitare la lingua `x-default` richiede prima una nuova destinazione o la disattivazione di `x-default`; la lingua default non può essere disabilitata.

### LANG-005 — Cambio default

Il cambio della lingua predefinita richiede:

1. anteprima delle URL interessate;
2. conferma esplicita;
3. materializzazione dei valori globali necessari;
4. snapshot journaled di opzioni e mappe;
5. salvataggio degli attuali `page_on_front`/`page_for_posts` sotto il vecchio UUID, promozione delle pagine del nuovo UUID nelle opzioni WordPress, verifica della loro lingua e normalizzazione delle mappe;
6. cutover atomico dal punto di vista runtime, con lock e rollback riprendibile;
7. flush delle rewrite rules una sola volta;
8. invalidazione di sitemap e cache;
9. report delle redirect consigliate.

### LANG-006 — Multisite

Ogni blog abilitato corrisponde a una sola lingua. La mappa deve avere al massimo un default e codici hreflang univoci. Il salvataggio rifiuta configurazioni ambigue anziché normalizzarle silenziosamente.

Lo stato enabled runtime dopo il claim vive in `erml_network_settings_v1`; il toggle core è soltanto snapshot/interlock di rollback e non viene più letto dall'add-on.

### LANG-007 — Cambio slug lingua

Cambiare lo slug mostra tutte le route old/new, richiede conferma, aggiorna la firma rewrite, invalida cache/sitemap e genera un piano redirect. Se l'infrastruttura redirect non è disponibile, l'admin deve esportare il piano e accettare esplicitamente l'assenza di redirect; nessuna vecchia route continua a rispondere 200 con contenuto duplicato.

### LANG-008 — Lingua disabilitata

Una lingua disabilitata resta modificabile in admin/REST `edit` ma non risolve route frontend pubbliche, non entra in query, alternate, switcher o sitemap e restituisce 404 sulle vecchie URL. Non viene eseguito alcun redirect implicito verso un'altra lingua. Preview firmate restano disponibili agli utenti autorizzati.

## 5. Assegnazioni e gruppi di traduzione

### REL-001 — Assegnazione

Ogni post/termine eleggibile Single Site DEVE avere una lingua, anche se non ha traduzioni. Durante il backfill iniziale, gli oggetti non assegnati sono trattati come lingua default; dopo il gate di migrazione la modalità strict segnala ogni oggetto privo di assegnazione.

Un post/termine creato fuori dal workflow traduzioni riceve, al primo salvataggio reale, la lingua esplicita validata dell'editor/REST, altrimenti il filtro lingua admin corrente, altrimenti il default. Autosave, revisioni e auto-draft non creano assegnazioni. Cambiare lingua a un membro raggruppato richiede il mutation command; un fallimento di persistenza lascia l'oggetto intatto ma lo segnala come unassigned alla diagnostica.

### REL-002 — Invarianti

- Un oggetto appartiene al massimo a un gruppo.
- Un gruppo contiene al massimo un membro per lingua.
- Tutti i membri condividono tipo e subtype compatibili.
- Un membro draft, private, trash o noindex resta collegato ma viene filtrato in base al consumer.
- Cestinare o cancellare un membro non modifica gli altri contenuti.
- Gli URL alternate vengono calcolati, non persistiti.

### REL-003 — Mutazioni concorrenti

Link, unlink, move e replace usano revisione attesa/optimistic locking. Una revisione obsoleta restituisce conflitto `409`; non prevale l'ultima scrittura in modo silenzioso.

Ogni assegnazione ha `object_revision`; ogni gruppo ha `group_revision`. La creazione usa revisione gruppo `0`; link, unlink, move e replace inviano entrambe le revisioni applicabili e le incrementano atomicamente.

Queste revisioni sono native nel Single Site. Nel backend Network una sidecar add-on serializza e revisiona i gruppi senza modificare la tabella legacy; se il preflight non può garantire transazione o mutex equivalente, le mutazioni vengono bloccate invece di usare il vecchio clear-then-insert concorrente.

### REL-004 — Collegamento

Collegare un oggetto già in un altro gruppo richiede l'azione esplicita `move`. Occupare uno slot lingua richiede `replace`, anteprima del membro rimosso e conferma. Il comportamento legacy di eliminare implicitamente lo slot non è ammesso nel backend Single Site.

### REL-005 — Tipi supportati

Default:

- inclusi: post type pubblici e viewable, pagine, post, tassonomie pubbliche;
- esclusi: revisioni, `nav_menu_item`, tipi tecnici, attachment come entità traducibile;
- estensione: filtro server-side che dichiara subtype, capability e policy di copia.

### REL-006 — Fallback Multisite

Per parità iniziale, il backend Multisite 1.0 PUÒ mantenere la corrispondenza per slug esistente quando manca un gruppo esplicito. In tal caso:

- il membro è marcato `inferred`;
- SEO, set navigabile e switcher usano lo stesso resolver di base;
- l'UI permette di trasformarlo in relazione esplicita;
- un match ambiguo non produce alternate;
- il Single Site non usa questa inferenza.

### REL-007 — Eliminazioni WordPress

Gli hook di cancellazione di post, termine e sito rimuovono le sole assegnazioni/relazioni orfane e invalidano cache. Non cancellano le traduzioni collegate. Un audit periodico rileva riferimenti già orfani.

## 6. Routing Single Site

### URL-001 — Strategia 1.x

- Default: lingua predefinita senza prefisso (`/pagina/`).
- Lingue non predefinite: prefisso al primo segmento (`/en/page/`).
- Opzione avanzata: prefisso anche per il default (`/it/pagina/`).
- Permalink plain: `?lang=en` senza perdita degli altri parametri.

### URL-002 — Superfici

Il contesto lingua DEVE funzionare su:

- homepage e pagina articoli;
- singolari post/page/CPT;
- archivi post type, taxonomy, autore e data;
- ricerca, paginazione e feed;
- endpoint sitemap gestiti;
- preview tramite override interno autorizzato.

Admin, login, cron, WP-CLI, file fisici e REST base non ricevono un prefisso di percorso.

### URL-003 — Collisioni

Lo slug lingua viene verificato contro segmenti riservati, rewrite base e slug radice. Un conflitto blocca il salvataggio con indicazione precisa della route coinvolta. Il sistema non cambia automaticamente slug o permalink esistenti.

### URL-004 — URL canonico

Cookie e `Accept-Language` non determinano la URL canonica. Il browser-language redirect è disabilitato di default; se aggiunto in futuro deve essere opt-in, temporaneo, revocabile e non applicato ai crawler noti o a una scelta già esplicita.

### URL-005 — Lingua errata

Un oggetto richiesto sotto il prefisso di un'altra lingua non viene servito con canonical contraddittorio. Restituisce 404 oppure redirect alla propria URL soltanto quando l'associazione è inequivocabile e la policy redirect è abilitata.

La lingua assegnata all'oggetto non può cambiare a posteriori la lingua scelta dalla URL frontend: serve a validare il risultato. Solo admin, REST `edit`, preview firmate e operazioni dirette per ID possono inizializzare esplicitamente il contesto dalla lingua dell'oggetto.

### URL-006 — Unicità slug

La 1.x NON filtra `wp_unique_post_slug()` o `wp_unique_term_slug()`. L'unicità nativa WordPress resta valida; il supporto a slug uguali richiede una futura route table e una specifica separata.

### URL-007 — Riscritture

Il router mantiene gli indici di capture delle rewrite WordPress, aggiunge la query var della lingua senza alterare quelle esistenti e rigenera le regole solo su attivazione o cambio di firma routing. Nessun flush avviene su una normale richiesta frontend.

### URL-008 — Forma canonica del prefisso

Esiste una sola forma 200 per URL. Con `prefix_default=false`, la lingua default è senza prefisso e le altre sono prefissate; un prefisso default in eccesso redirige alla forma senza prefisso. Con `prefix_default=true`, tutte sono prefissate e la forma senza prefisso redirige alla home/route default corrispondente. Una lingua non-default senza prefisso non viene servita come default: se la destinazione è risolvibile in modo univoco, una richiesta GET/HEAD redirige alla route prefissata, altrimenti restituisce 404. Le redirect conservano solo query arg allowlisted.

`?lang=en` è canonico soltanto con permalink plain. Con permalink pretty, un parametro utente `lang` valido redirige alla directory equivalente se risolvibile, altrimenti 404; non crea una seconda forma 200. Le rewrite usano la query var interna `erml_lang`, distinta dall'argomento REST `lang`, e il canonicalizer elimina tentativi di forzarla dalla query pubblica.

## 7. Query e selezione contenuti

### QUERY-001 — Frontend

Archivi, ricerca, feed e query pubbliche eleggibili mostrano solo oggetti della lingua corrente. Il filtro usa JOIN indicizzati e non esegue query per ogni risultato.

### QUERY-002 — Compatibilità bootstrap

Durante il backfill, gli oggetti senza assegnazione sono inclusi soltanto nella lingua default. A backfill verificato, questa eccezione viene disabilitata.

### QUERY-003 — Contesti

| Contesto | Scope default |
|---|---|
| Frontend / REST `view` | lingua corrente |
| Admin list | `all`, con filtro lingua persistente per utente |
| REST `edit` | `all` se `lang` assente |
| Sitemap | `all_enabled`, poi filtro indexability |
| Export / audit | `all` |
| Cron / CLI | `all`, salvo lingua esplicita |
| Query con escape hatch PHP documentato | `all` o `all_enabled` |

Gli scope ammessi sono `current`, `all_enabled` e `all`. L'escape hatch `erml_language_scope` viene impostato soltanto da codice server-side fidato sulla query e non è una query var frontend pubblica; i controller REST la valorizzano solo dopo permission check.

### QUERY-004 — Lookup diretto

`get_post()` e lookup per ID non cambiano semantica. Il controllo lingua avviene nel router/consumer; non si rende globalmente invisibile un oggetto valido a codice server-side.

### QUERY-005 — Termini

I term picker editoriali mostrano per default termini della stessa lingua del contenuto. Se un termine collegato ha la traduzione corrispondente, la duplicazione lo rimappa; altrimenti la relazione viene omessa e segnalata.

### QUERY-006 — Media e menu

Gli attachment sono condivisi. I menu sono normali menu WordPress distinti associati per lingua e location; l'add-on non traduce `nav_menu_item` come contenuto editoriale.

## 8. Esperienza amministrativa

### UI-001 — Navigazione

L'add-on registra una voce **Multilingual** sotto EasyRankly quando l'API UI è disponibile; in assenza della superficie core usa una propria pagina amministrativa senza dipendere dal markup interno del core. Su Multisite le impostazioni di rete risiedono in Network Admin.

### UI-002 — Gestione lingue

La schermata lingue consente aggiunta, modifica, ordinamento, enable/disable, scelta default e policy `x-default`. Prima del salvataggio mostra collisioni, riferimenti e impatto URL.

### UI-003 — Editor contenuti

Gutenberg usa un `PluginDocumentSettingPanel` dell'add-on. Il Classic Editor usa un metabox dell'add-on. Entrambi mostrano:

- lingua assegnata;
- gruppo e sua revisione;
- membri per lingua con stato, URL frontend ed edit URL;
- azioni crea vuota, duplica, collega, scollega, move e replace;
- provenienza esplicita/inferita nel Multisite.

Autosave e revisioni non mutano relazioni. Un'azione di gruppo usa un endpoint REST dedicato e nonce valido.

### UI-004 — Termini

Le schermate add/edit term espongono lingua e traduzioni con lo stesso modello di conflitto. Il bulk assign è permesso solo per oggetti non raggruppati o dopo conferma esplicita.

### UI-005 — Liste

Le liste post/termini mostrano lingua e disponibilità delle traduzioni, forniscono filtro lingua e azioni rapide. La rete Multisite usa ricerca e paginazione server-side; non carica l'intera rete in un select.

### UI-006 — Creazione traduzione

Richiesta:

```json
{
  "idempotency_key": "request-uuid",
  "source": {"type": "post", "id": 42},
  "target_language": "language-uuid",
  "mode": "blank|duplicate",
  "expected_source_object_revision": 3,
  "expected_source_group_revision": 7
}
```

Il target nasce sempre `draft`. `blank` crea struttura minima; `duplicate` applica il manifest seguente.

Il server acquisisce e verifica lo slot lingua prima di creare il draft. `idempotency_key` è obbligatoria: una ripetizione della stessa richiesta restituisce lo stesso risultato, mentre una richiesta concorrente diversa riceve 409 senza creare un post. Se un errore avviene dopo `wp_insert_post()`, un journal compensa eliminando o cestinando soltanto il draft appena creato dal job; il gate richiede zero draft orfani.

### UI-007 — Manifest di duplicazione

| Categoria | Policy |
|---|---|
| Titolo, contenuto, excerpt, SEO title/description, social copy, breadcrumb, keyword, blocchi schema testuali | Copia come seed da tradurre |
| Robots, esclusione sitemap, schema mode e tipi disabilitati | Copia strutturale |
| Featured image e media | Condividi per default |
| Tassonomie | Mappa alla traduzione; se assente ometti e segnala |
| Primary term | Mappa alla traduzione; altrimenti azzera |
| Canonical esplicito | Non copiare |
| Custom field | Solo allowlist/filtro esplicito |
| Report AI, job, lock, suggerimenti, stato runtime | Mai copiare |

L'UI distingue chiaramente copia editoriale da traduzione automatica; nessun contenuto duplicato viene pubblicato automaticamente.

### UI-008 — Semantica e usabilità dei controlli

Le superfici mantengono i controlli nativi, le label, il focus management e gli
stati testuali già implementati. La certificazione formale WCAG, screen reader
e assistive technology è fuori dal grafo M1-M10 e non costituisce un gate di
milestone o release.

## 9. Valori globali localizzati

### GLOBAL-001 — Registro chiuso

Una chiave può essere salvata soltanto se registrata server-side con formato, sanitizer, capability, sorgente e fallback. Il browser non può creare chiavi arbitrarie.

Il bridge usa esclusivamente i filtri pubblici `erankly_setting_value`, `erankly_global_entity_meta_map` e `erankly_site_special_meta` introdotti dal core 2.1; non sostituisce funzioni core né legge file interni.

I formati ammessi sono `text`, `textarea`, `url`, `html` con policy KSES e `json` con JSON Schema registrato. I blocchi schema globali usano esclusivamente `json` validato e vengono restituiti come array, mai inseriti come stringa non verificata.

### GLOBAL-002 — Ambito iniziale

La 1.x può localizzare:

- nome e motto del sito;
- template SEO globali per post type e tassonomie;
- home, pagina articoli, search, 404 e archivi;
- testi del translation notice;
- valori testuali ammessi di Organization/WebSite;
- blocchi schema globali dichiarati compatibili;
- metadati autore esplicitamente registrati.

Identità tecnica, toggle, email, telefono, immagini e identificatori restano condivisi salvo registrazione esplicita.

### GLOBAL-003 — Fallback

Per ogni chiave la policy è una tra `default-language`, `generated` o `empty`. Il fallback non viene materializzato automaticamente come traduzione. Un cambio della sorgente può marcare l'override `needs_review` senza rimuoverlo dal frontend.

Per impostazioni già possedute da WordPress/EasyRankly, il valore della lingua default resta nello storage sorgente e la tabella add-on conserva gli override non-default. Un cambio default promuove e materializza i valori con un job reversibile; non scambia opzioni durante una richiesta frontend.

### GLOBAL-003A — Writer pubblico della sorgente EasyRankly

La promozione della lingua predefinita DEVE usare esclusivamente
`erankly_get_localized_value_source_state()` e
`erankly_update_localized_value_source()`. Il core espone soltanto chiavi
testuali registrate e validate con il relativo sanitizer; non espone accesso
arbitrario all'opzione `erankly_settings`.

La lettura fornisce valore corrente, hash e fingerprint CAS. Write e restore
richiedono `expected_fingerprint`, condividono il mutex degli altri writer
settings, rileggono e fanno merge sullo stato corrente, verificano il valore
persistito e non possono perdere impostazioni estranee. Snapshot stale
restituiscono `409 erankly_localized_value_source_revision_conflict`.
Ripetere un write o restore già verificato è idempotente. Errori di storage o
verifica restano fail-closed e non includono il valore. Capability o contesto
non autorizzati restituiscono un `WP_Error` bounded. Se l'API manca o ha un
contratto incompatibile, l'add-on mantiene
`503 erml_default_language_source_write_unavailable`; non sono ammessi accessi
privati o fallback diretti.

### GLOBAL-004 — Menu

Le location possono puntare a un menu diverso per lingua. Se manca il mapping, la policy default è fallback al menu della lingua predefinita con diagnostica, non menu vuoto silenzioso.

### GLOBAL-004A — Entità virtuali e pagine speciali

`front_page_map` e `posts_page_map` sono indicizzate per UUID lingua e accettano soltanto pagine assegnate alla stessa lingua. Quando `show_on_front=posts`, la home è virtuale. Search, 404, archivi post type/taxonomy/autore/data e feed sono virtuali: ricevono contesto, URL e valori globali senza creare righe oggetto o gruppi artificiali.

Per la lingua default, `page_on_front` e `page_for_posts` WordPress restano la sorgente canonica; le mappe coprono le altre lingue. I filtri si applicano soltanto nel frontend congelato, così `is_front_page()` e `is_home()` restano corretti e l'admin continua a leggere le opzioni reali.

### GLOBAL-005 — Nessun output capture

Non vengono intercettati HTML finale, widget generici, stringhe gettext, email o template di terze parti. Un nuovo tipo di valore richiede registrazione esplicita o bridge.

## 10. Frontend

### FRONT-001 — Switcher

Lo switcher è disponibile come blocco `easyrankly-multilingual/language-switcher`, shortcode `[erml_language_switcher]` e integrazione menu. Consuma il set **navigabile**, non il set SEO.

Il renderer:

- include solo lingue abilitate e destinazioni pubblicate;
- identifica la lingua corrente con `aria-current="page"`;
- può mostrare nome nativo, codice o bandiera fornita dall'utente;
- non usa una bandiera come unica etichetta accessibile;
- conserva solo query arg allowlisted;
- permette policy per traduzione mancante: nascondi, disabilita, home lingua;
- non inventa un URL quando la relazione è ambigua.

### FRONT-002 — Translation notice

La notice è opt-in, disponibile come blocco `easyrankly-multilingual/translation-notice` e shortcode `[erml_translation_notice]`, localizzata nella lingua della destinazione e usa lo stesso set navigabile dello switcher. Asset e script vengono caricati soltanto quando blocco/shortcode/notice sono effettivamente renderizzati.

### FRONT-003 — Compatibilità shortcode

Nel backend Multisite 1.x gli shortcode legacy `[erankly_language_switcher]` e `[erankly_translation_notice]` restano alias supportati. I nuovi nomi canonici sono documentati dall'add-on; una deprecazione futura non può rimuovere gli alias prima della major 2.

## 11. SEO

### SEO-001 — Fonte unica di eleggibilità

L'add-on usa l'API neutrale EasyRankly per determinare `published`, `public`, `indexable` e canonical. Non legge direttamente il solo meta legacy `_erankly_noindex`.

### SEO-002 — Due set distinti

- **SEO set:** membri pubblicati, pubblici, indicizzabili e con `canonical_is_self=true`.
- **Navigable set:** membri pubblicati e visitabili, inclusi quelli noindex.

Draft, pending, private, trash, password-protected non pubblico e URL non assoluti non entrano in nessuno dei due set pubblico.

### SEO-003 — Hreflang

Il set hreflang DEVE:

- essere completo e reciproco;
- includere il riferimento a sé stesso;
- contenere codici univoci e URL HTTP(S) assolute;
- contenere almeno due membri validi prima di emettere tag;
- includere al massimo un `x-default` e solo se la destinazione esiste;
- essere derivato dalla stessa revisione del gruppo per tutta la richiesta.

La sanitizzazione finale resta nel core. Un solo owner emette i tag.

### SEO-004 — Canonical

Ogni traduzione indicizzabile ha canonical autoreferenziale nella propria lingua. Qualunque canonical effettivo diverso dalla URL risolta, anche nella stessa lingua, imposta `canonical_is_self=false` ed esclude il membro da hreflang e sitemap; un target cross-language produce inoltre warning ad alta severità. Il membro può restare navigabile.

### SEO-005 — Indicizzazione e default

Cambiare lingua default o strategia prefisso non produce automaticamente redirect permanenti. L'UI genera un piano delle coppie old/new; l'applicazione delle redirect richiede conferma e usa l'infrastruttura redirect disponibile.

### SEO-006 — Schema

Il bridge rende language-aware almeno:

- `WebSite`: `@id`, `url`, nome, descrizione, `SearchAction`;
- `WebPage`: `@id`, `url`, `isPartOf`, `inLanguage`;
- `Article`: URL, `mainEntityOfPage` e riferimenti coerenti;
- `BreadcrumbList`: ogni URL nel contesto corrente;
- Organization/Person: identità condivisa, soli valori testuali registrati localizzati;
- blocchi schema globali dichiarati traducibili.

`inLanguage` usa la lingua del contenuto, non il locale dell'utente admin.

Formule ID/URL obbligatorie:

- `language_home_url` è la home canonica della lingua secondo `prefix_default`;
- `WebSite.@id = language_home_url + '#website'` e `WebSite.url = language_home_url`;
- `SearchAction.target` usa la search route della stessa lingua;
- `WebPage.@id = canonical_url + '#webpage'`;
- `Article.@id = canonical_url + '#article'` e `mainEntityOfPage` punta al WebPage corrente;
- Organization/Person condivise mantengono l'ID stabile sulla home default, mentre i soli campi registrati possono variare per lingua;
- ogni breadcrumb usa URL risolta nello stesso contesto lingua.

### SEO-007 — Sitemap Single Site

L'indice sitemap resta unico. Ogni oggetto tradotto appare con la propria URL linguistica; lingue disabilitate, oggetti non indicizzabili e membri non self-canonical sono esclusi secondo la policy EasyRankly. La query sitemap opera cross-language e non viene accidentalmente filtrata alla lingua della richiesta.

La 1.x non promette `xhtml:link` negli XML. La documentazione core che lo dichiara senza implementazione deve essere corretta.

### SEO-007A — Archivi autore e user sitemap

L'autore è un'entità condivisa e la sua route è virtuale per lingua. Se la user sitemap è abilitata dalla policy EasyRankly e EasyRankly la possiede, il provider emette soltanto tuple `(author, language)` con almeno un post pubblico, indicizzabile, self-canonical e sitemap-eligible in quella lingua, usando scope `all_enabled`; non può lasciare una sola URL default né creare archivi vuoti. Con sitemap owner esterno, l'add-on non registra un secondo provider e mostra lo stato di compatibilità finché esiste un adapter supportato.

### SEO-008 — Sitemap Multisite e robots

Ogni sito continua ad avere la propria sitemap. L'add-on può aggiungerne gli URL a `robots.txt` tramite `erankly_robots_txt_lines`, con deduplicazione e senza enumerare siti disabilitati/non pubblici.

Il frammento network viene precomputato e invalidato da un job a batch. Una richiesta `robots.txt` legge una cache pronta e non esegue inventario live o `switch_to_blog()` su tutta la rete; in assenza di cache serve un output minimo sicuro e accoda la rigenerazione.

### SEO-009 — Google News

Se il modulo News è attivo in Single Site, `news:language`, nome pubblicazione, query e cache devono essere language-aware. Se non è possibile garantire file omogenei, la sitemap News viene separata per lingua. La release Single Site non può dichiarare compatibilità News finché questo test non è verde.

### SEO-010 — Owner SEO esterno

Se un altro plugin possiede canonical/meta ma non il multilingua, routing e relazioni restano attivi. L'add-on emette hreflang solo se l'arbitraggio conferma che l'altro plugin non lo emette. Non viene registrata una seconda sitemap senza adapter esplicito.

## 12. REST API

Namespace canonico: `/wp-json/easyrankly-multilingual/v1`.

### REST-001 — Endpoint minimi

| Metodo | Route | Scopo |
|---|---|---|
| GET | `/languages` | elenco lingue accessibili |
| POST | `/languages` | crea lingua |
| PATCH | `/languages/{language_uuid}` | modifica con expected language revision |
| DELETE | `/languages/{language_uuid}` | elimina dopo preflight riferimenti |
| GET | `/objects/{type}/{object_id}/translations` | lingua, gruppo e membri |
| PATCH | `/objects/{type}/{object_id}/language` | assegna lingua con object revision |
| GET | `/objects/search` | ricerca target permessi/paginata |
| POST | `/translations` | crea blank/duplicate |
| PATCH | `/groups/{group_uuid}` | link, move o replace con revisione |
| DELETE | `/groups/{group_uuid}/members/{language_uuid}` | unlink con revisione |
| GET | `/settings` | configurazione accessibile |
| PATCH | `/settings` | salva con expected settings revision |
| GET | `/health` | diagnostica non sensibile |
| POST | `/migrations/{job}` | azione amministrativa esplicita |

### REST-002 — Risposta gruppo

```json
{
  "group": "group-uuid",
  "group_revision": 7,
  "members": {
    "it-uuid": {"type": "post", "id": 42, "object_revision": 3, "status": "publish", "url": "..."},
    "en-uuid": {"type": "post", "id": 81, "object_revision": 1, "status": "draft", "url": "..."}
  }
}
```

I campi `edit_url` e dati non pubblici sono presenti solo se l'utente può modificare l'oggetto.

`{object_id}` è un intero WordPress positivo; `{group_uuid}` e `{language_uuid}` sono UUID validati. `PATCH /objects/{type}/{object_id}/language` richiede `language_uuid` ed `expected_object_revision`; se l'oggetto è raggruppato restituisce conflitto e richiede una mutation di gruppo. Il body canonico di `PATCH /groups/{group_uuid}` è:

```json
{
  "operation": "link|move|replace",
  "entity": {"type": "post", "id": 81},
  "language_uuid": "en-uuid",
  "source_group_uuid": null,
  "target_group_uuid": "group-uuid",
  "expected_replaced_member": null,
  "expected_object_revision": 1,
  "expected_source_group_revision": 0,
  "expected_target_group_revision": 7
}
```

`replace` richiede `expected_replaced_member` con type/ID; `move` richiede entrambi i gruppi. DELETE richiede nel body `expected_object_revision` e `expected_source_group_revision`. Campi incoerenti con l'operazione restituiscono `422 erml_invalid_mutation`.

Ogni lingua restituisce `revision`; il relativo PATCH richiede `expected_revision`. GET settings restituisce `revision` e `values`; PATCH richiede `expected_revision` e `values`. Aggiornamenti concorrenti di default, slug, prefisso, home/posts page o menu restituiscono `409 erml_revision_conflict` con lo snapshot corrente.

### REST-003 — Errori

| HTTP | Codice | Caso |
|---:|---|---|
| 403 | `erml_forbidden` | capability o membership insufficiente |
| 409 | `erml_object_already_grouped` | oggetto in altro gruppo |
| 409 | `erml_language_slot_occupied` | slot lingua occupato |
| 409 | `erml_revision_conflict` | revisione obsoleta |
| 409 | `erml_url_prefix_conflict` | route in conflitto |
| 409 | `erml_owner_conflict` | altro owner multilingua |
| 423 | `erml_adoption_locked` | mutazione sospesa da lease di adopt/rollback |
| 422 | `erml_type_mismatch` | tipo/subtype incompatibile |
| 422 | `erml_language_disabled` | lingua disabilitata |
| 503 | `erml_safe_readonly` | migrazione/topologia non operativa |

Ogni errore include messaggio localizzato e dati macchina sufficienti a ricaricare lo stato. Nessun endpoint mutante usa GET.

### REST-004 — Compatibilità Multisite

Le route legacy `/erankly/v1/ml/search` e `/erankly/v1/settings/multilingual` restano alias nella 1.x Multisite, con stessi controlli di sicurezza e header/deprecation notice dove supportato. Non vengono registrate due callback concorrenti.

Anche il REST field post legacy `erankly_ml_links` resta uno shim compatibile per tutta la 1.x Multisite, con identico payload intenzionale, capability e test del vecchio client Gutenberg. Il nuovo campo canonico non causa una seconda scrittura.

### REST-005 — Parametro lingua nelle collection WordPress

Le collection supportate `/wp/v2/*` registrano l'argomento REST `lang` come slug lingua validato. `lang=all` è ammesso soltanto con `context=edit` e capability di edit sul post type/taxonomy; in `view` è rifiutato. Se `lang` manca, `view` usa la lingua richiesta/default e `edit` usa scope `all`. Questo argomento REST non è la rewrite var frontend interna.

### REST-006 — Lingua nel create/update WordPress

I tipi supportati registrano nel REST schema `edit` il campo calcolato `erml_language_uuid` e restituiscono anche `erml_object_revision`, `erml_group_uuid` e `erml_group_revision`; non sono post/term meta. Su create/update il controller valida `erml_language_uuid` prima dell'insert e persiste l'assegnazione subito dopo la creazione dell'oggetto. Se manca usa filtro admin/REST esplicito e poi default. Cambiare il campo su un membro raggruppato restituisce 409 e richiede la mutation di gruppo.

Se l'assegnazione esplicita fallisce su un nuovo post, il post viene lasciato/forzato `draft`, la risposta è errore con recovery object ID e nessun contenuto viene pubblicato nella lingua sbagliata. Un termine appena creato viene compensato/eliminato dal job della stessa richiesta; se la compensazione fallisce resta escluso dal frontend e segnalato P0 in Site Health. Su update l'assegnazione precedente resta invariata. Gutenberg invia questo campo al primo salvataggio reale; autosave e revisioni non cambiano lingua.

## 13. Permessi e sicurezza

### SEC-001 — Capability

| Azione | Requisito minimo |
|---|---|
| Configurare rete Multisite | `manage_network_options` |
| Configurare lingue Single Site | `manage_options` |
| Assegnare/collegare post | `edit_post` su source e target |
| Creare traduzione | capacità di creare il target post type e modificare source |
| Gestire termini | capability della tassonomia e accesso al termine |
| Import/export | `manage_options` o `manage_network_options` secondo scope |
| Uninstall con cancellazione | amministratore autorizzato + consenso persistente |

Nel Multisite l'utente deve inoltre essere membro/capace sul blog target. La ricerca REST non può enumerare blog o contenuti inaccessibili.

### SEC-002 — Controlli

- Nonce REST o admin per ogni mutazione browser.
- Permission callback su ogni route.
- Sanitizzazione per tipo e escaping all'output.
- Query preparate, identificatori SQL allowlisted e `%i` dove disponibile.
- Redirect limitati a URL validate; nessun open redirect.
- Nessun dato grezzo di input o output AI viene conservato.
- Upload import tramite API WordPress e validazione schema/dimensione prima del parsing.
- `switch_to_blog()` sempre bilanciato in `finally` o percorso equivalente.

### SEC-003 — CSRF/IDOR/concorrenza

I test devono coprire nonce mancante, capability insufficiente, ID di un altro blog, oggetto target non editabile, request forgiata, revisione obsoleta e doppia creazione contemporanea dello stesso slot.

## 14. Migrazione e adozione

### MIG-001 — Precondizione core

L'add-on non adotta lo storage Multisite finché il core non offre handshake provider e cleanup ownership-aware. Su EasyRankly 1.x/2.0 resta dormiente.

### MIG-002 — Adozione zero-copy

L'adozione Multisite:

1. legge schema, opzioni e stato modulo;
2. calcola conteggi e fingerprint ordinato di righe/gruppi/mappa;
3. esegue audit di default, hreflang, orfani e gruppi incoerenti;
4. salva journal `pending` con current/candidate owner, lease e token CAS;
5. sospende temporaneamente le mutazioni ML legacy, lasciando attive le letture;
6. verifica un campione deterministico di URL/alternate;
7. passa a `ready`; sotto lock ricalcola fingerprint e generation;
8. importa il toggle in `erml_network_settings_v1`, salva lo snapshot, spegne il flag legacy e ne verifica la rilettura;
9. passa atomicamente a `claimed` solo se token, owner, stato e fingerprint coincidono e il flag legacy è off;
10. ricalcola lo stesso fingerprint dopo il claim;
11. produce report scaricabile privo di contenuti sensibili.

Non rinomina tabella, opzioni, group ID o shortcode. L'operazione è idempotente e riprendibile.

Reset/uninstall preservano lo storage se esiste qualunque journal non concluso, inclusi `pending`, `ready` ed `error`. REST save, reset e uninstall concorrenti durante un lease ricevono uno stato di manutenzione e non aggirano il lock.

Il claim mantiene il mutex condiviso `erankly_ml_ownership_lock` dalla rilettura/modifica del toggle fino al CAS marker. Ogni save core 2.1 dell'intera `erankly_settings` usa lo stesso mutex, rilegge/merge i dati correnti e non può riattivare il toggle in `pending`, `ready`, `error`, `claimed`, `rollback_ready` o `retained`; non sovrascrive impostazioni estranee da uno snapshot stale. Rollback mantiene il mutex fino a owner CAS, ripristino/verifica toggle e chiusura journal.

### MIG-003 — Fingerprint

Il fingerprint include almeno:

- schema/versione;
- count totale relazioni;
- count gruppi distinti;
- count per blog e object type;
- hash ordinato delle tuple relazionali;
- hash normalizzato della mappa lingue;
- generation cache.

Timestamp e campi volatili non entrano nell'hash.

Violazioni legacy (default/hreflang duplicati, gruppi misti, orfani o slot duplicati) bloccano il claim. `verify/adopt` non corregge nulla. Un comando separato `wp erml repair --dry-run` produce anteprima; l'esecuzione richiede conferma, journal proprio e approvazione di un nuovo fingerprint.

### MIG-004 — Bootstrap Single Site

1. Crea la lingua default dal locale WordPress.
2. Lascia gli URL default invariati (`prefix_default=false`).
3. Abilita la compatibilità per oggetti non assegnati.
4. Esegue backfill post/termini a batch con cursor stabile.
5. Verifica conteggi e orfani.
6. Passa a strict.
7. Solo allora consente l'attivazione di lingue aggiuntive e routing pubblico.

Un'interruzione riprende dal journal; non ricomincia dal primo oggetto.

### MIG-005 — Rollback

Su core 2.x bridge, rollback significa restituire ownership al fallback sullo stesso storage, dopo verifica, e soltanto dopo disattivare l'add-on. Uninstall non è un meccanismo di rollback.

Il rollback DEVE precedere downgrade e disattivazione: entra in `rollback_ready`, congela i writer, trasferisce owner al core con toggle ancora off, poi ripristina/verifica il valore `enabled` e chiude il journal. Se si forza EasyRankly 2.0 mentre lo storage è claimed, l'interlock mantiene il modulo incorporato spento e l'add-on resta dormiente; riabilitare manualmente quel flag è unsupported. Ogni errore resta fail-closed.

Dopo scritture esclusive non compatibili con il fallback, il marker deve dichiarare `rollback_possible=false` e il sistema non finge reversibilità. Il percorso supportato è `claimed -> rollback_ready -> core`; una semplice disattivazione lascia `claimed` e provoca safe stop.

### MIG-006 — Conversioni esterne

Multisite-to-Single Site e import da plugin esterni sono flussi separati basati su export normalizzato e mapping oggetti. Non vengono eseguiti implicitamente durante activation/update.

## 15. Export/import

### DATA-001 — Formato

Il formato canonico è JSON streaming `erml/1` e contiene manifest, backend sorgente, lingue, configurazione, assegnazioni, gruppi, valori localizzati e diagnostica. Non contiene segreti, nonce, cache, job o URL firmate.

### DATA-002 — Riferimenti

Gli ID WordPress e i PK dei gruppi non sono identificatori portabili. Il file usa UUID/riferimenti stabili e conserva ID, subtype, slug/path ed eventuale external ID come indizi di mapping.

### DATA-003 — Import

Ogni import richiede dry-run. Policy default `skip`; `explicit-merge` e `explicit-replace` richiedono conferma. Il job è a batch, journaled, idempotente per `export_id` e seguito da audit.

### DATA-004 — Esito

Il report distingue creati, collegati, saltati, non risolti, conflitti e rollback disponibili. Un import parziale non viene dichiarato riuscito.

## 16. Lifecycle e proprietà dei dati

### LIFE-001 — Activation

Activation verifica dipendenza, API, topology, storage e conflict owner prima di scrivere. Nel Multisite la 1.x richiede network activation; una activation per-site fallisce senza creare dati.

### LIFE-002 — Deactivation

La disattivazione non elimina tabelle, opzioni, meta, relazioni, contenuti o rewrite history. Esegue solo cleanup di cron/lock temporanei e flush controllato quando necessario.

### LIFE-003 — Reset core

Il reset EasyRankly non tocca dati con ownership add-on/tombstone o con journal `pending`, `ready`, `error`, `claimed`, `rollback_ready` o `retained`. In multi-network controlla tutte le reti prima di una eventuale operazione globale.

### LIFE-004 — Uninstall core

EasyRankly 2.x preserva storage adottato; EasyRankly 3.x non contiene alcun cleanup multilingua. Disinstallare il core con add-on attivo non deve cancellare lingue o relazioni.

### LIFE-005 — Uninstall add-on

Default: retain data. La cancellazione richiede `erml_remove_data_on_uninstall=true`, ownership verificata e scope esatto. Contenuti post/termine restano comunque intatti. Su multi-network la tabella legacy non viene eliminata se una rete la usa o non è stata verificata.

Con retention, uninstall lascia un tombstone `retained` leggibile dal core e il fallback resta bloccato. Solo purge completo e verificato elimina storage e marker.

Oltre 100 siti o su multi-network, la cancellazione richiede prima `wp erml purge --all-networks --yes`. Se `uninstall.php` non trova un purge journal completo e verificato per ogni rete consenziente, conserva i dati; non avvia un job che non potrebbe terminare dopo la rimozione dei file.

### LIFE-006 — Upgrade/downgrade

Ogni migrazione schema è versionata, idempotente e journaled. Un downgrade incompatibile entra in safe-readonly, non tenta una migrazione inversa non definita.

### LIFE-007 — Journal operazioni editoriali

Il journal `erml_translation_operations` non conserva contenuti: soltanto riferimenti, hash richiesta, stato, risultato/errore e ID del draft creato. Un cron elimina record terminali scaduti dopo almeno 7 giorni; record non terminali o con compensazione pendente restano visibili in Site Health e non vengono rimossi finché risolti.

## 17. Conflitti con plugin esterni

### EXT-001 — Owner multilingua

Se Polylang, WPML, TranslatePress, MultilingualPress o un provider equivalente è attivo:

- modalità native non avviata;
- nessun rewrite, query filter o output hreflang dell'add-on;
- avviso con owner rilevato;
- solo un bridge esplicito e supportato potrà essere selezionato in futuro.

Il conflitto imposta `fallback_allowed=false`: su core 2.x non deve riemergere neppure il provider incorporato.

### EXT-002 — Owner SEO

Un owner SEO esterno non disattiva automaticamente relazioni e routing. Canonical/meta restano al SEO owner; hreflang e sitemap richiedono arbitraggio per evitare duplicati.

### EXT-003 — Rilevamento prudente

Il conflict detector usa API/costanti/plugin basename documentati e filtri estensibili. Un sospetto non verificabile produce warning e diagnostica, non una cancellazione o takeover automatico.

## 18. Prestazioni e cache

### PERF-001 — Query

- Nessun loop su tutti i siti in una richiesta frontend.
- Nessuna query N+1 per lista, switcher o sitemap.
- Gli archivi usano un solo JOIN indicizzato per il filtro lingua.
- A cache calda, una pagina singolare non aggiunge più di due query SQL per contesto/relazioni; a cache fredda non più di cinque, escluse integrazioni opzionali.
- Le operazioni di rete sono paginate/keyset e riprendibili.

### PERF-002 — Reti grandi

La UI e i job devono funzionare almeno con 501 siti. Nessun `get_sites()` con limite totale fisso può essere usato come inventario completo.

### PERF-003 — Cache

Le chiavi includono provider, topology, network/site, lingua, entity/group, mode e generation. Le mutazioni invalidano cache mirate e incrementano generation quando la copertura mirata non è certa. I test includono object cache persistente.

### PERF-004 — Asset

- Nessun asset editor fuori dall'editor supportato.
- Nessun asset settings fuori dalle pagine add-on.
- Nessun asset frontend se switcher/notice non viene renderizzato.
- Manifest e handle appartengono all'add-on e non dipendono dagli asset del core.

### PERF-005 — Misurazione

Ogni budget viene misurato sullo ZIP di distribuzione, con query cache fredda/calda e baseline senza add-on. Una regressione fuori budget blocca il rilascio o richiede ADR e nuovo budget approvato.

## 19. Internazionalizzazione e privacy

### I18N-001

Tutte le stringhe UI usano il text domain `easyrankly-multilingual`. Le stringhe di contenuto dell'utente non vengono passate a funzioni gettext.

### I18N-002

Locale dell'admin, lingua del contenuto e hreflang sono concetti distinti. Date/numeri nell'UI seguono l'admin; output editoriale segue il contesto del contenuto quando applicabile.

### PRIV-001

Non viene inviata telemetria per default. Report diagnostici non includono contenuti, email, token, cookie o URL private complete; un export di contenuti è sempre un'azione esplicita.

## 20. Compatibilità obbligatoria

| Scenario | Esito richiesto |
|---|---|
| Core 1.x/2.0 + add-on, mai claimed | Add-on dormiente, nessun fatal/scrittura, fallback invariato |
| Downgrade forzato a 2.0 mentre claimed | Add-on dormiente e flag legacy interlocked off; rollback richiesto prima di riattivare |
| Core 2.1 senza add-on, storage non claimed | Provider incorporato, comportamento baseline |
| Core 2.1 senza add-on, storage claimed | Fallback bloccato, safe stop e istruzione di rollback/riattivazione |
| Core 2.1 dopo uninstall add-on con retention | Tombstone `retained`, fallback bloccato, dati preservati |
| Core 2.1 + add-on non adottato/preflight KO | Fallback sicuro, report admin |
| Core 2.1 + add-on `claimed` | Solo add-on; mai fallback concorrente |
| Core 3.x senza add-on | Nessun multilingua, nessun fatal, dati preservati |
| Core 3.x + add-on | Solo add-on tramite API v1 compatibile |
| Add-on senza core | Dormiente, avviso admin, nessuna scrittura |
| Altro owner multilingua | Modalità native bloccata |

## 21. Difetti della baseline che NON vanno preservati

L'implementazione deve correggere prima della dichiarazione di parità:

1. riferimento concreto a `$GLOBALS['erankly_ml_resolver']` / `ERankly_ML_Resolver` nel consumer degli alternate navigabili;
2. uso del solo `_erankly_noindex` invece dello stato robots/indexability effettivo;
3. divergenza tra fallback slug dell'hreflang e relazioni mostrate dallo switcher;
4. limite amministrativo di 200 siti;
5. possibilità di default multipli o codici hreflang duplicati;
6. assenza delle relazioni nell'export;
7. cleanup multilingua posseduto direttamente da reset/uninstall del core;
8. dichiarazione di alternate nelle XML sitemap non supportata dal renderer;
9. dipendenza dell'output hreflang dal fatto che EasyRankly possieda o meno l'intero head SEO.

## 22. Scenari di accettazione end-to-end

### A — Adozione Multisite

Dato un network EasyRankly 2.1 con 250 siti, relazioni, default e notice legacy, quando l'add-on esegue verify e adopt, allora conteggi/fingerprint restano uguali, il provider core non si avvia, ogni pagina produce gli stessi URL intenzionali, UI e switcher non troncano la rete e il rollback riattiva il fallback sullo stesso storage.

### B — Nuovo Single Site

Dato un sito italiano esistente, quando l'add-on viene attivato, allora crea l'italiano come default senza cambiare le URL, assegna i contenuti in batch, permette di aggiungere inglese e crea traduzioni inglesi draft sotto `/en/` senza pubblicare duplicati automaticamente.

### C — SEO

Dato un gruppo IT/EN pubblicato e indicizzabile, entrambe le pagine hanno canonical autoreferenziale e tag hreflang reciproci, self e `x-default`. Se EN passa noindex, scompare dal set SEO ma resta nel switcher; se diventa draft, scompare anche dal set navigabile.

### D — Concorrenza

Dato un gruppo con slot EN libero, quando due editor creano simultaneamente EN, una richiesta riesce e l'altra riceve `409 erml_revision_conflict` o `erml_language_slot_occupied`; nessun oggetto viene scollegato silenziosamente.

### E — Lifecycle

Dato storage `claimed`, quando il core viene resettato o disinstallato, lingue e relazioni restano invariate. Quando l'add-on viene disattivato, i contenuti restano. Solo uninstall add-on con consenso esplicito elimina lo storage, non i contenuti.

### F — Conflitto

Dato Polylang attivo, quando si tenta di avviare il backend nativo, l'add-on resta inattivo con diagnostica e non aggiunge rewrite, filtri query, sitemap o hreflang.
