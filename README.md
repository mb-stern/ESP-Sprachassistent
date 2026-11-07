# ESP-Sprachassistent

Der ESP-Sprachassistent ist eine Firmware für das **Waveshare ESP32-S3-AUDIO-Board mit 16 MB Flash**. Er erkennt das Weckwort «Hey Symcon» lokal. Nach der Aktivierung verbindet er sich für das Sprachgespräch über das Internet mit der **OpenAI-API**. Dafür braucht er einen eigenen API-Schlüssel. Ein zusätzlicher Sprachserver im Heimnetz ist nicht nötig.

Die Haussteuerung verbindet sich optional über **MCP (Model Context Protocol)** mit der eigenen Symcon-Installation. Für diese Integration verwenden wir den nativen MCP-Server der **Rust-Version von Symcon** unter **Kern-Instanzen → MCP**. Dort wird der Token mit den gewünschten Rechten eingerichtet. Die MCP-Adresse und der Token gehören ins ESP-Webinterface. [Symcon zur MCP-Schnittstelle](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/ki-agenten/mcp-server/)

Der Lautsprecher sucht Musik in Plex, LMS und Radio Browser. Er kann selbst abspielen oder freigegebene Sonos-Geräte, LMS-Radios und Bose SoundTouch ansteuern. Das optionale **Symcon-Modul** bringt diese Suche und die Wiedergabesteuerung als Musik-Kachel in Symcon. Quelle und Ausgabe werden auf dem ESP gespeichert und auch von der Sprachsteuerung verwendet.

## Firmware auf einen neuen Lautsprecher installieren

1. Unter [GitHub Releases](https://github.com/mb-stern/ESP-Sprachassistent/releases) das USB-Vollimage `symcon-speaker-beta-<Version>.bin` für das Waveshare-Board herunterladen.
2. Das Board mit einem USB-Datenkabel verbinden. Im [Espressif Flash Download Tool](https://www.espressif.com/en/support/download/other-tools) **ESP32-S3** und den passenden COM-Port auswählen. Das Vollimage als einzigen Eintrag bei **`0x0`** aktivieren und flashen. Falls der Flashmodus nicht automatisch startet, **BOOT** gedrückt halten, **RESET** kurz drücken und danach **BOOT** loslassen.
3. Den ESP neu starten. Das Setup-WLAN öffnen und das Heim-WLAN einrichten. Nach dem Verbinden zeigt das Setup die neue IPv4-Adresse. Das Webinterface unter dieser Adresse öffnen und dort OpenAI-Schlüssel, bei Bedarf Symcon-MCP und die gewünschten Musikquellen einrichten.

Für laufende Lautsprecher bietet **Firmware-Update** im ESP-Webinterface WLAN-Updates an. Die App-BIN `*-app.bin` und `ota-release.json` bilden das OTA-Paket. Ab Beta 0.40.5 ist die [GitHub-OTA-Adresse](https://mb-stern.github.io/ESP-Sprachassistent/ota/ota-release.json) voreingestellt. Lautsprecher mit dem bisherigen Nextcloud-Feed wechseln nach dem einmaligen Übergangsupdate automatisch zu GitHub, sobald Manifest und App-BIN dort verfügbar sind.

## Symcon-Modul installieren

1. In Symcon unter **Kern-Instanzen → Modules** das Repository `https://github.com/mb-stern/ESP-Sprachassistent.git` hinzufügen. Für Entwicklungsstände in der Modulverwaltung den Zweig `development` wählen, für Vorabtests `beta`, für freigegebene Versionen `main`.
2. Für die automatische Gerätesuche auf `development` **ESP-Sprachassistent Discovery** unter **Discovery-Instanzen** hinzufügen. Mit ESP-Firmware ab 0.40.5 im selben lokalen Netz zeigt sie Name, Raum, IP und Version. Einen Lautsprecher auswählen und seine Instanz anlegen. Alternativ im Objektbaum eine Instanz **ESP-Sprachassistent** anlegen. Pro ESP eine Instanz verwenden.
3. Die lokale Webadresse des Lautsprechers und bei Bedarf sein Web-Passwort eintragen. Einstellungen übernehmen und **Gespeicherte Verbindung prüfen** anklicken.
4. Die Instanz in der Kachelvisualisierung öffnen. Musikquellen und externe Wiedergabeziele zuerst im ESP-Webinterface einrichten und freigeben.

Die Kachelvisualisierung setzt Symcon 7.1 oder neuer und die Musik-Webschnittstelle der Firmware Beta 0.40.4 oder neuer voraus. Symcon muss die lokale ESP-Adresse erreichen. Bei einer HTTP-Adresse wird auch das optionale Web-Passwort per HTTP-Basic-Anmeldung im lokalen Netz verwendet.

## Bedienung

- Eine oder mehrere Suchquellen speichern und die Suche bei Bedarf auf Interpret, Album, Titel oder Radiosender eingrenzen. Interpret und Album öffnen die nächste Ebene. Alben und Interpreten lassen sich auch zufällig abspielen.
- Freigegebene Ausgaben eines Systems auswählen. Die Firmware prüft die Gruppierung und führt die Wiedergabe aus. **Letztes Radio** verwendet Sender und Ausgabe der letzten Radiowiedergabe.
- Die Kachel zeigt die vom ESP gemeldeten laufenden Wiedergaben. **Status aktualisieren** fragt die Ausgabegeräte über den ESP neu ab.

Für Symcon-Skripte stehen `ESPVOICE_PlayRadio($InstanzID)` und `ESPVOICE_ControlPlayback($InstanzID, 'stop')` zur Verfügung. Weitere Steuerbefehle sind `pause`, `resume`, `previous` und `next`.

Die drei Zweige betreffen die Modulverteilung. Firmware-BINs und der OTA-Kanal werden separat bereitgestellt.
