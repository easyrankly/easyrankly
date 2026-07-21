# EasyRankly — sistema di stile delle impostazioni

Questo documento definisce il contratto visivo e di interazione delle schermate di impostazione EasyRankly. La schermata canonica è **Impostazioni → EasyRankly → General**.

Baseline verificata il 21 luglio 2026 su EasyRankly 2.0.0, WordPress 7.0.2, viewport desktop 1280 × 720 px e viewport responsive 760 × 900 px.

## 1. Ambito e fonti di verità

Il sistema è diviso in tre livelli principali, caricati in quest'ordine:

1. [`assets/css/shared.css`](../assets/css/shared.css): token globali e componenti condivisi fra impostazioni, editor, tassonomie e wizard.
2. [`assets/css/admin-core.css`](../assets/css/admin-core.css): shell amministrativa, navigazione, card e primitive comuni alle superfici admin classiche.
3. [`assets/css/admin-settings.css`](../assets/css/admin-settings.css): componenti di form, tab, schema, media e stati condivisi da impostazioni, editor classico e tassonomie.

[`assets/css/classic-editor.css`](../assets/css/classic-editor.css) contiene soltanto
gli override realmente esclusivi dell'editor classico e delle tassonomie. I CSS
di modulo (`migration.css`, `health.css`, `redirects.css`, `multilingual.css`,
`reset.css`) si caricano dopo i componenti condivisi e non devono duplicarli.

Il markup canonico è in:

- [`admin/settings-page.php`](../admin/settings-page.php): pagina, navigazione laterale e routing dei pannelli;
- [`admin/settings/panels.php`](../admin/settings/panels.php): sezioni del pannello General;
- [`admin/settings/renderers.php`](../admin/settings/renderers.php): dettagli espandibili, tab e valori predefiniti;
- [`admin/field-renderers.php`](../admin/field-renderers.php): menu delle variabili e campi condivisi.

Le regole di questo documento sono normative. Il CSS resta l'implementazione
eseguibile: ogni modifica strutturale a token, primitive o breakpoint deve
aggiornare anche questo file.

### Confini

- Le regole descrivono tutti i pattern visibili o attivabili dalla schermata
  General, inclusi stati vuoti, disabilitati, aperti, attivi, focus, errore e
  responsive.
- I pannelli specializzati possono comporre queste primitive, ma non devono
  introdurre palette, raggi, spaziature o gerarchie tipografiche alternative
  senza una decisione di design esplicita.
- I componenti nativi WordPress sono parte del sistema: non devono essere ridisegnati quando il loro stile soddisfa già il riferimento.

## 2. Principi visivi

1. **WordPress-native prima di tutto.** Font, controlli di base, bottoni e focus dei campi restano quelli del core quando possibile.
2. **Superfici piatte.** Card e controlli usano bordi, non ombre. L'ombra è riservata a popover e menu sovrapposti.
3. **Raggio unico e compatto.** Superfici, controlli, tab e popover usano `2px`.
4. **Gerarchia per spazio e peso.** I livelli si distinguono con margini, maiuscole e peso tipografico; non con decorazioni superflue.
5. **Un solo contenitore di sezione.** Ogni gruppo principale, in qualunque pannello, usa direttamente `.erankly-settings-section` + `.erankly-card`. Le sottosezioni interne usano divisori, non card annidate.
6. **Accento dinamico.** Selezione e focus usano `--wp-admin-theme-color`, con fallback `#3858e9`.
7. **Stati semantici coerenti.** Successo è verde, avviso è ambra, errore è rosso, informazione usa il colore tema.
8. **Comportamento accessibile.** Etichette, fieldset, tab, regioni live e stato non devono dipendere dal solo colore.

## 3. Token

I token devono essere riutilizzati direttamente. Non duplicare i relativi valori esadecimali nei nuovi componenti.

### 3.1 Raggi

| Token | Valore | Uso |
| --- | ---: | --- |
| `--erankly-radius-surface` | `2px` | card, pannelli, contenitori |
| `--erankly-radius-control` | `2px` | input, select, textarea, bottoni, voci di navigazione |
| `--erankly-radius-compact` | `2px` | elementi compatti |
| `--erankly-radius-popover` | `2px` | menu e popover |

### 3.2 Colori neutrali

