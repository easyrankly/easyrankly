# Schema.org recheck — 7 settembre 2026

## Correzioni di questo passaggio

1. **i18n JS** — `assets/js/schema-jsonld.js` usa `wp.i18n` (stesse stringhe PHP). Dipendenza `wp-i18n` + `wp_set_script_translations`.
2. **Avvisi merge `@id`** — `do_action( 'erankly_schema_merge_warnings' )`, log se `WP_DEBUG`, commento HTML per chi può `edit_posts`, voce admin bar.
3. **Event** — niente nodo senza `Place` (nome/indirizzo) o `VirtualLocation` + URL. Fixture TEC virtuale coperta.
4. **Focus** — notice `#erankly-invalid-json-ld-notice` + focus sulla textarea invalida (classico); in Gutenberg focus all’avvio del salvataggio.
5. **Targeting singular** — lista post type vuota = tutti i tipi pubblici (allineato all’help text).
6. **FormTokenField** — rimosso `__experimentalShowHowTo` (deprecato in WP 7.1).

## Gutenberg (Playwright, editor reale)

Pagina bozza ID 55, poi eliminata.

| Passo | Esito |
|---|---|
| Pannello Schema visibile | Sì (`simplified_mode=0`) |
| Learn more | URL Google reale, non `#` |
| Add JSON-LD da Automatic | Passa a **Automatic + custom** e crea textarea |
| `{"foo":"bar"}` | `aria-invalid=true`, `aria-describedby`, `role=alert` |
| JSON valido + `{{post_url}}` | Help semantico, nessun alert |
| Salva bozza | `POST /wp/v2/pages/55` **200** |
| Risposta REST | `mode=merge`, blocco non `null` |
| Reload editor | mode + JSON persistiti |
| Viewport 390×844 | `documentElement.scrollWidth=390`, pannello 390px, no overflow |
| Console | 0 errori (2 warning core WP: JQMIGRATE / iframe styles) |
| Frontend pubblicato | 1 script JSON-LD, `Thing` “Gutenberg round trip”, variabile risolta |

## Audit live (`studio wp eval-file tests/live-schema-audit.php`)

Tutti PASS, poi `RESTORE`. Inclusi in più rispetto al giro precedente:

- più blocchi REST
- salvataggio campo non correlato
- Event senza location
- TEC incompleto / virtuale
- HowTo da HTML
- Article/BlogPosting + Organization
- ProfilePage `mainEntity`
- messaggi di merge
- targeting globale IT vs EN
- replace/disabled sul blog inglese

WooCommerce assente → Product saltato consapevolmente.

## Unit eval (senza PHPUnit)

Validazione, REST schema, merge, Event location, HowTo, QAPage, SearchAction default, i18n JS: tutti PASS.

PHPUnit non è installato (`WP_TESTS_DIR` / `phpunit` assenti).

## Ripristino

- Impostazioni originali ripristinate dall’audit live.
- Nessuna pagina `erankly-schema-audit*`, HowTo/Article fixture, Gutenberg ID 55.
- `en/` invariato: 4 symlink.
- Core WP e Studio non toccati.

## Limitazioni residue

- PHPUnit file `tests/test-schema.php` esiste ma non è eseguibile in questo ambiente.
- Product WooCommerce non testato (plugin non attivo).
- The Events Calendar reale non è installato; la fixture usa il post type `tribe_events` e le meta TEC.
- Il warning core sull’iframe `global-styles` non è di EasyRankly.
- LocalBusiness incompleto si salva ancora con stato “incomplete” (niente falso `updated=1`).
