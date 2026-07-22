# EasyRankly Multilingual

Stato: M1 e M2 completate nel core EasyRankly; M3 e il plugin add-on non sono iniziati. Questa cartella resta la specifica normativa e non contiene codice applicativo dell'add-on.

Baseline analizzata: `origin/beta` di EasyRankly, versione `2.0.0`, commit `eccebfb`. La release ponte core descritta in M2 è implementata in EasyRankly `2.1.0`; le fasi add-on restano future.

## Nome del prodotto

**EasyRankly Multilingual** è grammaticalmente corretto in inglese e coerente con un nome di prodotto. Gli identificatori tecnici stabiliti sono:

| Elemento | Valore |
|---|---|
| Nome plugin | EasyRankly Multilingual |
| Slug / cartella | `easyrankly-multilingual` |
| Text domain | `easyrankly-multilingual` |
| Namespace PHP | `EasyRankly\Multilingual` |
| Prefisso PHP non namespaced | `erml_` / `ERML_` |
| Namespace REST | `easyrankly-multilingual/v1` |

## Decisione di prodotto

Il multilingua diventa un add-on distinto. Il core EasyRankly conserva esclusivamente API SEO neutrali e il meccanismo di registrazione dei provider; l'add-on possiede dati, interfaccia, risoluzione delle traduzioni, routing, output hreflang, asset e ciclo di vita.

L'add-on seleziona un backend in base alla topologia WordPress:

- **Multisite:** mantiene il modello attuale, nel quale ogni sito rappresenta una lingua, e adotta senza copia la tabella e le opzioni legacy.
- **Single Site:** usa documenti WordPress distinti e collegati per lingua, sul modello concettuale di Polylang. Non traduce l'HTML a runtime e non usa output buffering come motore generale.

Il kernel, i modelli di dominio e le API utente sono comuni; persistenza, assegnazione linguistica e routing sono backend specifici.

## Decisioni vincolanti

1. L'MVP Single Site è **document-based**: ogni traduzione è un post, termine o entità WordPress distinta.
2. Il frontend visuale e la traduzione universale delle stringhe in stile TranslatePress non fanno parte della versione 1.x.
3. È ammesso un registro limitato di stringhe globali note, tipizzate e filtrabili; non è ammessa la cattura indiscriminata dell'output.
4. Il backend Multisite usa i dati legacy in-place. Una migrazione che duplica tutte le relazioni è vietata salvo futura decisione esplicita.
5. Un solo provider può possedere routing, relazioni e output hreflang in una richiesta.
6. Polylang, WPML, TranslatePress o un altro router multilingua attivo bloccano la modalità nativa. I bridge esterni sono un'estensione futura, non una modalità implicita.
7. Il rilevamento della lingua dal browser e i redirect automatici sono disattivati per impostazione predefinita.
8. Disattivazione e aggiornamento non cancellano dati. La cancellazione è consentita soltanto dall'uninstall dell'add-on, dopo scelta esplicita dell'amministratore.
9. L'estrazione definitiva dal core avviene solo dopo almeno un ciclo di compatibilità verificato.

## Documenti

- [ARCHITECTURE.md](./ARCHITECTURE.md): confini dei pacchetti, componenti, contratti pubblici, bootstrap e modello dati.
- [SPECIFICATION.md](./SPECIFICATION.md): requisiti normativi di comportamento, UX, SEO, sicurezza, migrazione e qualità.
- [MILESTONES.md](./MILESTONES.md): sequenza di implementazione, deliverable, dipendenze e gate di accettazione.

## Treno di rilascio raccomandato

| Release | Ruolo |
|---|---|
| EasyRankly 2.0 | Baseline funzionale; modulo Multisite incorporato invariato. |
| EasyRankly 2.1 | Introduce API pubblica, arbitraggio del provider e ownership del ciclo di vita; conserva il modulo incorporato come fallback. |
| Multilingual 1.0 | Estrae e rende stabile il backend Multisite con adozione zero-copy. |
| Multilingual 1.1 beta | Introduce il backend Single Site e il relativo routing. |
| Multilingual 1.1 stable | Completa UX, SEO, export/import e certificazione Single Site. |
| EasyRankly 3.0 | Rimuove dal core classi, asset, impostazioni e storage multilingua legacy; mantiene solo i contratti neutrali. |

## Matrice di compatibilità prevista

| Core | Add-on assente | Add-on presente |
|---|---|---|
| EasyRankly 1.x / 2.0 | Il modulo incorporato continua a funzionare. | Add-on non avviato; avviso di versione incompatibile, nessun doppio output. |
| EasyRankly 2.1–2.x | Il modulo incorporato è il fallback solo se lo storage non è `claimed`/`retained`; altrimenti safe stop e avviso. | L'add-on è il provider esclusivo; il fallback non viene caricato. |
| EasyRankly 3.x | Multilingua non disponibile, nessun fatal e dati lasciati intatti. | L'add-on è il provider esclusivo. |

Versione minima dell'add-on 1.0: WordPress `6.2`, PHP `8.0`, EasyRankly `2.1`. L'header `Requires Plugins: easyrankly` va aggiunto per WordPress che lo supporta, ma non sostituisce il controllo runtime della versione dell'API.

| Versione add-on | Multisite | Single Site |
|---|---|---|
| 1.0.x | `network-legacy` supportato | `unsupported-topology`: nessuna tabella, rewrite o query filter; solo avviso |
| 1.1+ | `network-legacy` supportato | `single-linked` supportato |

## Definizione di completamento del progetto

Il progetto è completo quando:

- il pacchetto EasyRankly non contiene più implementazione, asset, tabelle, opzioni o schermate multilingua;
- il backend Multisite dell'add-on mantiene parità funzionale e adotta i dati esistenti senza perdita;
- il backend Single Site soddisfa tutti i requisiti MUST della specifica;
- canonical, hreflang, sitemap/robots, schema e switcher derivano dallo stesso contesto linguistico;
- upgrade, rollback, disattivazione e uninstall hanno test automatici;
- la matrice di compatibilità e qualità in `MILESTONES.md` è verde;
- i pacchetti ZIP dei due plugin sono indipendenti e superano i rispettivi controlli di distribuzione.

## Lessico normativo

In questi documenti **MUST / DEVE**, **SHOULD / DOVREBBE** e **MAY / PUÒ** indicano rispettivamente requisito obbligatorio, raccomandazione derogabile con motivazione e comportamento opzionale.