| Token | Valore | Uso |
| --- | --- | --- |
| `--erankly-color-text` | `#1d2327` | testo principale controllato dal plugin |
| `--erankly-color-text-muted` | `#787c82` | testo secondario attenuato |
| `--erankly-color-muted` | `#646970` | titoli di sezione, metadati, descrizioni |
| `--erankly-color-surface` | `#fff` | card, controlli, popover |
| `--erankly-color-surface-hover` | `#f0f0f1` | hover di navigazione e opzioni |
| `--erankly-color-surface-subtle` | `#f6f7f7` | hover leggero e superfici secondarie |
| `--erankly-color-border` | `#dcdcde` | bordi standard e divisori |
| `--erankly-color-border-strong` | `#949494` | controlli e contenitori interattivi evidenti |
| `--erankly-color-control-border` | `#c3c4c7` | bordo di controllo secondario |

Il fondo della pagina e il testo base sono ereditati da WordPress. Nel riferimento verificato risultano rispettivamente `#f0f0f0` e `#3c434a`.

### 3.3 Accento e stati

| Token | Valore | Uso |
| --- | --- | --- |
| `--erankly-color-info` | `var(--wp-admin-theme-color, #3858e9)` | selezione, focus, informazioni |
| `--erankly-color-info-surface` | accento all'`8%` su bianco | fondo informativo/attivo |
| `--erankly-color-info-border` | accento al `28%` miscelato col bordo | bordo informativo |
| `--erankly-color-success` | `#1a7f37` | successo e toggle attivo |
| `--erankly-color-success-surface` | `#eef8f1` | fondo successo |
| `--erankly-color-success-border` | `#b8d7c2` | bordo successo |
| `--erankly-color-warning` | `#9a6700` | avvisi e contatori prossimi al limite |
| `--erankly-color-warning-surface` | `#f8f3e8` | fondo avviso |
| `--erankly-color-warning-border` | `#d0bc98` | bordo avviso |
| `--erankly-color-error` | `#b32d2e` | errori e testo di errore |
| `--erankly-color-error-surface` | `#fcf0f1` | fondo errore |
| `--erankly-color-error-border` | `#e7b7bd` | bordo errore |
| `--erankly-color-danger` | `#d63638` | azioni distruttive |

Colori disabilitati ammessi: `#a7aaad` per controlli generici e `#949494` per tab unificati.

### 3.4 Ombre e font speciali

| Token | Valore | Uso |
| --- | --- | --- |
| `--erankly-shadow-popover` | `0 4px 12px rgba(0, 0, 0, 0.12)` | esclusivamente superfici flottanti |
| `--erankly-font-mono` | `Consolas, Monaco, monospace` | token di variabile e codice |

Card, input, tab, navigazione e avvisi non ricevono ombre decorative.

## 4. Scala di spaziatura

Usare prima i valori già presenti nel sistema:

| Valore | Impiego ricorrente |
| ---: | --- |
| `2px` | distanza fra voci della navigazione, piccoli stati |
| `3px` | gap e padding interno dei controlli segmentati |
| `4px` | offset dei menu, nav mobile |
| `6px` | gap verticale nelle opzioni checkbox, margine del toggle |
| `8px` | distanza titolo-controllo, gap compatti, padding risultati, distanza fra controlli |
| `10px` | padding di righe e messaggi compatti |
| `12px` | inset dei controlli, navigazione, summary e piccoli gruppi |
| `14px` | padding interno dei pannelli annidati |
| `16px` | ritmo dei campi, griglie, pannelli e contenuti espansi |
| `20px` | padding verticale degli avvisi |
| `24px` | padding card e distanza fra sezioni principali |
| `32px` | separazione fra macro-sottosezioni |

Non introdurre valori intermedi se uno di questi produce lo stesso risultato visivo.

## 5. Tipografia

La famiglia base è quella di WordPress:

```css
-apple-system, system-ui, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu,
Cantarell, "Helvetica Neue", sans-serif
```

