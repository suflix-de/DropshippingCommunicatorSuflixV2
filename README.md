# DropshippingCommunicatorSuflix – PlentyONE Plugin

Dieses Plugin exportiert Auftragsdaten als TXT-Datei (Lieferantenformat) und versendet diese zusammen mit dem Lieferschein (PDF) per E-Mail an bis zu 3 Empfänger sowie optional per BCC.

---

## Dateistruktur

```
DropshippingCommunicatorSuflix/
├── plugin.json
├── config/
│   └── config.php                     ← Konfigurationsfelder (Backend)
├── src/
│   ├── Controllers/
│   │   └── ExportController.php       ← REST-Endpoint (vom Flow aufgerufen)
│   ├── Helpers/
│   │   └── TxtGenerator.php           ← Erzeugt die TXT-Datei
│   ├── Providers/
│   │   ├── DropshippingCommunicatorSuflixServiceProvider.php
│   │   └── DropshippingCommunicatorSuflixRouteServiceProvider.php
│   └── Services/
│       ├── ConfigService.php          ← Liest Plugin-Konfiguration
│       └── ExportService.php          ← Orchestriert TXT, PDF, E-Mail
└── resources/
    └── lang/de/plugin.php
```

---

## Installation

1. Plugin-Ordner `DropshippingCommunicatorSuflix` in das Plugin-Verzeichnis Ihres PlentyONE-Systems hochladen (z. B. via Git oder Plugin-Upload im Backend).
2. Im Backend unter **Plugins → Plugin-Übersicht** das Plugin aktivieren und im gewünschten Plugin-Set bereitstellen.
3. Nach der Bereitstellung unter **Plugins → DropshippingCommunicatorSuflix → Konfiguration** die Einstellungen vornehmen (s. u.).

---

## Konfiguration (Backend)

| Reiter          | Feld                              | Beschreibung |
|-----------------|-----------------------------------|--------------|
| Lieferant       | Händlerkundennummer               | Ihre Kundennummer beim Lieferanten (z. B. `11061`) |
| E-Mail          | Empfänger (An)                    | Kommagetrennt, bis zu 3 Adressen |
| E-Mail          | BCC-Empfänger                     | Kommagetrennt, beliebig viele Adressen |
| E-Mail          | Absender E-Mail                   | Absender-Adresse |
| E-Mail          | Absender Name                     | Anzeigename des Absenders |
| E-Mail          | E-Mail Betreff                    | Platzhalter `{orderId}` und `{deliveryNote}` verfügbar |
| Bundle-Artikel  | Bundle-Varianten-IDs              | Kommagetrennte Varianten-IDs; Elternartikel werden übersprungen, nur Paketinhalte werden exportiert |

---

## TXT-Format

Die erzeugte Datei folgt dem vorgegebenen Lieferantenformat:

```
k;{händlerkundennummer};{auftragsnummer};{firmenname};{anrede};{kundenname};;{straße};{land};{plz};{ort};{telefon};{lieferscheinnummer};{email};
p;{externeId};{menge};{variantenname};
p;{externeId};{menge};{variantenname};   ← beliebig viele Artikelzeilen
```

**Bundle-Logik:** Ist die Varianten-ID eines Artikels in der Bundle-Liste eingetragen, wird der Elternartikel **nicht** als `p`-Zeile ausgegeben. Stattdessen erscheinen ausschließlich die Paketbestandteile (typeId 2 in PlentyONE).

---

## Flow-Einrichtung

1. Im Backend unter **Prozesse / Ereignisaktionen / Flows** einen neuen Flow anlegen.
2. **Ereignis:** z. B. „Auftragsanlage" oder „Zahlungseingang" (je nach Ihrem Prozess).
3. **Aktion:** „REST-Route aufrufen" mit folgenden Einstellungen:
   - **Methode:** `POST`
   - **URL:** `https://{ihre-domain}/dropshipping-communicator-suflix/send/{orderId}`
     *(Platzhalter `{orderId}` wird von PlentyONE automatisch mit der Auftrags-ID befüllt)*
   - **Header:** `Content-Type: application/json`
4. Flow speichern und aktivieren.

> **Tipp:** Das Plugin gibt bei Erfolg `{"success": true, "message": "..."}` zurück. Fehler liefern `{"success": false, "message": "..."}` mit HTTP 500. Beide Antworten erscheinen im Flow-Log.

---

## Logs

Alle Aktionen werden im PlentyONE Log-Center protokolliert:
- **Kanal:** `DropshippingCommunicatorSuflix`
- **Ebenen:** `info` (Erfolg), `warning` (Lieferschein nicht gefunden), `error` (Fehler)

---

## Häufige Fragen

**Lieferschein nicht vorhanden?**  
Der E-Mail-Versand erfolgt trotzdem – nur ohne PDF-Anhang. Im Log erscheint eine `warning`.

**Mehr als 3 Empfänger?**  
Im Feld „Empfänger (An)" können Sie beliebig viele kommagetrennte Adressen eintragen. Die Beschränkung auf 3 ist nur eine Empfehlung, technisch gibt es kein Limit.

**Bundle-Artikel werden doppelt exportiert?**  
Stellen Sie sicher, dass die **Varianten-ID des Elternartikels** (nicht die Artikel-ID) im Feld „Bundle-Varianten-IDs" eingetragen ist.