| Elemento | Dimensione / interlinea | Peso | Altre regole |
| --- | --- | ---: | --- |
| Testo base admin | `13px / 1.4` | `400` | ereditato da WordPress |
| Titolo pagina `h1` | `23px / 1.3` | `400` | colore `--erankly-color-text` |
| Voce nav laterale | `14px / 1.4` | `400` | attiva `600` |
| Titolo sezione / gruppo nav | `12px / 1.4` | `600` | maiuscolo, tracking `0.04em`, colore muted |
| Etichetta campo | `13px / 1.4` | `600` tramite CSS | margine inferiore `8px` |
| Input, select, textarea | `14px` | `400` | stile base WordPress |
| Tab segmentato | `13px / 1.2` | `500` | opzione binaria attiva `600` |
| Descrizione | `13px / 1.5` | `400` | colore muted WordPress |
| Stato inline | `12px / 1.4` | `400` | colore semantico |
| Token di variabile | `12px` | `400` | font monospazio |
| Metadato autocomplete | `12px / 1.35` | `400` | colore muted |

Regole:

- I titoli delle sezioni principali sono `h3.erankly-section-title`, esterni alla card.
- Le sottosezioni interne usano `h4` con la stessa resa maiuscola da `12px`.
- Le etichette devono essere frasi brevi in sentence case; non usare tutto maiuscolo manualmente.
- Non creare nuove scale tipografiche per singoli pannelli.

## 6. Struttura della pagina

La gerarchia canonica è:

```text
.wrap.erankly-settings
└── .erankly-settings-layout
    ├── .erankly-settings-sidebar-nav
    │   ├── h1
    │   └── nav.erankly-settings-nav-tablist
    └── .erankly-settings-content
        └── form (max 920px)
            └── .erankly-tab-panel
                └── .erankly-settings-section (max 720px)
                    ├── h3.erankly-section-title
                    └── .erankly-card (max 720px)
```

### 6.1 Shell desktop

- Il layout principale è `display: flex`, allineato in alto, con `gap: 24px` e `margin-top: 16px`.
- La barra laterale è larga `220px`, non si restringe e resta sticky a `top: 42px`.
- Il titolo pagina `h1` vive dentro `.erankly-settings-sidebar-nav`, così resta allineato alla colonna di navigazione senza offset artificiali.
- Il contenuto usa lo spazio residuo con `min-width: 0`.
- Il form ha `max-width: 920px`.
- Sezioni e card hanno `max-width: 720px`.

### 6.2 Sezioni e card

`.erankly-settings-section`:

- `max-width: 720px`;
- `margin-bottom: 24px`;
- titolo con `margin-bottom: 8px`.

`.erankly-card`:

- fondo bianco;
- bordo `1px solid --erankly-color-border`;
- raggio `2px`;
- nessuna ombra;
- `padding: 24px`;
- `box-sizing: border-box`;
- nessun margine inferiore quando è figlia diretta di una sezione.

Il primo figlio della card non deve aggiungere margine superiore; l'ultimo non deve aggiungere margine inferiore.

Per una sottosezione interna usare un divisore:

```css
border-top: 1px solid var(--erankly-color-border);
margin-top: 24px;
padding-top: 24px;
```

Per separare un nuovo contesto di pari importanza usare `32px` al posto di `24px`.

### 6.3 Markup minimo di una sezione

```html
<div class="erankly-settings-section">
  <h3 class="erankly-section-title">Section title</h3>
  <div class="erankly-card">
    <!-- campi o componenti -->
  </div>
</div>
```

Non mettere il titolo principale della sezione dentro la card.
Non aggiungere wrapper puramente presentazionali attorno alle sezioni: il pannello
contiene direttamente le sue `.erankly-settings-section`. Le classi specializzate
si aggiungono soltanto quando hanno stile o comportamento propri.

## 7. Navigazione laterale

### 7.1 Contenitore

- Lista verticale con `gap: 2px`.
- Gruppi secondari con `margin-top: 10px`.
- Heading di gruppo: `12px`, peso `600`, tracking `0.04em`, maiuscolo, `padding: 4px 12px`.

### 7.2 Voce

Ogni voce usa `.erankly-settings-nav-item`:

- larghezza `100%`;
- font `14px / 1.4`;
- padding `8px 10px 8px 9px`;
- bordo sinistro trasparente `3px`, riservato nella geometria;
- raggio `2px`;
- testo `--erankly-color-text`;
- nessuna sottolineatura;
- testo lungo spezzabile con `overflow-wrap: anywhere`.

Stati:

- **hover:** fondo `--erankly-color-surface-hover`, testo invariato;
- **attivo:** fondo accentato all'`8%`, testo colore tema, peso `600`;
- **focus-visible:** anello `0 0 0 1px` nel colore tema e outline trasparente di compensazione;
- **disabilitato:** testo `#a7aaad`, cursore default, nessun fondo hover.

Le voci sono link server-routed reali. JavaScript migliora l'esperienza, ma non sostituisce URL e stato `aria-current="page"`.

### 7.3 Stato autosave

Lo stato vive sotto la navigazione e usa `.erankly-autosave-status`:

- font `13px / 1.4`, peso `500`;
- stato iniziale collassato (`max-height: 0`, opacity `0`, traslazione `-4px`);
- stato visibile con padding `8px 10px`, margine superiore `6px`, fondo semantico e transizione `150ms`;
- successo verde, avviso ambra, errore rosso.

## 8. Campi e controlli

### 8.1 Campo standard

```html
<div class="erankly-field">
  <label for="field-id">Field label</label>
  <input id="field-id" class="widefat" type="text">
  <p class="description">Optional supporting text.</p>
</div>
```

Regole:

- `.erankly-field` ha `margin-bottom: 16px` e `min-width: 0`.
- `label`, `legend` o `.erankly-field-label` diretti sono block con `margin-bottom: 8px`.
- L'ultimo campo di un gruppo non lascia spazio superfluo in fondo.
- Usare sempre `id` + `for`; per controlli compositi non etichettabili direttamente usare un elemento con ID e `aria-labelledby`.
- La descrizione segue il controllo e non sostituisce l'etichetta.
- Non inserire `strong` dentro `label` o `legend`: il peso tipografico appartiene al CSS.

Per un singolo checkbox la struttura resta un campo normale, senza `fieldset`:

```html
<div class="erankly-field erankly-checkboxes">
  <label><input class="erankly-toggle" type="checkbox"> Option label</label>
  <p class="description">Optional supporting text.</p>
</div>
```

### 8.2 Input, select e textarea

Il plugin imposta il raggio a `2px` e conserva bordo, focus e metrica nativi WordPress.

Baseline osservata nella schermata General:

| Controllo | Altezza/padding osservati | Regola |
| --- | --- | --- |
| Input `.widefat` | `40px`, `0 12px` | larghezza `100%` |
| Select `.widefat` | `40px`, `0 24px 0 12px` | freccia e focus nativi |
| Textarea `rows="3"` | circa `78px`, `8px 12px` | ridimensionabile solo verticalmente |

Queste altezze derivano dal core WordPress/Gutenberg del baseline. Non duplicarle
nel CSS EasyRankly se il controllo nativo le fornisce già; fuori da wp-admin
possono essere usate come target visivo.

Regole aggiuntive:

- `.widefat` occupa la larghezza del contenitore.
- Un select isolato ha `max-width: 360px`; dentro griglie e campi identità occupa `100%`.
- Gli input numerici non mostrano gli spinner nativi.
- I textarea usano `resize: vertical`.
- I campi readonly mantengono fondo bianco.
- I bottoni usano le classi WordPress `.button`, `.button-primary` o `.button-link-delete`; EasyRankly ne uniforma solo il raggio a `2px`.
- Non sostituire il focus nativo di input, select, textarea e bottoni con un'ombra decorativa.

### 8.3 Griglie di campi

`.erankly-inline-fields` e `.erankly-schema-identity-fields`:

- sono grid;
- usano `gap: 16px` in entrambe le direzioni;
- hanno `margin-bottom: 16px`;
- i figli non aggiungono un secondo margine inferiore;
- la variante `.erankly-inline-fields-two-columns` usa due colonne uguali `minmax(0, 1fr)`;
- l'identità usa due colonne solo nello stato `.is-person`.

## 9. Anteprima delle variabili

Il campo con token dinamici usa `.erankly-variable-field` come wrapper relativo.

Regole non negoziabili:

- Il controllo reale mantiene bordo, fondo e focus nativi.
- L'anteprima non disegna un proprio fondo o bordo.
- `.erankly-variable-preview` è assoluta con `inset: 0`, `z-index: 1` e `pointer-events: none`.
- L'inset orizzontale è `12px`, uguale a input e select.
- L'anteprima di un textarea parte a `8px` dall'alto e consente il wrapping con `white-space: pre-wrap`.
- In modalità preview il testo del controllo reale diventa trasparente; l'overlay mostra il valore risolto.
- Al focus si torna al valore grezzo editabile senza alterare geometria o bordo.
- Testi lunghi a riga singola usano ellissi.

Non reintrodurre overlay opachi: possono coprire bordo e focus del controllo reale.

### 9.1 Menu delle variabili

- Posizione assoluta sotto il campo, offset superiore `4px`, larghezza del campo, `z-index: 10000`.
- Fondo bianco, bordo standard, raggio `2px`, ombra popover.
- Altezza massima `260px`, scroll verticale.
- Riga opzione: flex, gap `8px`, padding `6px 10px`, nessun bordo.
- Hover/attiva: fondo `--erankly-color-surface-hover`.
- Token primario: `12px`, monospazio, colore testo.
- Descrizione secondaria: `12px`, muted, ellissi.

## 10. Pannelli espandibili

Il pattern canonico usa `<details class="erankly-settings-details">`.

Contenitore:

- fondo bianco;
- bordo forte `1px solid --erankly-color-border-strong`;
- raggio `2px`;
- overflow nascosto;
- margine inferiore `16px`, rimosso sull'ultimo elemento.

Summary:

- altezza minima `40px`;
- font `13px`, peso `600`, line-height calcolata a `38px`;
- layout flex centrato, gap `8px`;
- padding `0 12px`;
- cursore pointer;
- nessun marker nativo visibile.

Chevron:

- quadrato `7 × 7px`;
- bordo destro e inferiore da `2px`;
- chiuso a `45deg`, aperto a `-135deg`;
- transizione `150ms`.

Quando aperto, il summary riceve il bordo inferiore forte. Il contenuto usa `padding: 16px 14px 1px`.

## 11. Tab e controlli segmentati

### 11.1 Contenitore `.erankly-tabs`

- fondo bianco;
- bordo `1px` nel colore del tab;
- raggio `2px`;
- padding interno `3px`;
- gap `3px`;
- larghezza massima `100%`;
- colore predefinito `--wp-admin-theme-color`.

### 11.2 Elemento `.erankly-tab`

- altezza minima `32px`;
- padding `7px 14px`;
- font `13px / 1.2`, peso `500`;
- fondo e bordo trasparenti;
- raggio `2px`;
- testo centrato, spezzabile;
- transizione di fondo, colore e shadow `150ms`.

Stati:

- **default:** tinta ottenuta miscelando accento al `38%` con muted;
- **hover/focus:** fondo accento all'`8%`, testo accento pieno;
- **attivo:** fondo accento, testo bianco;
- **focus-visible:** doppio ring, `2px` bianco + `4px` accento;
- **disabilitato:** `#a7aaad`, opacity `0.8`, nessuna interazione.

### 11.3 Indicatore scorrevole

- Ha il colore accento, raggio `2px`, `z-index: 0`.
- Gli item stanno a `z-index: 1` e diventano trasparenti nello stato attivo.
- Movimento e ridimensionamento durano `260ms` con `cubic-bezier(0.22, 1, 0.36, 1)`.
- Con `prefers-reduced-motion: reduce` la transizione è disattivata.

### 11.4 “Same for all”

- La barra contiene tab a sinistra e label + controllo binario a destra.
- Label: peso `500`, allineata in fondo, `margin-left: auto`.
- Opzioni No/Yes: peso `600`, larghezza minima `42px`, padding orizzontale `12px`.
- L'opzione selezionata usa accento + testo bianco.
- Quando i valori sono unificati, i tab individuali non sono interattivi e la sintesi “Unified” sostituisce la lista.
- I tab delle entità possono scorrere orizzontalmente senza scrollbar visibile; non devono andare a capo.

Semantica richiesta: `role="tablist"`, `role="tab"`, `aria-selected`,
`aria-controls`, `role="tabpanel"`. Il controllo binario usa `aria-pressed` e una
regione live per il testo di stato.

## 12. Toggle e gruppi di opzioni

### 12.1 Toggle `.erankly-toggle`

| Parte | Regola |
| --- | --- |
| Traccia | `36 × 20px`, raggio `2px`, fondo off `#dcdcde` |
| Manopola | `16 × 16px`, inset `2px`, bianca, ombra `0 1px 2px rgba(0,0,0,.3)` |
| Stato on | fondo `--erankly-color-success`, manopola a `left: 18px` |
| Transizione | fondo e posizione `150ms` |
| Feedback on | pulse verde di `400ms` fino a `6px` |
| Disabilitato | opacity `0.5`, cursore `not-allowed` |
| Distanza label | margine destro `6px` |

Il toggle comunica lo stato anche tramite posizione e testo, non solo colore.

Il toggle usa un focus-visible esplicito, coerente col doppio ring dei tab:
`2px` bianco + `4px` nel colore tema, senza modificare la geometria.

### 12.2 Gruppi

- Usare `fieldset` + `legend` soltanto quando due o più controlli costituiscono un vero gruppo logico.
- Non usare `fieldset` come contenitore generico di un singolo controllo, di una sezione o di una card informativa.
- Il fieldset `.erankly-checkboxes` non ha bordo né padding.
- I `legend` ereditano la distanza uniforme di `8px` prevista per tutti i titoli di campo.
- `.erankly-checkbox-options` è flex con wrap e `gap: 6px 20px`.
- Le label sono inline-flex, centrate verticalmente, con line-height `1.4`.

## 13. Autocomplete utente

Il campo “Person reference user” usa una griglia `minmax(0, 1fr) auto` con gap `8px`: valore/ricerca a sinistra e bottone Remove a destra.

Menu risultati:

- assoluto sotto il campo, offset `4px`, `z-index: 1000`;
- fondo bianco, bordo forte, raggio `2px`;
- altezza massima `280px`, scroll verticale e overscroll contenuto;
- separatore fra righe con il colore surface-hover;
- opzione larga `100%`, padding `8px`, font `13px / 1.5`;
- hover/focus con fondo subtle;
- focus-visible con ring interno da `1px` nel colore tema;
- risultato ricco alto almeno `40px`, avatar `24 × 24px`, gap `8px`;
- nome peso `500`, metadato `12px`, entrambi con ellissi.

Gli stati ricerca/vuoto usano font `13px`, colore `#757575` e padding `8px`.

## 14. Avvisi e stati

Gli avvisi custom e le notice WordPress dentro `.erankly-settings` condividono la stessa resa:

- flex allineato in alto;
- larghezza `100%`;
- fondo semantico morbido;
- bordo completo semantico da `1px`;
- raggio `2px`;
- nessuna ombra;
- gap `14px`;
- padding `20px 24px`;
- margine inferiore `16px`;
- testo `#3c434a`, line-height `1.45`;
- icona semantica `24 × 24px`;
- testo spezzabile con `overflow-wrap: anywhere`.

Varianti obbligatorie: info, success, warning, error. Le notice dismissible riservano `44px` a destra e centrano verticalmente il pulsante di chiusura.

Gli stati inline usano font `12px`, gap `3px`, icona `14 × 14px` e colore semantico. Non usare il solo colore: aggiungere icona e testo.

## 15. Responsive

Il breakpoint principale è `max-width: 782px`, coerente con wp-admin.

Sotto o a `782px`:

- `.erankly-settings-layout` diventa colonna con `gap: 12px`;
- la sidebar diventa statica e larga `100%`;
- compare `.erankly-settings-sidebar-toggle`, alto secondo il contenuto, con padding `10px 12px`;
- la lista di navigazione è nascosta finché la sidebar non ha `.is-expanded`;
- la lista aperta ha bordo standard, raggio `2px`, `margin-top: 4px`, padding `8px`;
- il contenuto è largo `100%`;
- le griglie a due colonne e l'identità Person diventano una colonna;
- le righe degli orari di apertura diventano una colonna e si allineano in alto.

Il chevron del selettore mobile usa le stesse dimensioni e rotazioni dei pannelli espandibili.

I tab delle entità restano su una riga e scorrono orizzontalmente. Evitare la compressione dei label o un secondo breakpoint arbitrario.

Con JavaScript disattivato:

- il selettore mobile non viene mostrato;
- la navigazione completa resta visibile;
- i pannelli interni nascosti vengono esposti, mentre la navigazione principale continua a usare URL reali.

## 16. Movimento

Durate ammesse:

- `150ms`: hover/focus, espansione chevron, autosave, toggle;
- `260ms`: indicatore scorrevole dei tab;
- `400ms`: singolo pulse di conferma del toggle.

Le animazioni dei tab e dei toggle sono disattivate con
`prefers-reduced-motion: reduce`. Non usare movimento continuo o puramente
decorativo.

## 17. Accessibilità e semantica

Regole obbligatorie:

- Ogni controllo ha un nome accessibile; preferire `label for`.
- I veri gruppi di due o più opzioni usano `fieldset` e `legend`; i controlli singoli usano `div.erankly-field` e `label`.
- Tab e pannelli sono collegati con ID, `aria-controls` e `aria-labelledby`.
- La navigazione attiva espone `aria-current="page"`.
- Stati asincroni usano `aria-live="polite"`.
- Il contenuto solo visivo usa `aria-hidden="true"`; il testo solo screen reader usa `.screen-reader-text`.
- `hidden` è lo stato autorevole per contenuti non disponibili; non simulare la sparizione con opacity.
- Il focus deve essere visibile e non deve cambiare la geometria del componente.
- Campi di form e summary devono raggiungere almeno `40px`; i tab compatti non
  scendono sotto `32px`.
- L'ordine DOM deve restare logico anche quando il layout passa da due colonne a una.
- Successo, avviso ed errore devono avere testo o icona oltre al colore.

## 18. Convenzioni CSS e JavaScript

- Prefisso obbligatorio: `.erankly-`.
- Le classi descrivono il componente; i modificatori di stato usano `.is-active`, `.is-visible`, `.is-linked`, `.is-warning`, `.is-error`, `.is-success`.
- Gli attributi `data-erankly-*` sono hook di comportamento, non sostituti delle classi di stile.
- Limitare i selettori alla superficie, per esempio `.erankly-settings`, quando una regola non è realmente cross-surface.
- Evitare stili inline nei template PHP.
- Evitare `!important`; è ammesso soltanto per sovrascrivere utility WordPress o stati `hidden`/no-JS documentati.
- Non rimuovere selettori condivisi solo perché non appaiono nel plugin free: alcuni sono contratti consumati da EasyRankly Pro.
- Preferire un'unica regola condivisa a override specifici per pannello.
- Quando lo stile nativo WordPress è corretto, rimuovere l'override locale invece di ricostruire bordo, colore o focus.

## 19. Cosa fare e cosa evitare

### Fare

- Riutilizzare token, `.erankly-card`, `.erankly-field`, `.erankly-tabs` e `.erankly-settings-details`.
- Allineare testo di input, select e preview a `12px` dai bordi.
- Usare griglie `minmax(0, 1fr)` per prevenire overflow.
- Lasciare che WordPress gestisca font, bottoni e focus dei controlli nativi.
- Verificare desktop, breakpoint `782px`, focus tastiera, contenuti lunghi e stato no-JS.

### Evitare

- Nuove tonalità, raggi o ombre ad hoc.
- Card annidate per semplici sottosezioni.
- Titoli principali dentro la card.
- Select a larghezza arbitraria quando esistono le varianti `360px` o full width.
- Overlay con fondo o bordo sopra un input reale.
- Icone senza testo accessibile.
- Stato comunicato solo dal colore.
- Altezza fissa per contenuti testuali che possono tradursi o andare a capo.
- Override globali non circoscritti a una superficie EasyRankly.

## 20. Checklist di accettazione

Prima di considerare uniforme una nuova schermata o un nuovo componente:

- [ ] Usa esclusivamente token esistenti per palette, raggi e shadow.
- [ ] La gerarchia è sezione → titolo esterno → card → campi/componenti.
- [ ] Card e sezioni rispettano `720px`; il form non supera `920px`.
- [ ] Il ritmo usa `4/8/12/16/24/32px` salvo componente documentato.
- [ ] Label, descrizione, errori e stato hanno semantica accessibile.
- [ ] Input, select, textarea e bottoni conservano lo stile nativo WordPress.
- [ ] Preview e controlli reali hanno inset coerente di `12px`.
- [ ] Hover, active, disabled e focus-visible sono definiti.
- [ ] Il layout funziona a `1280px` e sotto/a `782px`.
- [ ] Testo lungo e traduzioni non provocano overflow.
- [ ] `prefers-reduced-motion` è rispettato per qualsiasi nuova animazione.
- [ ] La pagina resta utilizzabile senza JavaScript.
- [ ] Nessuna regola altera superfici EasyRankly non coinvolte.
- [ ] `npm run lint:css` e `git diff --check` passano dopo modifiche CSS.
